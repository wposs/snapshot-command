<?php

namespace WP_CLI\Snapshot;

use WP_CLI;
use WP_CLI_Command;
use WP_CLI\Utils;
use WP_CLI\Formatter;
use ZipArchive;
use function cli\prompt;

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
	 * @var object|SnapshotDB
	 */
	protected $db = '';

	/**
	 * Instance of Snapshot Storage Class.
	 *
	 * @var object|SnapshotStorage
	 */
	protected $storage = '';

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

		$this->db             = new SnapshotDB();
		$this->storage        = new SnapshotStorage();
		$this->snapshot_utils = new WP_CLI\Snapshot\Utils();

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
		$this->backup_type = Utils\get_flag_value( $assoc_args, 'config-only' );

		if ( empty( $this->backup_type ) ) {
			$this->start_progress_bar( 'Creating Backup', 4 );
			$db_backup_type = 1;
		} else {
			$this->start_progress_bar( 'Creating Backup', 5 );
			$db_backup_type = 0;
		}

		// Create necessary directories.
		$this->initiate_backup( $assoc_args );
		// Create Database backup.
		$this->create_db_backup();

		if ( empty( $this->backup_type ) ) {
			// Create wp-content backup.
			$this->create_wp_content_backup();
		} else {
			// Create Media backup.
			$this->create_uploads_backup();
			// Create Plugins backup.
			$this->create_plugins_backup();
			// Create Themes backup.
			$this->create_themes_backup();
		}

		// Store all config data in database.
		$name = Utils\get_flag_value( $assoc_args, 'name', $this->current_snapshots_dir );

		$snapshot_directory = Utils\trailingslashit( WP_CLI_SNAPSHOT_DIR ) . $this->current_snapshots_dir;
		if ( $this->zipData( $snapshot_directory, Utils\trailingslashit( WP_CLI_SNAPSHOT_DIR ) . $name . '.zip' ) ) {
			WP_CLI\Extractor::rmdir( $snapshot_directory );
			$this->progress->tick();
		}

		$zip_size_in_bytes = doubleval( shell_exec( 'du -sk ' . escapeshellarg( Utils\trailingslashit( WP_CLI_SNAPSHOT_DIR ) . $name . '.zip' ) ) ) * 1024;
		$snapshot_id       = $this->create_snapshot(
			[
				'name'            => $name,
				'created_at'      => time(),
				'backup_type'     => $db_backup_type,
				'backup_zip_size' => size_format( $zip_size_in_bytes ),
			]
		);

		if ( ! empty( $snapshot_id ) ) {
			$upload_dir       = wp_upload_dir();
			$uploads_in_bytes = doubleval( shell_exec( 'du -sk ' . escapeshellarg( $upload_dir['basedir'] ) ) ) * 1024;
			$this->create_snapshot_extra_info(
				[
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
					'snapshot_id'  => $snapshot_id,
				],
				$snapshot_id
			);
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
		$snapshot_list = $this->db->get_snapshot_data();

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
			[ 'id', 'name', 'created_at', 'backup_type', 'backup_zip_size' ]
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
	 * @throws WP_CLI\ExitException
	 */
	public function restore( $args, $assoc_args ) {
		$this->snapshot_utils->available_wp_packages(); // Check required packages available or not.
		$backup_info         = $this->get_backup_info( $args[0] );
		$snapshot_files      = [];
		$extra_snapshot_info = $this->db->get_extra_snapshot_info( $backup_info['id'] );
		$temp_info           = array_merge( $backup_info, $extra_snapshot_info );
		$db_backup_type      = $temp_info['backup_type'];
		unset( $temp_info['backup_type'], $temp_info['backup_zip_size'], $temp_info['created_at'], $temp_info['name'], $temp_info['id'], $temp_info['snapshot_id'] );
		$assoc_args['fields'] = array_keys( $temp_info );

		if ( 1 === abs( $db_backup_type ) ) {
			$this->start_progress_bar( 'Restoring Backup', 2 );
		} else {
			$this->start_progress_bar( 'Restoring Backup', 4 );
		}

		// Display a small summary of the backup info.
		WP_CLI::warning( 'Please check Snapshot information before proceeding...' );
		$formatter = new Formatter( $assoc_args );
		$formatter->display_item( $temp_info );
		WP_CLI::confirm( 'Would you like to proceed with the Restore Operation?' );

		// Update WordPress version if required.
		$this->maybe_restore_core_version( $extra_snapshot_info['core_version'] );

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
			$this->restore_extension_data( $snapshot_files['configs']['plugins'], 'plugin' );
			$this->restore_extension_data( $snapshot_files['configs']['themes'], 'theme' );
		}

		if ( 1 === abs( $db_backup_type ) ) {
			// Restore WP_content.
			$this->restore_backup( $snapshot_files['zip'], WP_CONTENT_DIR );
		} else {
			// Restore Media.
			$wp_content_dir = wp_upload_dir();
			$this->restore_backup( $snapshot_files['zip'], $wp_content_dir['basedir'] );
		}

		$this->progress->finish();
		WP_CLI::success( 'Site restore completed' );
	}

	/**
	 * Get information of the installation for given backup.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : ID / Name of Snapshot to inspect.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp snapshot inspect 1
	 *
	 * @when  before_wp_load
	 * @throws WP_CLI\ExitException
	 */
	public function inspect( $args, $assoc_args ) {
		$backup_info         = $this->get_backup_info( $args[0] );
		$extra_snapshot_info = $this->db->get_extra_snapshot_info( $backup_info['id'] );
		$temp_info           = $extra_snapshot_info;
		unset( $temp_info['snapshot_id'] );
		$assoc_args['fields'] = array_keys( $temp_info );

		// Display a small summary of the installation info.
		$formatter = new Formatter( $assoc_args );
		$formatter->display_item( $temp_info );
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
		$backup_info = $this->get_backup_info( $args[0] );

		// Delete the record from db and remove the zip.
		if ( true === $this->db->delete_backup_by_id( $backup_info['id'] ) ) {
			$backup_path = sprintf( '%s/%s.zip', WP_CLI_SNAPSHOT_DIR, $backup_info['name'] );
			unlink( $backup_path );
			WP_CLI::success( 'Successfully deleted backup' );
		}
	}

	/**
	 * Configure credentials for external storage.
	 *
	 * Supported services are:
	 *  - Amazon S3
	 *
	 * ## OPTIONS
	 *
	 * [--service=<service>]
	 * : Third party storage service to store backup zip.
	 * ---
	 * default: aws
	 * options:
	 *   - aws
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp snapshot configure --service=aws
	 *
	 * @when       before_wp_load
	 * @throws WP_CLI\ExitException
	 */
	public function configure( $args, $assoc_args ) {
		$service = Utils\get_flag_value( $assoc_args, 'service' );
		if ( 'aws' === $service ) {
			$aws_key       = prompt( 'Please enter your AWS Key', false, ': ', true );
			$aws_secret    = prompt( 'Please enter your AWS Secret', false, ': ', true );
			$service_array = [
				'aws_key' => $aws_key,
				'aws_secret' => $aws_secret,
			];
			foreach ( $service_array as $info_item_key => $info_item_value ) {
				$extra_info = [
					'info_key'        => $info_item_key,
					'info_value'      => $info_item_value,
					'storage_service' => $service,
				];
				$this->db->insert( 'snapshot_storage_credentials', $extra_info );
			}
		}
		WP_CLI::success( "Successfully configured {$service} credentials" );
	}

	/**
	 * Push the snapshot to an external sotrage service.
	 *
	 * <id>
	 * : ID / Name of Snapshot to inspect.
	 *
	 * ## OPTIONS
	 *
	 * [--service=<service>]
	 * : Third party storage service to store backup zip.
	 * ---
	 * default: aws
	 * options:
	 *   - aws
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp snapshot push 1 --service=aws
	 *
	 * @when       before_wp_load
	 * @throws WP_CLI\ExitException
	 */
	public function push( $args, $assoc_args ) {
		$backup_info  = $this->get_backup_info( $args[0] );
		$service      = Utils\get_flag_value( $assoc_args, 'service' );
		$service_info = $this->storage->get_storage_service_info( $service );

		// Make sure we have required data to proceed.
		if ( empty( $service_info ) ) {
			WP_CLI::error( 'Please configure your service using wp snapshot configure --service=<service_name>' );
		}

		// Handle backup push based on service type.
		if ( 'aws' === $service ) {
			$bucket_name   = prompt( 'Please enter a S3 bucket name', false, ': ' );
			$bucket_region = prompt( 'Please enter a S3 bucket region', false, ': ' );
			if ( empty( $bucket_name ) ) {
				WP_CLI::error( 'Please provide a S3 bucket name' );
			}
			if ( empty( $bucket_region ) ) {
				WP_CLI::error( 'Please provide a S3 bucket region' );
			}

			// Initialize the s3 instance.
			$this->storage->initialize_s3(
				[
					'region' => $bucket_region,
					'key'    => $service_info['aws_key'],
					'secret' => $service_info['aws_secret'],
				]
			);

			// Push the zip file to bucket.
			$upload_complete = $this->storage->push_to_s3_bucket(
				[
					'bucket_name' => $bucket_name,
					'backup_path' => sprintf(
						'%s%s.zip',
						Utils\trailingslashit( WP_CLI_SNAPSHOT_DIR ),
						$backup_info['name']
					),
				]
			);

			if ( true === $upload_complete ) {
				WP_CLI::success( "Successfully uploaded {$backup_info['name']}.zip to S3 bucket: {$bucket_name}" );
			} else {
				WP_CLI::error( 'Upload error, something went wrong' );
			}
		}
	}

	/**
	 * Wrapper function to check and get backup info.
	 *
	 * @param string $snapshot_id Snapshot ID / Name.
	 *
	 * @return array|bool
	 * @throws WP_CLI\ExitException
	 */
	private function get_backup_info( $snapshot_id ) {
		if ( ! empty( $snapshot_id ) ) {
			$backup_info = $this->db->get_snapshot_data( abs( $snapshot_id ) );
		} else {
			$backup_info = $this->db->get_backup_by_name( $snapshot_id );
		}

		// Check if there is valid backup to inspect.
		if ( empty( $backup_info ) ) {
			WP_CLI::error( "Snapshot with id/name '{$snapshot_id}' doesn't exist" );
		}

		return $backup_info;
	}

	/**
	 * Add extra information about the installation in the snapshot.
	 *
	 * @param array $installation_info Extra information on the installation.
	 * @param int   $snapshot_id       Snapshot ID.
	 */
	private function create_snapshot_extra_info( $installation_info, $snapshot_id ) {
		foreach ( $installation_info as $info_item_key => $info_item_value ) {
			$extra_info = [
				'info_key'    => $info_item_key,
				'info_value'  => $info_item_value,
				'snapshot_id' => $snapshot_id,
			];
			$this->db->insert( 'snapshot_extra_info', $extra_info );
		}
	}

	/**
	 * Create a snapshot record in DB and return the ID.
	 *
	 * @param array $snapshot_info Snapshot information.
	 *
	 * @return mixed
	 */
	private function create_snapshot( $snapshot_info ) {
		return $this->db->insert( 'snapshots', $snapshot_info );
	}

	/**
	 * Restore extension from backup info.
	 *
	 * @param string $ext_json_file Extension data file.
	 * @param string $ext_type      Type of Extension.
	 *                              Options: theme|plugin
	 *
	 * @return void
	 */
	private function restore_extension_data( $ext_json_file, $ext_type ) {
		if ( empty( $ext_json_file ) || empty( $ext_type ) ) {
			return;
		}

		$extension_type = ucfirst( $ext_type );
		WP_CLI::warning( "Removing currently installed {$ext_type}s" );
		if ( 'plugin' === $ext_type ) {
			WP_CLI::runcommand( 'plugin deactivate --all --quiet' );
			WP_CLI::runcommand( 'plugin uninstall --all --quiet' );
		} elseif ( 'theme' === $ext_type ) {
			WP_CLI::runcommand( 'theme delete --all --force --quiet' );
		}

		WP_CLI::log( "Restoring {$ext_type}s..." );
		$backup_ext_data = json_decode( file_get_contents( $ext_json_file ), true );
		foreach ( $backup_ext_data as $ext_data ) {
			$ext_is_public = $ext_data['is_public'];
			$ext_name      = $ext_data['name'];
			$ext_slug      = $ext_data['slug'];
			$ext_version   = $ext_data['version'];
			$ext_is_active = true === $ext_data['is_active'] ? '--activate' : '';

			if ( false === $ext_is_public ) {
				WP_CLI::warning( "{$extension_type} {$ext_name} is not available on WordPress.org, please install from appropriate source" );
			} else {
				WP_CLI::runcommand( "{$ext_type} install {$ext_slug} --version={$ext_version} {$ext_is_active} --quiet" );
			}
		}
		$this->progress->tick();
	}

	/**
	 * Restore files from zip content.
	 *
	 * @param string $source_zip Source zip path.
	 * @param string $dest_dir   Destination directory path.
	 *
	 * @return void
	 */
	private function restore_backup( $source_zip, $dest_dir ) {
		if ( empty( $source_zip ) || empty( $dest_dir ) ) {
			return;
		}

		// Remove the current files and directories.
		$directory_iterator_instance = new \RecursiveDirectoryIterator( $dest_dir, \FilesystemIterator::SKIP_DOTS );
		$recursive_iterator_instance = new \RecursiveIteratorIterator( $directory_iterator_instance, \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $recursive_iterator_instance as $resource_to_be_removed ) {
			$resource_to_be_removed->isDir() ? rmdir( $resource_to_be_removed ) : unlink( $resource_to_be_removed );
		}

		WP_CLI::log( 'Restoring files backup...' );
		$this->unZipData( $source_zip, $dest_dir );
		$this->progress->tick();
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
			WP_CLI::error( 'Multisite with --config-only flag is not supported.' );
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
		$this->progress->tick();
	}

	/**
	 * Create media upload backup for the given WordPress installation.
	 */
	private function create_uploads_backup() {
		$wp_content_dir = wp_upload_dir();
		$destination    = Utils\trailingslashit( WP_CLI_SNAPSHOT_DIR ) . Utils\trailingslashit( $this->current_snapshots_dir ) . 'uploads.zip';
		if ( $this->zipData( $wp_content_dir['basedir'], $destination ) ) {
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
		$installation_path = ABSPATH;

		if ( $wp_version === $backup_version ) {
			WP_CLI::log( 'Installed version matches snapshot version, checking files authenticity' );
			$checksum_result = WP_CLI::runcommand( 'core verify-checksums --quiet' );
			if ( 0 === $checksum_result->return_code ) {
				WP_CLI::log( 'WordPress verifies against its checksums, skipping WordPress Core Installation' );
			} else {
				WP_CLI::warning( 'WordPress version doesn\'t verify against its checksums, installing fresh setup' );
				$this->download_wp( $backup_version, $installation_path );
			}
		} else {
			WP_CLI::log( 'Installed version doesn\'t match' );
			$this->download_wp( $backup_version, $installation_path );
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

	/**
	 * Download WordPress.
	 *
	 * @param string $backup_version    WP version to install.
	 * @param string $installation_path Installation path.
	 *
	 * @return void
	 */
	private function download_wp( $backup_version, $installation_path ) {
		WP_CLI::log( "Downloading fresh files for WordPress version {$backup_version}" );
		WP_CLI::runcommand( "core download --version={$backup_version} --path={$installation_path} --force --quiet" );
	}

	/**
	 * Start progress bar.
	 *
	 * @param string $message Message to show.
	 * @param int    $count   Number of ticks.
	 *
	 * @return void
	 */
	private function start_progress_bar( $message, $count ) {
		$this->progress = Utils\make_progress_bar( $message, $count );
	}

	/**
	 * Create wp-content backup for the given WordPress installation.
	 */
	private function create_wp_content_backup() {
		$wp_content_dir = WP_CONTENT_DIR;
		$destination    = Utils\trailingslashit( WP_CLI_SNAPSHOT_DIR ) . Utils\trailingslashit( $this->current_snapshots_dir ) . 'wp-content.zip';
		if ( $this->zipData( $wp_content_dir, $destination ) ) {
			$this->progress->tick();
		}
	}

}
