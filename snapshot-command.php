<?php
if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}
$wpcli_snapshot_autoloader = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $wpcli_snapshot_autoloader ) ) {
	require_once $wpcli_snapshot_autoloader;
}
WP_CLI::add_command( 'snapshot', '\WP_CLI\Snapshot\SnapshotCommand' );
