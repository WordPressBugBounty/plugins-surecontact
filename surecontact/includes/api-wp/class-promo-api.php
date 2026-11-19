<?php
/**
 * Promo API Class
 *
 * REST endpoint for dismissing BFCM sale surfaces.
 *
 * @since 1.6.0
 *
 * @package SureContact\API_WP
 */

namespace SureContact\API_WP;

use SureContact\Promo_Service;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Promo_API class
 *
 * @since 1.6.0
 */
class Promo_API extends Api_Base {

	/**
	 * Instance
	 *
	 * @since 1.6.0
	 *
	 * @var Promo_API
	 */
	private static $instance = null;

	/**
	 * Get instance
	 *
	 * @since 1.6.0
	 *
	 * @return Promo_API
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register routes
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->get_api_namespace(),
			'/promo/dismiss',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'dismiss' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'surface' => array(
							'required' => true,
							'type'     => 'string',
							'enum'     => Promo_Service::DISMISSIBLE_SURFACES,
						),
					),
				),
			)
		);
	}

	/**
	 * Remember that the current user dismissed a sale surface.
	 *
	 * @since 1.6.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function dismiss( $request ) {
		$service = new Promo_Service();
		$surface = (string) $request->get_param( 'surface' );

		if ( ! $service->dismiss( $surface ) ) {
			return new WP_Error(
				'surecontact_invalid_surface',
				__( 'Unknown promo surface.', 'surecontact' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( array( 'success' => true ) );
	}
}
