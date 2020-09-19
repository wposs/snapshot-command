<?php


namespace WP_CLI\Snapshot;

use Aws\Sdk;

class SnapshotStorage {

	/**
	 * Store all required data for third party service.
	 *
	 * @var array
	 */
	protected static $services_info = [];

	/**
	 * Instance of an s3 client.
	 *
	 * @var \Aws\S3\S3Client
	 */
	protected $s3_instance;

	/**
	 * SnapshotStorage constructor.
	 */
	public function __construct() {
		$this->db            = new SnapshotDB();
		$registered_services = array_unique( $this->db->get_registered_services() );
		foreach ( $registered_services as $service ) {
			$service_details = $this->db->get_storage_service_info( $service );
			foreach ( $service_details as $service_detail ) {
				self::$services_info[ $service ][ $service_detail['info_key'] ] = $service_detail['info_value'];
			}
		}
	}

	/**
	 * Get formatted storage service info.
	 *
	 * @param string $service Service key.
	 *
	 * @return array|mixed
	 */
	public function get_storage_service_info( $service ) {
		if ( ! empty( self::$services_info[ $service ] ) ) {
			return self::$services_info[ $service ];
		}

		return [];
	}

	/**
	 * Initialize the s3 client for backup uploads.
	 *
	 * @param array $data Required data to initialize an s3 instance.
	 */
	public function initialize_s3( $data ) {
		$s3_config          = [
			'region'      => $data['region'],
			'version'     => 'latest',
			'credentials' => [
				'key'    => $data['key'],
				'secret' => $data['secret'],
			],
		];
		$sdk               = new Sdk( $s3_config );
		$this->s3_instance = $sdk->createS3();
	}

	/**
	 * Push the backup file to requested s3 object.
	 *
	 * @param array $backup_info Bucket name and path to file.
	 *
	 * @return bool
	 */
	public function push_to_s3_bucket( $backup_info ) {
		$result = $this->s3_instance->putObject(
			[
				'Bucket'     => $backup_info['bucket_name'],
				'Key'        => \WP_CLI\Utils\basename( $backup_info['backup_path'] ),
				'SourceFile' => $backup_info['backup_path'],
				'@http'      => [
					'progress' => function ( $download_total_size, $download_size_so_far, $upload_total_size, $upload_size_so_far ) {
						if ( ! empty( $upload_total_size ) ) {
							$progress_percentage = number_format( ( $upload_size_so_far / $upload_total_size ) * 100, 2 ) . '%';
							\WP_CLI::log( "Upload Progress : {$progress_percentage}" . PHP_EOL );
						}
					},
				],
			]
		);

		/**
		 * Verify upload success and check if we have an s3 URL after upload.
		 */
		if ( ! empty( $result ) && ! empty( $result['ObjectURL'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Function to pull snapshot.
	 *
	 * @param array $backup_info Backup details.
	 *
	 * @return bool
	 */
	public function pull_snapshot( $backup_info ) {

		try {
			$result = $this->s3_instance->getObject(
				[
					'Bucket'     => $backup_info['bucket_name'],
					'Key'        => \WP_CLI\Utils\basename( $backup_info['backup_path'] ),
					'SaveAs'     => $backup_info['backup_path'],
					'@http'      => [
						'progress' => function ( $download_total_size, $download_size_so_far, $upload_total_size, $upload_size_so_far ) {
							if ( ! empty( $upload_total_size ) ) {
								$progress_percentage = number_format( ( $upload_size_so_far / $upload_total_size ) * 100, 2 ) . '%';
								\WP_CLI::log( "Upload Progress : {$progress_percentage}" . PHP_EOL );
							}
						},
					],
				]
			);
			return true;
		} catch ( S3Exception $e ) {
			\WP_CLI::log( $e->getMessage() );
			return false;
		}
	}
}
