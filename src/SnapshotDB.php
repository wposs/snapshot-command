<?php


namespace WP_CLI\Snapshot;

use WP_CLI\Utils;
use WP_CLI\Iterators\Exception;

class SnapshotDB {

    // Hold the db object.
    private static $dbo = null;

    public function __construct() {
        if ( empty( self::$dbo ) ) {
            self::initialize_db();
        }
    }

    private static function initialize_db() {
        if ( ! defined( 'SNAPSHOT_DB' ) ) {
            define( 'SNAPSHOT_DB', Utils\trailingslashit( SNAPSHOT_DIR ) . 'wp_snapshot.db' );
        }

        if ( ! ( file_exists( SNAPSHOT_DB ) ) ) {
            self::create_tables();

            return;
        }

        self::$dbo = new \SQLite3( SNAPSHOT_DB );
    }

    private static function create_tables() {
        self::$dbo = new \SQLite3( SNAPSHOT_DB );
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

    public function insert( $table, $data ) {
        $fields = implode( ', ', array_keys( $data ) );
        $values = "'" . implode( "','", array_values( $data ) ) . "'";
        $query  = "INSERT INTO $table( $fields ) VALUES ( $values );";

        return self::$dbo->exec( $query );
    }

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

    public function get_backup_by_name( $name = '' ) {
        if ( ! empty( $name ) ) {
            return self::$dbo->querySingle( "SELECT * FROM snapshots WHERE name LIKE '$name'", true );
        }

        return false;
    }
}
