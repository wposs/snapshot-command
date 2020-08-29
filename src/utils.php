<?php

namespace WP_CLI\Snapshot;

use WP_CLI;

class Utils {

	/**
	 * Installed packages list.
	 *
	 * @var array
	 */
	protected $installed_packages = [];

	/**
	 * Required packages list.
	 *
	 * @var array
	 */
	protected $required_packages = [
		'wp-cli/checksum-command' => 'wp package install git@github.com:wp-cli/checksum-command.git',
	];

	/**
	 * Missing packages list.
	 *
	 * @var array
	 */
	protected $missing_packages = [];

	/**
	 * Check available packages list.
	 *
	 * @return void
	 */
	public function available_wp_packages() {
		if ( empty( $this->installed_packages ) ) {
			$this->get_packages_list();
		}

		$this->check_missing_packages();
	}

	/**
	 * Get packages list.
	 *
	 * @return void
	 */
	private function get_packages_list() {
		$packages = WP_CLI::runcommand( 'package list --format=json --fields=name', [ 'return' => 'all' ] );

		if ( empty( $packages->stdout ) ) {
			return;
		}

		$packages_raw_list = json_decode( $packages->stdout, true );

		if ( ! is_array( $packages_raw_list ) ) {
			return;
		}

		foreach ( $packages_raw_list as $package ) {
			$this->installed_packages[] = $package['name'];
		}
	}

	/**
	 * Check missing packages.
	 *
	 * @return void
	 */
	private function check_missing_packages() {
		foreach ( $this->required_packages as $package_name => $installation_command ) {
			if ( in_array( $package_name, $this->installed_packages, true ) ) {
				continue;
			}

			$this->missing_packages[ $package_name ] = $installation_command;
		}

		if ( empty( $this->missing_packages ) ) {
			return;
		}

		$this->show_missing_packages_info();
	}

	/**
	 * Show missing packages error.
	 *
	 * @return void
	 */
	private function show_missing_packages_info() {
		foreach ( $this->missing_packages as $package_name => $installation_command ) {
			WP_CLI::warning( "Missing '{$package_name}' package. Try '{$installation_command}'." );
		}

		WP_CLI::error( 'Snapshot command requires above packages to be installed.' );
	}

}
