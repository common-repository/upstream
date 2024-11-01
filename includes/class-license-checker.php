<?php
/**
 * The UpStream extensions license checker.
 *
 * @package UpStream
 */

namespace UpStream;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class License_Checker
 * 
 * @since 2.0.9
 */
class License_Checker {

	/**
     * Constant for plugin name.
	 * 
	 * @var String
     */
    const PLUGIN_NAME = 'upstream';

	/**
     * Constant for valid status.
	 * 
	 * @var String
     */
    const LICENSE_STATUS_VALID = 'valid';

	/**
	 * License registration page.
	 * 
	 * @var String
	 */
	const LICENSE_REGISTRATION_PAGE = 'admin.php?page=upstream_extensions';

	/**
	 * The url to send the EDD license check request.
	 * 
	 * @var String
	 */
	const EDD_API_URL = 'https://upstreamplugin.com';

	/**
	 * Periodically days to check.
	 * 
	 * @var Integer
	 */
	const DAYS_TO_CHECK = 15;

	/**
	 * The EDD id of the addon to check.
	 * 
	 * @var String
	 */
	public $addon_edd_id;
	
	/**
	 * The addon slug to check.
	 * 
	 * @var String
	 */
	public $addon_slug;

	/**
	 * The wp_options option name for the addon.
	 * 
	 * @var String
	 */
	public $option_name;

	/**
	 * Class constructor.
	 * 
	 * @since 2.0.9
	 * 
	 * @param String $addon_edd_id The EDD id of the addon to check.
	 * @param String $addon_slug   The addon name to check.
	 */
	public function __construct( $addon_edd_id, $addon_slug ) {
		$this->addon_edd_id = $addon_edd_id;
		$this->addon_slug   = $addon_slug;
		$this->option_name  = str_replace( '-', '_', $this->addon_slug );
	}

	/**
	 * Get addon license key from database.
	 * 
	 * @since  2.0.9
	 * @return String License key.
	 */
	private function get_license_key() {
		return get_option( $this->option_name . '_license_key', '' );
	}

	/**
	 * Get addon license status from database.
	 * 
	 * @since  2.0.9
	 * @return String License status.
	 */
	private function get_license_status() {
		return get_option( $this->option_name . '_license_status', 'missing' );		
	}

	/**
	 * Update addon license status to database.
	 * 
	 * @since  2.0.9
	 */
	private function update_license_status( $status ) {
		update_option( $this->option_name . '_license_status', $status, true);		
	}

	/**
	 * Update when the last time a license has been checked.
	 * 
	 * @since  2.0.9
	 */
	private function update_license_checked_date() {
		update_option( $this->option_name . '_license_checked', date( 'Y-m-d' ), true );		
	}

	/**
	 * Update license notification message.
	 * 
	 * @since 2.0.9
	 * @param String $message Notification message to store.
	 */
	private function update_license_message( $message ) {
		update_option( $this->option_name . '_license_message', $message, true );		
	}

	/**
	 * Get default error notification message.
	 * 
	 * @since  2.0.9
	 * @return String Notification message.
	 */
	private function get_default_error_message() {
		$addon_name = ucwords( str_replace( '-', ' ', $this->addon_slug ) );

		return sprintf( 
			__( 'The license for %s is not valid. Please %sregister your license key here.%s', 'upstream' ), 
			$addon_name,
			'<a href="' . admin_url( self::LICENSE_REGISTRATION_PAGE ) . '">',
			'</a>'
		);
	}

	/**
	 * Get the invalid license notification message.
	 * 
	 * @since  2.0.9
	 * @return String|Boolean Notification message or false if we have the valid license.
	 */
	public function get_invalid_license_message() {
		$license_status = $this->get_license_status();

		if ( self::LICENSE_STATUS_VALID !== $license_status ) {
			return get_option( $this->option_name . '_license_message', $this->get_default_error_message() );		
		}

		return false;
	}

	/**
	 * Check whether the addon's turn to check or not.
	 * 
	 * @since  2.0.9
	 * @return Boolean True if it is the addon's turn to check.
	 */
	public function is_turn_to_check() {
		$date_checked  = get_option( $this->option_name . '_license_checked', date( 'Y-m-d', strtotime( '-' . self::DAYS_TO_CHECK . ' days' ) ) );
		$times_between = time() - strtotime( $date_checked );
		$days_between  = round( $times_between / (60 * 60 * 24) );

		if ( $days_between >= self::DAYS_TO_CHECK ) {
			return true;
		}

		return false;
	}

	/**
	 * Check the current addon license.
	 * Use the `get_invalid_license_message` method to get this license_check result.
	 * 
	 * @since  2.0.9
	 */
	public function license_check() {
		try {
			$addon_name     = ucwords( str_replace( '-', ' ', $this->addon_slug ) );
			$license_status = $this->get_license_status();
			$license_key    = $this->get_license_key();

			// Update when the last time a license has been checked.
			$this->update_license_checked_date();

			// Check the license key from the database.
			if ( '' === $license_key ) {
				throw new Exception(
					sprintf( 
						__( 'You haven\'t registered the license key for %s. Please %sregister your license key here.%s', 'upstream' ), 
						$addon_name,
						'<a href="' . admin_url( self::LICENSE_REGISTRATION_PAGE ) . '">',
						'</a>'
					)
				);
			}

			// Check the current status from the database.
			if ( self::LICENSE_STATUS_VALID !== $license_status ) {
				throw new Exception( $this->get_default_error_message() );
			}

			// Make the request.
			$edd_response = wp_remote_post(
				self::EDD_API_URL,
				array(
					'timeout'   => 30,
					'sslverify' => true,
					'body'      => array(
						'edd_action' => 'check_license',
						'license'    => $license_key,
						'item_id'    => $this->addon_edd_id,
						'url'        => site_url(),
					),
				)
			);

			// Is the response an error?
			if ( is_wp_error( $edd_response ) || 200 !== wp_remote_retrieve_response_code( $edd_response ) ) {
				throw new Exception(
					sprintf( 
						__( 'An error occurred when checking the license for %s. (invalid_response)', 'upstream' ), 
						$addon_name,
					)
				);
			}

			// Convert data response to an object.
			$data = json_decode( wp_remote_retrieve_body( $edd_response ) );

			// Do we have empty data?
			if ( empty( $data ) || ! is_object( $data ) ) {
				throw new Exception(
					sprintf( 
						__( 'An error occurred when checking the license for %s. (invalid_data)', 'upstream' ), 
						$addon_name,
					)
				);
			}
			
			// Deal with invalid licenses.
			if ( isset( $data->license ) && self::LICENSE_STATUS_VALID !== $data->license ) {
				$this->update_license_status( $data->license );
                throw new Exception( $this->get_default_error_message() );
            }
		} catch ( Exception $e ) {
			$message = $e->getMessage();
			$this->update_license_message( $message );
		}
	}
}