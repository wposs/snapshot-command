<?php


namespace WP_CLI\Snapshot;

use WP_CLI\Utils;

class SnapshotDB {

	/**
	 * Hold the DB instance.
	 * @var null
	 */
	private static $dbo = null;

	/**
	 * SnapshotDB constructor.
	 */
	public function __construct() {
		if ( empty( self::$dbo ) ) {
			self::initialize_db();
		}
	}

	/**
	 * Initialize DB object, create db, tables if it doesn't exist, else return db instance.
	 */
	private static function initialize_db() {
		if ( ! defined( 'WP_CLI_SNAPSHOT_DB' ) ) {
			define( 'WP_CLI_SNAPSHOT_DB', Utils\trailingslashit( WP_CLI_SNAPSHOT_DIR ) . 'wp_snapshot.db' );
		}

		if ( ! ( file_exists( WP_CLI_SNAPSHOT_DB ) ) ) {
			self::create_tables();

			return;
		}

		// phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.sqliteRemoved -- False positive.
		self::$dbo = new \SQLite3( WP_CLI_SNAPSHOT_DB );
	}

	/**
	 * Create a table to hold snapshot information.
	 */
	private static function create_tables() {
		// phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.sqliteRemoved -- False positive.
		self::$dbo = new \SQLite3( WP_CLI_SNAPSHOT_DB );
		$query     = 'CREATE TABLE snapshots (
			id INTEGER,
			name VARCHAR,
			created_at DATETIME,
			backup_type INTEGER DEFAULT 0,
			core_version VARCHAR,
			core_type VARCHAR,
			db_size VARCHAR,
			uploads_size VARCHAR,
			PRIMARY KEY (id)
		);';
		self::$dbo->exec( $query );
	}

	/**
	 * Generic method to insert details into the requested table.
	 *
	 * @param string $table Table name.
	 * @param array  $data  Column data.
	 *
	 * @return mixed
	 */
	public function insert( $table, $data ) {
		$fields = implode( ', ', array_keys( $data ) );
		$values = "'" . implode( "','", array_values( $data ) ) . "'";
		$query  = "INSERT INTO $table( $fields ) VALUES ( $values );";

		return self::$dbo->exec( $query );
	}

	/**
	 * Get individual Snapshot information.
	 *
	 * @param int $id Snapshot ID.
	 *
	 * @return array
	 */
	public function get_data( $id = 0 ) {
		if ( empty( $id ) ) {
			$data = [];
			$res  = self::$dbo->query( 'SELECT * FROM snapshots' );
			while ( $row = $res->fetchArray() ) {
				$data[ $row['id'] ]['id']           = $row['id'];
				$data[ $row['id'] ]['name']         = $row['name'];
				$data[ $row['id'] ]['created_at']   = $row['created_at'];
				$data[ $row['id'] ]['backup_type']  = $row['backup_type'];
				$data[ $row['id'] ]['core_version'] = $row['core_version'];
				$data[ $row['id'] ]['core_type']    = $row['core_type'];
				$data[ $row['id'] ]['db_size']      = $row['db_size'];
				$data[ $row['id'] ]['uploads_size'] = $row['uploads_size'];
			}

			return $data;
		} else {
			return self::$dbo->querySingle( "SELECT * FROM snapshots WHERE id = $id", true );
		}
	}

	/**
	 * Get backup info by name, useful for backups with custom name.
	 *
	 * @param string $name Name of the desired backup.
	 *
	 * @return bool
	 */
	public function get_backup_by_name( $name = '' ) {
		if ( ! empty( $name ) ) {
			return self::$dbo->querySingle( "SELECT * FROM snapshots WHERE name LIKE '$name'", true );
		}

		return false;
	}

	/**
	 * Delete backup information from database.
	 *
	 * @param int $id Backup ID.
	 *
	 * @return bool
	 */
	public function delete_backup_by_id( $id ) {
		if ( ! empty( $id ) ) {
			self::$dbo->query( "DELETE FROM snapshots WHERE id = $id" );

			return true;
		}

		return false;
	}
}
