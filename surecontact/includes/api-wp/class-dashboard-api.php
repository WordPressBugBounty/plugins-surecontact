<?php
/**
 * Dashboard API Class
 *
 * REST endpoints for the dashboard's Setup screen.
 *
 * @since 1.6.0
 *
 * @package SureContact\API_WP
 */

namespace SureContact\API_WP;

use SureContact\Dashboard_Overview;
use SureContact\Dashboard_Setup;
use WP_REST_Request;
use WP_REST_Server;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard_API class
 *
 * @since 1.6.0
 */
class Dashboard_API extends Api_Base {

	/**
	 * Instance
	 *
	 * @since 1.6.0
	 *
	 * @var Dashboard_API
	 */
	private static $instance = null;

	/**
	 * Get instance
	 *
	 * @since 1.6.0
	 *
	 * @return Dashboard_API
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
			'/dashboard/setup',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_setup' ),
					'permission_callback' => array( $this, 'validate_permission' ),
				),
			)
		);

		register_rest_route(
			$this->get_api_namespace(),
			'/dashboard/overview',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_overview' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'days' => array(
							'type'    => 'integer',
							'enum'    => Dashboard_Overview::RANGES,
							'default' => 30,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->get_api_namespace(),
			'/dashboard/recent-contacts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_recent_contacts' ),
					'permission_callback' => array( $this, 'validate_permission' ),
				),
			)
		);

		register_rest_route(
			$this->get_api_namespace(),
			'/dashboard/setup/skip',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'skip_setup' ),
					'permission_callback' => array( $this, 'validate_permission' ),
				),
			)
		);
	}

	/**
	 * Current setup state (lets the Setup screen refresh after the user finishes a step).
	 *
	 * @since 1.6.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_setup( $request ) {
		return rest_ensure_response( ( new Dashboard_Setup() )->get_state() );
	}

	/**
	 * Account numbers for the dashboard cards.
	 *
	 * @since 1.6.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_overview( $request ) {
		$overview = ( new Dashboard_Overview() )->get_overview( (int) $request->get_param( 'days' ) );

		return is_wp_error( $overview ) ? $overview : rest_ensure_response( $overview );
	}

	/**
	 * Newest contacts from this site.
	 *
	 * @since 1.6.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_recent_contacts( $request ) {
		$contacts = ( new Dashboard_Overview() )->get_recent_contacts();

		return is_wp_error( $contacts ) ? $contacts : rest_ensure_response( $contacts );
	}

	/**
	 * Skip setup for the current user; the dashboard shows from then on.
	 *
	 * @since 1.6.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function skip_setup( $request ) {
		Dashboard_Setup::skip();

		return rest_ensure_response( ( new Dashboard_Setup() )->get_state() );
	}
}
