<?php

namespace WP_CLI\Snapshot;

use WP_CLI;
use WP_CLI_Command;
use WP_CLI\Utils;

/**
 * Backup / Restore WordPress installation
 *
 * @when    after_wp_load
 * @package wp-cli
 */
class SnapshotCommand extends WP_CLI_Command {

	private $config = [];

	protected $snapshots_dir = '';

	public function __construct() {

		$this->snapshots_dir = Utils\get_home_dir() . '/.wp-cli/snapshots';

		if ( ! file_exists( $this->snapshots_dir ) ) {
			mkdir( $this->snapshots_dir );
		}

		if ( ! is_readable( $this->snapshots_dir ) ) {
			WP_CLI::error( "{$this->snapshots_dir} is not readable." );
		}

	}

	/**
	 * Creates a snapshot of WordPress installation.
	 *
	 * [--name]
	 *  : Name of snapshot.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp snapshot create
	 */
	public function create( $args, $assoc_args ) {


		$this->get_core_info();
		$this->create_db_backup( $assoc_args );
		var_dump($this->config);
		// DB backup
		// Media backup.
		// Plugins backup.
		// Themes backup.
	}

	private function get_core_info() {
		global $wp_version;
		$installation_type  = is_multisite() ? 'mu' : '';
		$this->config['core_settings'] = [ 'wp_version' => $wp_version, 'site_type' => $installation_type ];
	}

	private function create_db_backup($assoc_args) {
		$snapshot_name = Utils\get_flag_value( $assoc_args, 'name' );
		$hash               = substr( md5( mt_rand() ), 0, 7 );
		$result_dir         = sprintf( 'snapshot-%s-%s', date( 'Y-m-d' ), ! empty( $snapshot_name ) ? $snapshot_name . '-' . $hash : $hash );
		$current_export_sql = WP_CLI::runcommand( 'db export --add-drop-table --porcelain', [ 'return' => true ] );
		$this->config['db_backup'] = $current_export_sql;
	}
}
