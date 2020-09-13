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

		// Create snapshots table.
		$snapshots_table_query = 'CREATE TABLE IF NOT EXISTS snapshots (
			id INTEGER,
			name VARCHAR,
			created_at DATETIME,
			backup_type INTEGER DEFAULT 0,
			backup_zip_size VARCHAR,
			PRIMARY KEY (id)
		);';
		self::$dbo->exec( $snapshots_table_query );

		// Create snapshot_extra_info table.
		$extra_info_table_query = 'CREATE TABLE IF NOT EXISTS snapshot_extra_info (
			id INTEGER,
			info_key VARCHAR,
			info_value VARCHAR,
			snapshot_id INTEGER,
			PRIMARY KEY (id)
		);';
		self::$dbo->exec( $extra_info_table_query );

		// Create storage_credentials table.
		$storage_credentials_table_query = 'CREATE TABLE IF NOT EXISTS snapshot_storage_credentials (
			id INTEGER,
			storage_service VARCHAR,
			info_key VARCHAR,
			info_value VARCHAR,
			PRIMARY KEY (id)
		);';
		self::$dbo->exec( $storage_credentials_table_query );

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
		self::$dbo->exec( $query );

		return self::$dbo->lastInsertRowid();
	}

	/**
	 * Method to update details in the service storage table.
	 *
	 * @param array $data Column data.
	 *
	 * @return mixed
	 */
	public function update_service_configuration( $data ) {
		$table = 'snapshot_storage_credentials';

		$does_service_config_exist = $this->select(
			$table,
			'*',
			[
				'storage_service' => $data['storage_service'],
				'info_key'        => $data['info_key'],
			]
		);

		if ( empty( $does_service_config_exist ) ) {
			return $this->insert( $table, $data );
		} else {
			$fields = '';
			foreach ( $data as $field_name => $value ) {
				if ( ! empty( $fields ) ) {
					$fields .= ',';
				}
				$fields .= "$field_name='$value'";
			}

			$query = "UPDATE $table SET $fields WHERE id={$does_service_config_exist['id']};";
			self::$dbo->exec( $query );
		}

		return self::$dbo->lastInsertRowid();
	}

	/**
	 * Select data from database.
	 *
	 * @param string $table  Table name.
	 * @param mixed  $fields List of fields to fetch.
	 * @param array  $where  Where condition.
	 * @return void
	 */
	public function select( $table, $fields = '*', $where ) {

		if ( is_array( $fields ) ) {
			$fields = implode( ', ', $fields );
		}

		$where_condition = '';
		foreach ( $where as $field => $value ) {
			if ( empty( $where_condition ) ) {
				$where_condition = 'WHERE';
			} else {
				$where_condition .= ' AND ';
			}

			$where_condition .= " $field='$value'";
		}

		$query  = "SELECT $fields FROM $table $where_condition;";
		return self::$dbo->querySingle( $query, true );
	}

	/**
	 * Get individual or all snapshots information.
	 *
	 * @param int $id Snapshot ID.
	 *
	 * @return array
	 */
	public function get_snapshot_data( $id = 0 ) {
		if ( empty( $id ) ) {
			$data = [];
			$res  = self::$dbo->query( 'SELECT * FROM snapshots' );
			while ( $row = $res->fetchArray() ) {
				$data[ $row['id'] ]['id']              = $row['id'];
				$data[ $row['id'] ]['name']            = $row['name'];
				$data[ $row['id'] ]['created_at']      = $row['created_at'];
				$data[ $row['id'] ]['backup_type']     = $row['backup_type'];
				$data[ $row['id'] ]['backup_zip_size'] = $row['backup_zip_size'];
			}

			return $data;
		} else {
			return self::$dbo->querySingle( "SELECT * FROM snapshots WHERE id = $id", true );
		}
	}

	/**
	 * Get extra information on the given snapshot.
	 *
	 * @param int $snapshot_id Snapshot ID.
	 *
	 * @return mixed
	 */
	public function get_extra_snapshot_info( $snapshot_id ) {
		if ( ! empty( $snapshot_id ) ) {
			$data   = [];
			$result = self::$dbo->query( "SELECT * FROM snapshot_extra_info WHERE snapshot_id = $snapshot_id" );
			while ( $row = $result->fetchArray() ) {
				$data[ $row['info_key'] ] = $row['info_value'];
			}

			return $data;
		}

		return false;
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
			self::$dbo->query( "DELETE FROM snapshot_extra_info WHERE snapshot_id = $id" );

			return true;
		}

		return false;
	}

	/**
	 * Get third party storage info.
	 *
	 * @param string $service Service provider name.
	 *
	 * @return array|bool
	 */
	public function get_storage_service_info( $service ) {
		if ( ! empty( $service ) ) {
			$data = [];
			$res  = self::$dbo->query( "SELECT * FROM snapshot_storage_credentials WHERE storage_service LIKE '$service'" );
			while ( $row = $res->fetchArray() ) {
				$data[ $row['id'] ]['id']              = $row['id'];
				$data[ $row['id'] ]['storage_service'] = $row['storage_service'];
				$data[ $row['id'] ]['info_key']        = $row['info_key'];
				$data[ $row['id'] ]['info_value']      = $row['info_value'];
			}

			return $data;
		}

		return false;
	}

	/**
	 * Get all registered third party info required for storing backup files.
	 *
	 * @return array
	 */
	public function get_registered_services() {
		$data = [];
		$res  = self::$dbo->query( 'SELECT storage_service FROM snapshot_storage_credentials' );
		while ( $row = $res->fetchArray() ) {
			$data[] = $row['storage_service'];
		}

		return $data;
	}

}
