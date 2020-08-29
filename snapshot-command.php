<?php

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

$wpcli_snapshot_autoloader = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $wpcli_snapshot_autoloader ) ) {
	require_once $wpcli_snapshot_autoloader;
}

$wpcli_snapshot_verify_required_packages = function() {
	$utils = new \WP_CLI\Snapshot\Utils();
	$utils->available_wp_packages();
};

WP_CLI::add_command(
	'snapshot',
	'\WP_CLI\Snapshot\SnapshotCommand',
	[
		'before_invoke' => $wpcli_snapshot_verify_required_packages,
	]
);
