<?php

namespace WP_CLI\Snapshot;

use WP_CLI;

class Utils {
	/**
	 * Installed packages list.
	 *
	 * @var array
	 */
	protected static $installed_packages = [];

	/**
	 * Required packages list.
	 *
	 * @var array
	 */
	protected static $required_packages = [
		'wp-cli/checksum-command' => 'wp package install git@github.com:wp-cli/checksum-command.git',
	];

	/**
	 * Missing packages list.
	 *
	 * @var array
	 */
	protected static $missing_packages = [];

	/**
	 * Check available packages list.
	 *
	 * @return void
	 */
	public static function available_wp_packages() {
		if ( empty( self::$installed_packages ) ) {
			self::get_packages_list();
		}

		self::check_missing_packages();
	}

	/**
	 * Get packages list.
	 *
	 * @return void
	 */
	private static function get_packages_list() {
		$packages = WP_CLI::runcommand( 'package list --format=json --fields=name', [ 'return' => 'all' ] );

		if ( empty( $packages->stdout ) ) {
			return;
		}

		$packages_raw_list = json_decode( $packages->stdout, true );

		if ( ! is_array( $packages_raw_list ) ) {
			return;
		}

		foreach ( $packages_raw_list as $package ) {
			self::$installed_packages[] = $package['name'];
		}
	}

	/**
	 * Check missing packages.
	 *
	 * @return void
	 */
	private static function check_missing_packages() {
		foreach ( self::$required_packages as $package_name => $installation_command ) {
			if ( in_array( $package_name, self::$installed_packages, true ) ) {
				continue;
			}

			self::$missing_packages[ $package_name ] = $installation_command;
		}

		if ( empty( self::$missing_packages ) ) {
			return;
		}

		self::show_missing_packages_info();
	}

	/**
	 * Show missing packages error.
	 *
	 * @return void
	 */
	private static function show_missing_packages_info() {
		foreach ( self::$missing_packages as $package_name => $installation_command ) {
			WP_CLI::warning( "Missing '{$package_name}' package. Try '{$installation_command}'." );
		}

		WP_CLI::error( 'Snapshot command requires above packages to be installed.' );
	}

	/**
	 * Get file size in bytes.
	 *
	 * @param string $file_path File path.
	 *
	 * @return int
	 */
	public static function size_in_bytes( $file_path ) {
		if ( empty( $file_path ) ) {
			return 0;
		}

		return doubleval( shell_exec( 'du -sk ' . escapeshellarg( $file_path ) ) ) * 1024;
	}

}
