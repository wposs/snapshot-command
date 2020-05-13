<?php

namespace WP_CLI\Snapshot;

use WP_CLI;
use WP_CLI_Command;
use WP_CLI\Utils;
use WP_CLI\Formatter;
use ZipArchive;

/**
 * Backup / Restore WordPress installation
 *
 * @package wp-cli
 */
class SnapshotCommand extends WP_CLI_Command {

	/**
	 * Common config array.
	 *
	 * @var array
	 */
	private $config = [];

	/**
	 * Report status of the backup / restore.
	 * @var object
	 */
	private $progress;

	/**
	 * Current snapshots directory.
	 *
	 * @var string
	 */
	protected $current_snapshots_dir = '';

	/**
	 * Absolute path to the snapshot.
	 * @var string
	 */
	protected $current_snapshots_full_path = '';

	/**
	 * Path to config directory.
	 *
	 * @var string
	 */
	protected $config_dir = '';

	/**
	 * The DB instance.
	 *
	 * @var string|SnapshotDB
	 */
	protected $db = '';

	/**
	 * Type of the backup currently in process.
	 *
	 * @var string
	 */
	protected $backup_type = '';

	/**
	 * WordPress installation type.
	 *
	 * @var string
	 */
	protected $installation_type = '';


	/**
	 * Initialize required files and DB.
	 *
	 * @throws WP_CLI\ExitException
	 * @throws \Exception
	 */
	public function __construct() {
		define( 'WP_CLI_SNAPSHOT_DIR', Utils\get_home_dir() . '/.wp-cli/snapshots' );

		if ( ! is_dir( WP_CLI_SNAPSHOT_DIR ) ) {
			mkdir( WP_CLI_SNAPSHOT_DIR );
		}

		if ( ! is_readable( WP_CLI_SNAPSHOT_DIR ) ) {
			WP_CLI::error( WP_CLI_SNAPSHOT_DIR . ' is not readable.' );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new \Exception( 'Snapshot command requires ZipArchive.' );
		}

		$this->db       = new SnapshotDB();
		$this->progress = Utils\make_progress_bar( 'Creating Backup', 5 );

	}

	/**
	 * Creates a snapshot of WordPress installation.
	 *
	 * ## OPTIONS
	 *
	 * [--name=<name>]
	 * : Snapshot nice name.
	 *
	 * [--config-only]
	 * : Store only configuration values WordPress version, Plugin/Theme version.
	 * * ---
	 * default: true
	 * options:
	 *   - true
	 *   - false
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp snapshot create
	 *
	 * @when  after_wp_load
	 * @throws WP_CLI\ExitException
	 */
	public function create( $args, $assoc_args ) {
		$this->backup_type = Utils\get_flag_value( $assoc_args, 'config-only', true );

		// Create necessary directories.
		$this->initiate_backup( $assoc_args );
		// Create Database backup.
		$this->create_db_backup();
		// Create Media backup.
		$this->create_uploads_backup();
		// Create Plugins backup.
		$this->create_plugins_backup();
		// Create Themes backup.
		$this->create_themes_backup();

		// Store all config data in database.
		$name = Utils\get_flag_value( $assoc_args, 'name', $this->current_snapshots_dir );

		$snapshot_directory = Utils\trailingslashit( WP_CLI_SNAPSHOT_DIR ) . $this->current_snapshots_dir;
		if ( $this->zipData( $snapshot_directory, Utils\trailingslashit( WP_CLI_SNAPSHOT_DIR ) . $name . '.zip' ) ) {
			WP_CLI\Extractor::rmdir( $snapshot_directory );
			$this->progress->tick();
		}

		$upload_dir       = wp_upload_dir();
		$uploads_in_bytes = doubleval( shell_exec( 'du -sk ' . escapeshellarg( $upload_dir['basedir'] ) ) ) * 1024;
		$data             = [
			'name'         => $name,
			'created_at'   => time(),
			'core_version' => $GLOBALS['wp_version'],
			'core_type'    => 'mu' == $this->installation_type ? 'multisite' : 'standard',
			'db_size'      => size_format(
				$GLOBALS['wpdb']->get_var(
					$GLOBALS['wpdb']->prepare(
						"SELECT SUM(data_length + index_length) FROM information_schema.TABLES where table_schema = '%s' GROUP BY table_schema;",
						DB_NAME
					)
				)
			),
			'uploads_size' => size_format( $uploads_in_bytes ),
		];

		if ( true === $this->db->insert( 'snapshots', $data ) ) {
			$this->progress->finish();
			WP_CLI::success( 'Site backup completed.' );
		} else {
			WP_CLI::error( 'Something went wrong.' );
		}

	}

	/**
	 * List all the backup snapshots.
	 *
	 * ## OPTIONS
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - ids
	 *   - json
	 *   - count
	 *   - yaml
	 * ---
	 * ## EXAMPLES
	 *
	 *     $ wp snapshot list
	 *
	 * @subcommand list
	 *
	 * @when       before_wp_load
	 * @throws WP_CLI\ExitException
	 */
	public function _list( $args, $assoc_args ) {
		$snapshot_list = $this->db->get_data();

		// Return error if no backups exist.
		if ( empty( $snapshot_list ) ) {
			WP_CLI::error( 'No backups found' );
		}

		foreach ( $snapshot_list as $id => $snapshot ) {
			if ( 0 === abs( $snapshot['backup_type'] ) ) {
				$snapshot_list[ $id ]['backup_type'] = 'config';
			} else {
				$snapshot_list[ $id ]['backup_type'] = 'file';
			}
			$snapshot_list[ $id ]['created_at'] = gmdate( 'Y-m-d\TH:i:s\Z', $snapshot_list[ $id ]['created_at'] );
		}
		$formatter = new Formatter(
			$assoc_args,
			[ 'id', 'name', 'created_at', 'backup_type', 'core_version', 'core_type', 'db_size', 'uploads_size' ]
		);
		$formatter->display_items( $snapshot_list );
	}

	/**
	 * Restores a snapshot of WordPress installation.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : ID / Name of Snapshot to restore.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp snapshot restore 1
	 *
	 * @when  after_wp_load
	 */
	public function restore( $args, $assoc_args ) {
		$backup_id      = abs( $args[0] );
		$snapshot_files = [];

		if ( ! empty( $backup_id ) ) {
			$backup_info = $this->db->get_data( $backup_id );
		} else {
			$backup_info = $this->db->get_backup_by_name( $args[0] );
		}

		$temp_info = $backup_info;
		unset( $temp_info['backup_type'], $temp_info['created_at'], $temp_info['name'], $temp_info['id'] );
		$assoc_args['fields'] = array_keys( $temp_info );

		// Display a small summary of the backup info.
		WP_CLI::warning( 'Please check Snapshot information before proceeding...' );
		$formatter = new Formatter( $assoc_args );
		$formatter->display_item( $temp_info );
		WP_CLI::confirm( 'Would you like to proceed with the Restore Operation?' );

		// Update WordPress version if required.
		$this->maybe_restore_core_version( $backup_info['core_version'] );

		// Get all the backup zip content.
		$zip_content = $this->get_zip_contents( $backup_info['name'] );

		// Store all required data for restoring.
		foreach ( $zip_content as $snapshot_content ) {
			$snapshot_content_ext = pathinfo( $snapshot_content, PATHINFO_EXTENSION );
			if ( 'sql' === $snapshot_content_ext ) {
				$snapshot_files['db'] = $snapshot_content;
			} elseif ( 'json' === $snapshot_content_ext ) {
				$config_name                               = pathinfo( $snapshot_content, PATHINFO_FILENAME );
				$snapshot_files['configs'][ $config_name ] = $snapshot_content;
			} elseif ( 'zip' === $snapshot_content_ext ) {
				$snapshot_files['zip'] = $snapshot_content;
			}
		}

		// Restore Database.
		if ( ! empty( $snapshot_files['db'] ) ) {
			WP_CLI::log( 'Restoring database backup...' );
			WP_CLI::runcommand( "db import {$snapshot_files['db']} --quiet" );
			$this->progress->tick();
		}

		// Restore Plugins and Themes.
		if ( ! empty( $snapshot_files['configs'] ) ) {
			$this->restore_plugin_data( $snapshot_files['configs']['plugins'] );
			$this->restore_theme_data( $snapshot_files['configs']['themes'] );
		}

		// Restore Media.
		$this->restore_media_backup( $snapshot_files['zip'] );

		$this->progress->finish();
		WP_CLI::success( 'Site restore completed' );
	}

	/**
	 * Delete a given snapshot.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : ID / Name of Snapshot to delete.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp snapshot delete 1
	 *
	 * @when       before_wp_load
	 * @throws WP_CLI\ExitException
	 */
	public function delete( $args, $assoc_args ) {
		$snapshot_list = $this->db->get_data();
		$backup_id     = abs( $args[0] );

		// Return error if no backups exist.
		if ( empty( $snapshot_list ) ) {
			WP_CLI::error( 'No backups found' );
		}

		if ( ! empty( $backup_id ) ) {
			$backup_info = $this->db->get_data( $backup_id );
		} else {
			$backup_info = $this->db->get_backup_by_name( $args[0] );
		}

		// If backup exists delete the record from db and remove the zip.
		if ( ! empty( $backup_info ) ) {
			if ( true === $this->db->delete_backup_by_id( $backup_info['id'] ) ) {
				$backup_path = sprintf( '%s/%s.zip', WP_CLI_SNAPSHOT_DIR, $backup_info['name'] );
				unlink( Utils\trailingslashit( WP_CLI_SNAPSHOT_DIR ) . $backup_info['name'] . '.zip' );
				WP_CLI::success( 'Successfully deleted backup' );
			}
		} else {
			WP_CLI::error( "Backup with id/name '{$backup_id}' not found" );
		}
	}

	/**
	 * Restore theme from backup info.
	 *
	 * @param string $themes_json_file Theme data file.
	 */
	private function restore_theme_data( $themes_json_file ) {
		if ( ! empty( $themes_json_file ) ) {
			WP_CLI::warning( 'Removing currently installed themes' );
			WP_CLI::runcommand( 'theme delete --all --force --quiet' );

			WP_CLI::log( 'Restoring themes...' );
			$backup_theme_data = json_decode( file_get_contents( $themes_json_file ), true );
			foreach ( $backup_theme_data as $theme_data ) {
				$theme_is_public = $theme_data['is_public'];
				$theme_name      = $theme_data['name'];
				$theme_slug      = $theme_data['slug'];
				$theme_version   = $theme_data['version'];
				$theme_is_active = true === $theme_data['is_active'] ? '--activate' : '';

				if ( false === $theme_is_public ) {
					WP_CLI::warning( "Theme {$theme_name} is not available on WordPress.org, please install from appropriate source" );
				} else {
					WP_CLI::runcommand( "theme install {$theme_slug} --version={$theme_version} {$theme_is_active} --quiet" );
				}
			}
			$this->progress->tick();
		}
	}

	/**
	 * Restore plugin from backup info.
	 *
	 * @param string $plugins_json_file Plugin data file.
	 */
	private function restore_plugin_data( $plugins_json_file ) {
		if ( ! empty( $plugins_json_file ) ) {
			WP_CLI::warning( 'Removing currently installed plugins' );
			WP_CLI::runcommand( 'plugin deactivate --all --quiet' );
			WP_CLI::runcommand( 'plugin uninstall --all --quiet' );

			WP_CLI::log( 'Restoring plugins...' );
			$backup_plugin_data = json_decode( file_get_contents( $plugins_json_file ), true );
			foreach ( $backup_plugin_data as $plugin_data ) {
				$plugin_is_public = $plugin_data['is_public'];
				$plugin_name      = $plugin_data['name'];
				$plugin_slug      = $plugin_data['slug'];
				$plugin_version   = $plugin_data['version'];
				$plugin_is_active = true === $plugin_data['is_active'] ? '--activate' : '';

				if ( false === $plugin_is_public ) {
					WP_CLI::warning( " Plugin {$plugin_name} is not available on WordPress.org, please install from appropriate source" );
				} else {
					WP_CLI::runcommand( "plugin install {$plugin_slug} --version={$plugin_version} {$plugin_is_active} --quiet" );
				}
			}
			$this->progress->tick();
		}
	}

	/**
	 * Restore media files from zip content.
	 *
	 * @param string $uploads_zip Uploads zip path.
	 */
	private function restore_media_backup( $uploads_zip ) {
		if ( ! empty( $uploads_zip ) ) {
			$wp_content_dir = wp_upload_dir();

			// Remove the current files and directories.
			$directory_iterator_instance = new \RecursiveDirectoryIterator( $wp_content_dir['basedir'], \FilesystemIterator::SKIP_DOTS );
			$recursive_iterator_instance = new \RecursiveIteratorIterator( $directory_iterator_instance, \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $recursive_iterator_instance as $resource_to_be_removed ) {
				$resource_to_be_removed->isDir() ? rmdir( $resource_to_be_removed ) : unlink( $resource_to_be_removed );
			}

			WP_CLI::log( 'Restoring media backup...' );
			$this->unZipData( $uploads_zip, $wp_content_dir['basedir'] );
			$this->progress->tick();
		}
	}

	/**
	 * Setup the most required data for the backup.
	 *
	 * @param $assoc_args
	 *
	 * @throws WP_CLI\ExitException
	 */
	private function initiate_backup( $assoc_args ) {
		$this->installation_type = is_multisite() ? 'mu' : '';
		if ( 'mu' === $this->installation_type && Utils\get_flag_value( $assoc_args, 'config-only', true ) ) {
			WP_CLI::error( 'Multisite is not supported' );
		}

		$snapshot_name                     = Utils\get_flag_value( $assoc_args, 'name' );
		$hash                              = substr( md5( mt_rand() ), 0, 7 );
		$this->current_snapshots_dir       = sprintf( 'snapshot-%s-%s', gmdate( 'Y-m-d' ), ! empty( $snapshot_name ) ? $snapshot_name . '-' . $hash : $hash );
		$this->current_snapshots_full_path = Utils\trailingslashit( WP_CLI_SNAPSHOT_DIR ) . $this->current_snapshots_dir;
		mkdir( $this->current_snapshots_full_path );

		$config_dir = Utils\trailingslashit( $this->current_snapshots_full_path ) . 'configs';
		if ( ! is_dir( $config_dir ) ) {
			mkdir( $config_dir );
		}
		$this->config_dir = $config_dir;
		$this->progress->tick();
	}

	/**
	 * Create the DB backup for the given WordPress installation.
	 */
	private function create_db_backup() {
		$db_export_path     = getcwd();
		$current_export_sql = WP_CLI::runcommand( 'db export --add-drop-table --porcelain', [ 'return' => true ] );
		exec( "mv $db_export_path/$current_export_sql $this->current_snapshots_full_path" );
		$this->config['db_backup'] = Utils\trailingslashit( $this->current_snapshots_dir ) . $current_export_sql;
		$this->progress->tick();
	}

	/**
	 * Create media upload backup for the given WordPress installation.
	 */
	private function create_uploads_backup() {
		$wp_content_dir = wp_upload_dir();
		$destination    = Utils\trailingslashit( WP_CLI_SNAPSHOT_DIR ) . Utils\trailingslashit( $this->current_snapshots_dir ) . 'uploads.zip';
		if ( $this->zipData( $wp_content_dir['basedir'], $destination ) ) {
			$this->config['media_backup'] = Utils\trailingslashit( $this->current_snapshots_dir ) . 'uploads.zip';
			$this->progress->tick();
		}
	}

	/**
	 * Wrapper function to zip the given files to a specified location on the file system.
	 *
	 * @param $source
	 * @param $destination
	 *
	 * @return bool
	 */
	private function zipData( $source, $destination ) {
		if ( file_exists( $source ) ) {
			$zip = new ZipArchive();
			$zip->open( $destination, ZipArchive::CREATE );
			$files = self::recursive_scandir( $source );
			foreach ( $files as $file ) {
				if ( 0 === substr_compare( $file, '/', - 1 ) ) {
					$zip->addEmptyDir( $file );
				} else {
					$zip->addFile( $source . '/' . $file, $file );
				}
			}

			return $zip->close();
		}

		return false;
	}

	/**
	 * Wrapper function to unzip the given zip to a specified location on the file system.
	 *
	 * @param $zip_file
	 * @param $destination
	 *
	 * @return bool
	 */
	private function unZipData( $zip_file, $destination ) {
		$zip = new ZipArchive();
		if ( $zip->open( $zip_file ) === true ) {
			$zip->extractTo( $destination );
			$zip->close();
		}

		return false;
	}

	/**
	 * Create a plugins backup for the given WordPress installation.
	 */
	private function create_plugins_backup() {
		$all_plugins_info = [];
		foreach ( $this->get_all_plugins() as $file => $details ) {
			$all_plugins_info[] = $this->get_plugin_info( $file, $details );
		}
		$this->write_config_to_file( $this->config_dir, 'plugins', $all_plugins_info );
		$this->config['plugin_info'] = Utils\trailingslashit( $this->config_dir ) . 'plugins.json';
		$this->progress->tick();
	}

	/**
	 * Gets all available plugins.
	 *
	 * Uses the same filter core uses in plugins.php to determine which plugins
	 * should be available to manage through the WP_Plugins_List_Table class.
	 *
	 * @return array
	 */
	private function get_all_plugins() {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Calling native WordPress hook.
		return apply_filters( 'all_plugins', get_plugins() );
	}

	/**
	 * Get formatted info for each plugin.
	 *
	 * @param $file
	 * @param $plugin_detail
	 *
	 * @return mixed
	 */
	private function get_plugin_info( $file, $plugin_detail ) {
		$plugin_data['name']      = $plugin_detail['Name'];
		$plugin_data['version']   = $plugin_detail['Version'];
		$plugin_data['slug']      = $this->get_plugin_slug( $file );
		$plugin_data['is_active'] = is_plugin_active( $file );
		$plugin_data['is_public'] = true;

		if ( null === $plugin_data['slug'] ) {
			WP_CLI::warning( "Skipping {$plugin_detail['Name']}: Plugin not available on WordPress.org" );
			if ( $this->backup_type ) {
				WP_CLI::log( 'Consider running the command with --config-only=false to backup private Plugin/Theme' );
			}
			$plugin_data['is_public'] = false;
		}

		return $plugin_data;
	}

	/**
	 * Get the plugin slug.
	 *
	 * @param $file
	 *
	 * @return |null
	 */
	private function get_plugin_slug( $file ) {
		// Check installed plugins versions against the latest versions on WordPress.org.
		wp_update_plugins();
		$all_plugins_info = get_site_transient( 'update_plugins' );

		if ( isset( $all_plugins_info->no_update[ $file ] ) ) {
			return $all_plugins_info->no_update[ $file ]->slug;
		}

		if ( isset( $all_plugins_info->response[ $file ] ) ) {
			return $all_plugins_info->response[ $file ]->slug;
		}

		return null;
	}

	/**
	 * Store backup configuration to a file.
	 *
	 * @param $config_dir
	 * @param $name
	 * @param $data
	 */
	private function write_config_to_file( $config_dir, $name, $data ) {
		$fp = fopen( $config_dir . '/' . $name . '.json', 'w' );
		fwrite( $fp, json_encode( $data ) );
		fclose( $fp );
	}

	/**
	 * Create a themes backup for the given WordPress installation.
	 */
	private function create_themes_backup() {
		$all_themes_info = [];
		foreach ( wp_get_themes() as $name => $theme ) {
			$all_themes_info[] = $this->get_theme_info( $name, $theme );
		}
		$this->write_config_to_file( $this->config_dir, 'themes', $all_themes_info );
		$this->config['theme_info'] = Utils\trailingslashit( $this->config_dir ) . 'themes.json';
		$this->progress->tick();
	}

	/**
	 * Get formatted info for each theme.
	 *
	 * @param $name
	 * @param $theme_detail
	 *
	 * @return mixed
	 */
	private function get_theme_info( $name, $theme_detail ) {
		$theme_data['name']      = $theme_detail->get( 'Name' );
		$theme_data['version']   = $theme_detail->get( 'Version' );
		$theme_data['slug']      = $name;
		$theme_data['is_active'] = true === $this->is_theme_active( $theme_detail ) ? true : false;
		$theme_data['is_public'] = true;

		if ( ! $this->is_theme_public( $name ) ) {
			WP_CLI::warning( "Skipping {$name} : Theme not available on WordPress.org" );
			if ( $this->backup_type ) {
				WP_CLI::log( 'Consider running the command with --config-only=false to backup private Plugin/Theme' );
			}
			$theme_data['is_public'] = false;
		}

		return $theme_data;
	}

	/**
	 * Check if the given theme is the active theme.
	 *
	 * @param $theme
	 *
	 * @return bool
	 */
	private function is_theme_active( $theme ) {
		return $theme->get_stylesheet_directory() === get_stylesheet_directory();
	}

	/**
	 * Verify if the given theme is available on WordPress.org.
	 *
	 * @param $theme
	 *
	 * @return bool
	 */
	private function is_theme_public( $theme ) {
		$api = themes_api( 'theme_information', array( 'slug' => $theme ) );
		if ( is_wp_error( $api ) && 'themes_api_failed' === $api->get_error_code() && 'Theme not found' === $api->get_error_message() ) {
			return false;
		}

		return true;
	}

	/**
	 * Recursively scan a given directory and get the file contents.
	 *
	 * @param        $dir
	 * @param string $prefix_dir
	 *
	 * @return array
	 */
	private function recursive_scandir( $dir, $prefix_dir = '' ) {
		$ret = array();
		foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $file ) {
			if ( is_dir( $dir . '/' . $file ) ) {
				$ret[] = ( $prefix_dir ? ( $prefix_dir . '/' . $file ) : $file ) . '/';
				$ret   = array_merge( $ret, self::recursive_scandir( $dir . '/' . $file, $prefix_dir ? ( $prefix_dir . '/' . $file ) : $file ) );
			} else {
				$ret[] = $prefix_dir ? ( $prefix_dir . '/' . $file ) : $file;
			}
		}

		return $ret;
	}

	/**
	 * Setup WordPress core files as required for restore.
	 *
	 * @param $backup_version
	 */
	private function maybe_restore_core_version( $backup_version ) {
		global $wp_version;

		if ( $wp_version === $backup_version ) {
			WP_CLI::log( 'Installed version matches snapshot version, checking files authenticity' );
			$checksum_result = WP_CLI::runcommand( 'core verify-checksums --quiet' );
			if ( 0 === $checksum_result->return_code ) {
				WP_CLI::log( 'WordPress verifies against its checksums, skipping WordPress Core Installation' );
			} else {
				WP_CLI::warning( 'WordPress version doesn\'t verify against its checksums, installing fresh setup' );
				WP_CLI::log( "Downloading fresh flies for WordPress version {$backup_version}" );
				WP_CLI::runcommand( "core download --version={$backup_version} --force --quiet" );
			}
		} else {
			WP_CLI::log( "Downloading fresh files for WordPress version {$backup_version}" );
			WP_CLI::runcommand( "core download --version={$backup_version} --force --quiet" );
		}
	}

	/**
	 * Get all the contents in a zipped file for further processing.
	 *
	 * @param $backup_name
	 *
	 * @return array|bool
	 */
	private function get_zip_contents( $backup_name ) {
		$temp_dir = Utils\get_temp_dir() . uniqid( 'wp-cli-snapshot-restore-', true );
		mkdir( $temp_dir );
		$zip = new ZipArchive();
		$res = $zip->open( Utils\trailingslashit( WP_CLI_SNAPSHOT_DIR ) . $backup_name . '.zip' );
		if ( true === $res ) {
			$zip->extractTo( $temp_dir );
			$zip->close();
			$files     = self::recursive_scandir( $temp_dir );
			$temp_dir  = Utils\trailingslashit( $temp_dir );
			$all_files = array_map(
				function ( $current_path ) use ( $temp_dir ) {
					return $temp_dir . $current_path;
				},
				$files
			);

			return $all_files;
		}

		return false;
	}

}
