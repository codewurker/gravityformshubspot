<?php

/**
 * Gravity Forms HubSpot API Library.
 *
 * @since     1.0
 * @package   GravityForms
 * @author    Rocketgenius
 * @copyright Copyright (c) 2019, Rocketgenius
 */
class GF_HubSpot_API {

	/**
	 * HubSpot API URL.
	 *
	 * @since  1.0
	 * @var    string $api_url HubSpot API URL.
	 */
	protected $api_url = 'https://api.hubapi.com/';

	/**
	 * HubSpot authentication data.
	 *
	 * @since  1.0
	 * @var    array $auth_data HubSpot authentication data.
	 */
	protected $auth_data = null;

	/**
	 * Initialize API library.
	 *
	 * @since  1.0
	 *
	 * @param  array $auth_data HubSpot authentication data.
	 */
	public function __construct( $auth_data = null ) {
		$this->auth_data = $auth_data;
	}

	/**
	 * Make API request.
	 *
	 * @since  1.0
	 *
	 * @param string    $path          Request path.
	 * @param array     $options       Request options.
	 * @param string    $method        Request method. Defaults to GET.
	 * @param string    $return_key    Array key from response to return. Defaults to null (return full response).
	 * @param int|array $response_code Expected HTTP response code.
	 *
	 * @return array|WP_Error
	 */
	public function make_request( $path = '', $options = array(), $method = 'GET', $return_key = null, $response_code = 200 ) {

		// Log API call succeed.
		gf_hspot()->log_debug( __METHOD__ . '(): Making request to: ' . $path );

		// Get authentication data.
		$auth_data = $this->auth_data;

		// Build request URL.
		if ( $path === 'token/revoke' ) {
			$request_url = 'https://api.hubapi.com/oauth/v1/refresh-tokens/' . $options['token'];

			// Execute request.
			$response = wp_remote_request( $request_url, array( 'method' => $method ) );
		} else {
			$request_url = strpos( $path, 'https://' ) === 0 ? $path : $this->api_url . $path;

			// Add options if this is a GET request.
			if ( 'GET' === $method ) {
				$request_url = add_query_arg( $options, $request_url );
			}

			// Prepare request arguments.
			$args = array(
				'method'  => $method,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $auth_data['access_token'],
					'Content-Type'  => 'application/json',
				),
			);

			// Add request arguments to body.
			if ( in_array( $method, array( 'POST', 'PUT' ) ) ) {
				$args['body'] = json_encode( $options );
			}

			// Execute API request.
			$response = wp_remote_request( $request_url, $args );
		}

		if ( is_wp_error( $response ) ) {
			gf_hspot()->log_error( __METHOD__ . '(): HTTP request failed; ' . $response->get_error_message() );

			return $response;
		}

		// If an incorrect response code was returned, return WP_Error.
		$retrieved_response_code = wp_remote_retrieve_response_code( $response );
		if ( is_int( $response_code ) ) {
			$response_code = array( $response_code );
		}
		if ( ! in_array( $retrieved_response_code, $response_code, true ) ) {
			$response_code = implode( ', ', $response_code );
			$error_message = "Expected response code: {$response_code}. Returned response code: {$retrieved_response_code}.";
			$json_body     = gf_hspot()->maybe_decode_json( $response['body'] );

			$error_data = array( 'status' => $retrieved_response_code );
			if ( ! rgempty( 'message', $json_body ) ) {
				$error_message = $json_body['message'];
			}
			if ( ! rgempty( rgars( $json_body, 'errors' ) ) ) {
				$error_data['data'] = rgars( $json_body, 'errors' );
			}

			// 401 Unauthorized - Returned when the authentication provided is invalid.
			if ( $retrieved_response_code === 401 ) {
				$log = 'API credentials are invalid;';
			} else {
				$log = 'API errors returned;';
			}

			gf_hspot()->log_error( __METHOD__ . "(): $log " . $error_message . '; error data: ' . print_r( $error_data, true ) );

			return new WP_Error( 'hubspot_api_error', $error_message, $error_data );
		}

		// Convert JSON response to array.
		$response = gf_hspot()->maybe_decode_json( $response['body'] );

		// If a return key is defined and array item exists, return it.
		if ( ! empty( $return_key ) && rgar( $response, $return_key ) ) {
			return rgar( $response, $return_key );
		}

		return $response;

	}

	/**
	 * Refresh access tokens.
	 *
	 * @since 1.0
	 *
	 * @return array|WP_Error
	 */
	public function refresh_token() {
		// Get authentication data.
		$auth_data = $this->auth_data;

		// If refresh token is not provided, throw exception.
		if ( ! rgar( $auth_data, 'refresh_token' ) ) {
			return new WP_Error( 'hubspot_refresh_token_error', esc_html__( 'Refresh token must be provided.', 'gravityformshubspot' ) );
		}

		$args = array(
			'body' => array(
				'refresh_token' => $auth_data['refresh_token'],
				'state'         => wp_create_nonce( gf_hspot()->get_authentication_state_action() ),
			),
		);

		$response      = wp_remote_post( gf_hspot()->get_gravity_api_url( '/auth/hubspot/refresh' ), $args );
		$response_code = wp_remote_retrieve_response_code( $response );
		$message       = wp_remote_retrieve_response_message( $response );

		if ( $response_code === 200 ) {
			$auth_payload = json_decode( wp_remote_retrieve_body( $response ), true );
			$auth_payload = json_decode( $auth_payload['auth_payload'], true );

			if ( isset( $auth_payload['access_token'] ) && wp_verify_nonce( $auth_payload['state'], gf_hspot()->get_authentication_state_action() ) ) {
				$auth_data['access_token']  = $auth_payload['access_token'];
				$auth_data['refresh_token'] = $auth_payload['refresh_token'];
				$auth_data['expires_in']    = $auth_payload['expires_in'];

				$this->auth_data = $auth_data;

				return $auth_data;
			}

			if ( isset( $auth_payload['error'] ) ) {
				$message = $auth_payload['error'];
			} elseif ( isset( $auth_payload['status'] ) ) {
				$message = $auth_payload['status'];
			}

		}

		return new WP_Error( 'hubspot_refresh_token_error', $message, array( 'status' => $response_code ) );
	}

	/**
	 * Revoke authentication token.
	 *
	 * @since  1.0
	 *
	 * @return array|WP_Error
	 */
	public function revoke_token() {

		// Get authentication data.
		$auth_data = $this->auth_data;

		// If refresh token is not provided, throw exception.
		if ( ! rgar( $auth_data, 'refresh_token' ) ) {
			return new WP_Error( 'hubspot_revoke_token_error', esc_html__( 'Refresh token must be provided.', 'gravityformshubspot' ) );
		}

		return $this->make_request( 'token/revoke', array( 'token' => $auth_data['refresh_token'] ), 'DELETE', null, 204 );

	}

	/**
	 * Get available users.
	 *
	 * @since  1.0
	 *
	 * @return array|WP_Error
	 */
	public function get_contacts() {
		static $contacts;

		if ( ! isset( $contacts ) ) {
			$contacts = $this->make_request( 'contacts/v1/lists/all/contacts/all', array(), 'GET', 'users' );
		}

		return $contacts;
	}

	/**
	 * Get contact properties.
	 *
	 * @since 1.0
	 *
	 * @return array|WP_Error
	 */
	public function get_contact_properties() {

		$properties = $this->make_request( 'properties/v1/contacts/groups/?includeProperties=true', array(), 'GET' );

		return $properties;
	}

	/**
	 * Update contact properties by email.
	 *
	 * @since 1.0
	 *
	 * @param string $email Email.
	 * @param array  $data Contact data.
	 *
	 * @return array|WP_Error
	 */
	public function update_contact_by_email( $email, $data ) {
		return $this->make_request( "contacts/v1/contact/createOrUpdate/email/{$email}/", $data, 'POST' );
	}

	/**
	 * Create a new form.
	 *
	 * @since 1.0
	 *
	 * @param array $form The form options array.
	 *
	 * @return array|WP_Error
	 */
	public function create_form( $form ) {
		return $this->make_request( 'forms/v2/forms', $form, 'POST' );
	}

	/**
	 * Get form by guid.
	 *
	 * @since 1.0
	 *
	 * @param string $guid GUID of the form.
	 *
	 * @return array|WP_Error
	 */
	public function get_form( $guid ) {
		return $this->make_request( "forms/v2/forms/{$guid}" );
	}

	/**
	 * Get all forms.
	 *
	 * @since 1.0
	 *
	 * @return array|WP_Error Returns an array of forms
	 */
	public function get_forms() {
		return $this->make_request( 'forms/v2/forms' );
	}

	/**
	 * Update the form.
	 *
	 * @since 1.0
	 *
	 * @param string $guid GUID of the form.
	 * @param array  $form The form options array.
	 *
	 * @return array|WP_Error
	 */
	public function update_form( $guid, $form ) {

		gf_hspot()->log_debug( 'Updating Form. GUID: ' . $guid );
		gf_hspot()->log_debug( 'Payload: ' . print_r( $form, true ) );

		return $this->make_request( "forms/v2/forms/{$guid}", $form, 'POST' );
	}

	/**
	 * Delete form.
	 *
	 * @since 1.0
	 *
	 * @param string $guid GUID of the form.
	 *
	 * @return array|WP_Error
	 */
	public function delete_form( $guid ) {
		return $this->make_request( "forms/v2/forms/{$guid}", array(), 'DELETE', null, 204 );
	}

	/**
	 * Get contact owners from HubSpot.
	 *
	 * @since 1.0
	 * @since 2.1 Updated to use the v3 endpoint.
	 *
	 * @return array|WP_Error
	 */
	public function get_owners() {
		return $this->make_request( 'crm/v3/owners', array( 'limit' => 500 ), 'GET', 'results' );
	}

	/**
	 * Submit form data to HubSpot.
	 *
	 * @since 1.0
	 *
	 * @param string $portal_id HubSpot portal ID.
	 * @param string $form_guid HubSpot form GUID.
	 * @param array  $submission Form submission data.
	 *
	 * @return array|WP_Error
	 */
	public function submit_form( $portal_id, $form_guid, $submission ) {

		// Submit HubSpot form.
		$url = "https://api.hsforms.com/submissions/v3/integration/submit/{$portal_id}/{$form_guid}";

		gf_hspot()->log_debug( 'Submitting Form. URL:' . $url );
		gf_hspot()->log_debug( 'Payload: ' . print_r( $submission, true ) );

		return $this->make_request( $url, $submission, 'POST' );
	}

}
