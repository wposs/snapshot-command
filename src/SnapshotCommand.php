<?php

namespace WP_CLI\Snapshot;

use WP_CLI;
use WP_CLI_Command;
use WP_CLI\Utils;
use WP_CLI\Formatter;
use WP_CLI\Extractor;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Backup / Restore WordPress installation
 *
 * @package wp-cli
 */
class SnapshotCommand extends WP_CLI_Command {

	private $config = [];
	private $progress;

	protected $current_snapshots_dir = '';
	protected $current_snapshots_full_path = '';
	protected $config_dir = '';
	protected $db = '';
	protected $backup_type = '';
	protected $installation_type = '';


	public function __construct() {

		define( 'SNAPSHOT_DIR', Utils\get_home_dir() . '/.wp-cli/snapshots' );

		if ( ! is_dir( SNAPSHOT_DIR ) ) {
			mkdir( SNAPSHOT_DIR );
		}

		if ( ! is_readable( SNAPSHOT_DIR ) ) {
			WP_CLI::error( SNAPSHOT_DIR .' is not readable.' );
		}

        if ( ! class_exists( 'ZipArchive' ) ) {
            throw new Exception( 'Snapshot command requires ZipArchive.' );
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

		$snapshot_directory = Utils\trailingslashit( SNAPSHOT_DIR ) . $this->current_snapshots_dir;
		if ( $this->zipData( $snapshot_directory, Utils\trailingslashit( SNAPSHOT_DIR ) . $name . '.zip' ) ) {
			WP_CLI\Extractor::rmdir( $snapshot_directory );
			$this->progress->tick();
		}

		$upload_dir = wp_upload_dir();
		$data       = [
			'name'         => $name,
			'created_at'   => time(),
			'core_version' => $GLOBALS['wp_version'],
			'core_type'    => 'mu' == $this->installation_type ? 'multisite' : 'standard',
			'db_size'      => size_format( $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT SUM(data_length + index_length) FROM information_schema.TABLES where table_schema = '%s' GROUP BY table_schema;", DB_NAME ) ) ),
			'uploads_size' => size_format( shell_exec( 'du -sk ' . escapeshellarg( $upload_dir['basedir'] ) ) )
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
	 */
	public function _list( $args, $assoc_args ) {
		$snapshot_list = $this->db->get_data();
		foreach ( $snapshot_list as $id => $snapshot ) {
			if ( 0 === abs( $snapshot['backup_type'] ) ) {
				$snapshot_list[ $id ]['backup_type'] = 'config';
			} else {
				$snapshot_list[ $id ]['backup_type'] = 'file';
			}
			$snapshot_list[ $id ]['created_at'] = gmdate( "Y-m-d\TH:i:s\Z", $snapshot_list[ $id ]['created_at'] );
		}
		$formatter = new Formatter( $assoc_args,
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

		$backup_id = abs( $args[0] );

		if ( ! empty( $backup_id ) ) {
			$backup_info = $this->db->get_data( $backup_id );
		} else {
			$backup_info = $this->db->get_backup_by_name( $args[0] );
		}

		if ( ! isset( $assoc_args['fields'] ) ) {
			$temp_info = $backup_info;
			unset( $temp_info['backup_type'], $temp_info['created_at'], $temp_info['name'], $temp_info['id'] );
			$assoc_args['fields'] = array_keys( $temp_info );
		}

		WP_CLI::warning( 'Please check Snapshot Info before proceeding.' );
		$formatter = new \WP_CLI\Formatter( $assoc_args );
		$formatter->display_item( $temp_info );
		WP_CLI::confirm( 'Would you like to proceed with the Restore Operation?' );

		//$this->maybe_restore_core_version( $backup_info['core_version'] );

        $zip_content = $this->get_zip_contents( $backup_info['name'] );

        $snapshot_files = [];
        foreach ( $zip_content as $snapshot_content ) {
            if ( false !== strpos( $snapshot_content, '.sql' ) ) {
                $snapshot_files['db'] = $snapshot_content;
            } elseif ( false !== stripos( $snapshot_content, '.json' ) ) {
                $config_name = pathinfo($snapshot_content,PATHINFO_FILENAME);
                $snapshot_files['configs'][$config_name] = $snapshot_content;
            }
        }

        // Restore DB.
        if ( ! empty( $snapshot_files['db'] ) ) {
            WP_CLI::runcommand( "db import {$snapshot_files['db']}" );
        }

        if ( ! empty( $snapshot_files['configs'] ) ) {

            // Restore Plugins.
            if( ! empty( $snapshot_files['configs']['plugins'] ) ) {
                $backup_plugin_data = json_decode( file_get_contents( $snapshot_files['configs']['plugins'] ), true );
                var_dump($backup_plugin_data);
                var_dump($this->get_all_plugins() );
            }
        }

	}

	private function initiate_backup( $assoc_args ) {
		$this->installation_type = is_multisite() ? 'mu' : '';
		if ( 'mu' === $this->installation_type && Utils\get_flag_value( $assoc_args, 'config-only', true ) ) {
			WP_CLI::error( 'Multisite is not supported' );
		}

		$snapshot_name                     = Utils\get_flag_value( $assoc_args, 'name' );
		$hash                              = substr( md5( mt_rand() ), 0, 7 );
		$this->current_snapshots_dir       = sprintf( 'snapshot-%s-%s', date( 'Y-m-d' ), ! empty( $snapshot_name ) ? $snapshot_name . '-' . $hash : $hash );
		$this->current_snapshots_full_path = Utils\trailingslashit( SNAPSHOT_DIR ) . $this->current_snapshots_dir;
		mkdir( $this->current_snapshots_full_path );

		$config_dir = Utils\trailingslashit( $this->current_snapshots_full_path ) . 'configs';
		if ( ! is_dir( $config_dir ) ) {
			mkdir( $config_dir );
		}
		$this->config_dir = $config_dir;
		$this->progress->tick();
	}

	private function create_db_backup() {
		$db_export_path     = getcwd();
		$current_export_sql = WP_CLI::runcommand( 'db export --add-drop-table --porcelain', [ 'return' => true ] );
		exec( "mv $db_export_path/$current_export_sql $this->current_snapshots_full_path" );
		$this->config['db_backup'] = Utils\trailingslashit( $this->current_snapshots_dir ) . $current_export_sql;
		$this->progress->tick();
	}

	private function create_uploads_backup() {
		$wp_content_dir = wp_upload_dir();
		$destination    = Utils\trailingslashit( SNAPSHOT_DIR ) . Utils\trailingslashit( $this->current_snapshots_dir ) . 'uploads.zip';
		if ( $this->zipData( $wp_content_dir['basedir'], $destination ) ) {
			$this->config['media_backup'] = Utils\trailingslashit( $this->current_snapshots_dir ) . 'uploads.zip';
			$this->progress->tick();
		}
	}

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

	private function write_config_to_file( $config_dir, $name, $data ) {
		$fp = fopen( $config_dir . '/' . $name . '.json', 'w' );
		fwrite( $fp, json_encode( $data ) );
		fclose( $fp );
	}

	private function create_themes_backup() {
		$all_themes_info = [];
		foreach ( wp_get_themes() as $name => $theme ) {
			$all_themes_info[] = $this->get_theme_info( $name, $theme );
		}
		$this->write_config_to_file( $this->config_dir, 'themes', $all_themes_info );
		$this->config['theme_info'] = Utils\trailingslashit( $this->config_dir ) . 'themes.json';
		$this->progress->tick();
	}

	private function get_theme_info( $name, $theme_detail ) {
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

	private function is_theme_active( $theme ) {
		return $theme->get_stylesheet_directory() === get_stylesheet_directory();
	}

	private function is_theme_public( $theme ) {
		$api = themes_api( 'theme_information', array( 'slug' => $theme ) );
		if ( is_wp_error( $api ) && 'themes_api_failed' === $api->get_error_code() && 'Theme not found' === $api->get_error_message() ) {
			return false;
		}

		return true;
	}

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

	private function maybe_restore_core_version( $backup_version ) {
		global $wp_version;

		if ( $wp_version === $backup_version ) {
			WP_CLI::log( 'Installed version matches snapshot version.' );
			$checksum_result = WP_CLI::runcommand( 'core verify-checksums', [ 'return' => true ] );
			if ( 0 === $checksum_result->return_code ) {
				WP_CLI::log( 'WordPress verifies against its checksums, skipping WordPress Core Installation' );
			} else {
				WP_CLI::log( 'WordPress doesn\'t verify against its checksums.' );
				WP_CLI::runcommand( "core install --version {$backup_version}", [ 'return' => true ] );
			}
		} else {
			WP_CLI::runcommand( "core install --version={$backup_version}", [ 'return' => true ] );
		}
	}

	private function get_zip_contents( $backup_name ) {
        $temp_dir = Utils\get_temp_dir() . uniqid( 'wp-cli-snapshot-restore-', true );
        mkdir( $temp_dir );
        $zip = new ZipArchive();
        $res = $zip->open( Utils\trailingslashit( SNAPSHOT_DIR ) . $backup_name . '.zip' );
        if ($res === TRUE) {
            $zip->extractTo($temp_dir);
            $zip->close();
            $files = self::recursive_scandir( $temp_dir );
            $temp_dir = Utils\trailingslashit( $temp_dir );
            $all_files       = array_map(
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
