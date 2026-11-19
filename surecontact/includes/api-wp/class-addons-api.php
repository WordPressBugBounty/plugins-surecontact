<?php
/**
 * Add-ons API Class
 *
 * REST endpoints behind the Add-ons window (Sales and SMTP): list the add-ons and
 * create a checkout for one.
 *
 * @since 1.6.0
 *
 * @package SureContact\API_WP
 */

namespace SureContact\API_WP;

use SureContact\Auth_Manager;
use SureContact\SaaS_Client;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Addons_API class
 *
 * Security:
 * - Both routes need `manage_options` and a valid REST nonce (validate_permission).
 * - Only add-on slugs from PLAN_SLUGS can be sent. The SaaS checks again, and also checks
 *   that the connected account can manage billing for the site's organization.
 * - The checkout link from the SaaS is only returned if it is an https SureCart checkout
 *   link (/checkout/?checkout_id=...), so the browser is never sent anywhere else.
 * - No magic-login link is involved: the SaaS creates a plain checkout for the organization.
 *
 * @since 1.6.0
 */
class Addons_API extends Api_Base {

	/**
	 * Add-on plan slugs that can be bought from the plugin (same list as the SaaS).
	 *
	 * @since 1.6.0
	 */
	const PLAN_SLUGS = array(
		'sales-addon-monthly',
		'sales-addon-yearly',
		'sales-addon-lifetime',
		'smtp-starter',
		'smtp-growth',
		'smtp-scale',
		'smtp-lifetime-50000',
		'smtp-lifetime-100000',
	);

	/**
	 * SMTP display ids from the public /smtp-plans list, mapped to their plan slugs
	 * (same map as the app's SMTP tab).
	 *
	 * @since 1.6.0
	 */
	const SMTP_DISPLAY_IDS = array(
		'starter_smtp' => 'smtp-starter',
		'growth_smtp'  => 'smtp-growth',
		'scale_smtp'   => 'smtp-scale',
		'credits_50k'  => 'smtp-lifetime-50000',
		'credits_100k' => 'smtp-lifetime-100000',
	);

	/**
	 * Instance
	 *
	 * @since 1.6.0
	 *
	 * @var Addons_API
	 */
	private static $instance = null;

	/**
	 * SaaS client, injectable for tests.
	 *
	 * @since 1.6.0
	 *
	 * @var SaaS_Client|null
	 */
	private $saas_client = null;

	/**
	 * Auth manager, injectable for tests.
	 *
	 * @since 1.6.0
	 *
	 * @var Auth_Manager|null
	 */
	private $auth_manager = null;

	/**
	 * Get instance
	 *
	 * @since 1.6.0
	 *
	 * @return Addons_API
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @since 1.6.0
	 *
	 * @param SaaS_Client|null  $saas_client  Optional SaaS client.
	 * @param Auth_Manager|null $auth_manager Optional auth manager.
	 */
	public function __construct( $saas_client = null, $auth_manager = null ) {
		$this->saas_client  = $saas_client;
		$this->auth_manager = $auth_manager;
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
			'/addons',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_addons' ),
					'permission_callback' => array( $this, 'validate_permission' ),
				),
			)
		);

		register_rest_route(
			$this->get_api_namespace(),
			'/addons/checkout',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_checkout' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'plan_slug' => array(
							'required' => true,
							'type'     => 'string',
							'enum'     => self::PLAN_SLUGS,
						),
					),
				),
			)
		);
	}

	/**
	 * List the add-ons for the connected organization.
	 *
	 * @since 1.6.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function get_addons( $request ) {
		$not_connected = $this->require_connection();
		if ( $not_connected ) {
			return $not_connected;
		}

		$response = $this->get_saas_client()->post( 'wordpress/addons' );
		if ( is_wp_error( $response ) ) {
			return $this->to_rest_error( $response, 'surecontact_addons_unavailable' );
		}

		if ( empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return new WP_Error( 'surecontact_addons_unavailable', __( 'Could not load add-ons. Please try again.', 'surecontact' ), array( 'status' => 502 ) );
		}

		$addons         = $this->sanitize_addons( $response['data'] );
		$addons['smtp'] = $this->get_smtp_plans();

		return rest_ensure_response( $addons );
	}

	/**
	 * SMTP plans with the same details the app's SMTP tab shows, from the public
	 * /smtp-plans list. Called without the site's token, since the list is public.
	 * Checkout links in that list are dropped: Buy always goes through create_checkout().
	 *
	 * @since 1.6.0
	 *
	 * @return array|null Null when the list could not be loaded.
	 */
	private function get_smtp_plans() {
		$response = $this->get_saas_client()->get( 'smtp-plans', array( 'headers' => array( 'Accept' => 'application/json' ) ) );
		if ( is_wp_error( $response ) || ! is_array( $response ) || empty( $response['success'] ) ) {
			return null;
		}

		$text = static function ( $item, $key ) {
			return isset( $item[ $key ] ) && is_scalar( $item[ $key ] ) ? sanitize_text_field( (string) $item[ $key ] ) : '';
		};

		$map = static function ( $items, $is_credit ) use ( $text ) {
			$out = array();
			foreach ( is_array( $items ) ? $items : array() as $item ) {
				$id = is_array( $item ) && isset( $item['id'] ) ? (string) $item['id'] : '';
				if ( ! isset( self::SMTP_DISPLAY_IDS[ $id ] ) ) {
					continue;
				}
				$row = array(
					'slug'          => self::SMTP_DISPLAY_IDS[ $id ],
					'name'          => $text( $item, 'name' ),
					'description'   => $text( $item, 'description' ),
					'currency'      => $text( $item, 'currency' ),
					'price'         => $text( $item, 'price' ),
					'originalPrice' => $text( $item, 'original_price' ),
					'priceSuffix'   => $text( $item, 'price_suffix' ),
					'rateText'      => $text( $item, 'rate_text' ),
					'ctaLabel'      => $text( $item, 'cta_label' ),
				);
				if ( $is_credit ) {
					$row['note']        = $text( $item, 'note' );
					$row['highlighted'] = ! empty( $item['highlighted'] );
				} else {
					$row['emailCount']  = $text( $item, 'email_count' );
					$row['recommended'] = ! empty( $item['recommended'] );
				}
				$out[] = $row;
			}
			return $out;
		};

		return array(
			'plans'   => $map( $response['plans'] ?? array(), false ),
			'credits' => $map( $response['credits']['items'] ?? array(), true ),
		);
	}

	/**
	 * Create a SureCart checkout for an add-on and return its link.
	 *
	 * @since 1.6.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function create_checkout( $request ) {
		$not_connected = $this->require_connection();
		if ( $not_connected ) {
			return $not_connected;
		}

		$plan_slug = (string) $request->get_param( 'plan_slug' );
		if ( ! in_array( $plan_slug, self::PLAN_SLUGS, true ) ) {
			return new WP_Error( 'surecontact_invalid_addon', __( 'This add-on is not available.', 'surecontact' ), array( 'status' => 400 ) );
		}

		$response = $this->get_saas_client()->post( 'wordpress/addons/checkout', array( 'plan_slug' => $plan_slug ) );
		if ( is_wp_error( $response ) ) {
			return $this->to_rest_error( $response, 'surecontact_checkout_failed' );
		}

		$url = isset( $response['data']['checkout_url'] ) ? (string) $response['data']['checkout_url'] : '';
		if ( ! self::is_checkout_url( $url ) ) {
			return new WP_Error( 'surecontact_checkout_failed', __( 'Unable to create checkout link. Please try again.', 'surecontact' ), array( 'status' => 502 ) );
		}

		return rest_ensure_response( array( 'checkout_url' => esc_url_raw( $url ) ) );
	}

	/**
	 * Whether a URL is a SureCart checkout link we can send the browser to:
	 * https, a host, the /checkout/ path and a checkout_id.
	 *
	 * @since 1.6.0
	 *
	 * @param string $url URL from the SaaS.
	 * @return bool
	 */
	public static function is_checkout_url( $url ) {
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( empty( $parts['path'] ) || '/checkout/' !== $parts['path'] || empty( $parts['query'] ) ) {
			return false;
		}

		parse_str( $parts['query'], $query );

		return ! empty( $query['checkout_id'] ) && is_string( $query['checkout_id'] ) && 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $query['checkout_id'] );
	}

	/**
	 * Keep only the fields the Add-ons window uses, with safe types.
	 *
	 * @since 1.6.0
	 *
	 * @param array $data Data from the SaaS.
	 * @return array
	 */
	private function sanitize_addons( array $data ) {
		$plans = static function ( $items ) {
			$out = array();
			foreach ( is_array( $items ) ? $items : array() as $plan ) {
				if ( ! is_array( $plan ) || empty( $plan['slug'] ) || ! in_array( $plan['slug'], self::PLAN_SLUGS, true ) ) {
					continue;
				}
				$out[] = array(
					'slug'         => (string) $plan['slug'],
					'name'         => isset( $plan['name'] ) ? sanitize_text_field( (string) $plan['name'] ) : '',
					'price'        => isset( $plan['price'] ) ? (float) $plan['price'] : 0.0,
					'billingCycle' => isset( $plan['billing_cycle'] ) ? sanitize_key( (string) $plan['billing_cycle'] ) : '',
					'emails'       => isset( $plan['emails'] ) ? absint( $plan['emails'] ) : 0,
				);
			}
			return $out;
		};

		$owned = isset( $data['sales']['owned_slug'] ) ? (string) $data['sales']['owned_slug'] : '';

		return array(
			'canManageBilling' => ! empty( $data['can_manage_billing'] ),
			'sales'            => array(
				'ownedSlug' => in_array( $owned, self::PLAN_SLUGS, true ) ? $owned : null,
				'included'  => ! empty( $data['sales']['included'] ),
				// Sales on the plan but capped; the window then says "go unlimited".
				'hasModule' => ! empty( $data['sales']['has_sales_module'] ),
				'plans'     => $plans( $data['sales']['plans'] ?? array() ),
			),
		);
	}

	/**
	 * Error for sites that aren't connected, or null when connected.
	 *
	 * @since 1.6.0
	 *
	 * @return WP_Error|null
	 */
	private function require_connection() {
		if ( $this->get_auth_manager()->is_authenticated() && $this->get_auth_manager()->get_workspace_uuid() ) {
			return null;
		}

		return new WP_Error( 'surecontact_not_connected', __( 'Connect your site to SureContact first.', 'surecontact' ), array( 'status' => 400 ) );
	}

	/**
	 * Turn a SaaS error into a REST error, keeping the SaaS status and its (already HTML-stripped) message.
	 *
	 * @since 1.6.0
	 *
	 * @param WP_Error $error   SaaS error.
	 * @param string   $code    REST error code.
	 * @return WP_Error
	 */
	private function to_rest_error( WP_Error $error, $code ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && ! empty( $data['code'] ) ? (int) $data['code'] : 502;
		if ( $status < 400 || $status > 599 ) {
			$status = 502;
		}

		return new WP_Error( $code, $error->get_error_message(), array( 'status' => $status ) );
	}

	/**
	 * SaaS client.
	 *
	 * @since 1.6.0
	 *
	 * @return SaaS_Client
	 */
	private function get_saas_client() {
		if ( null === $this->saas_client ) {
			$this->saas_client = new SaaS_Client( $this->get_auth_manager() );
		}
		return $this->saas_client;
	}

	/**
	 * Auth manager.
	 *
	 * @since 1.6.0
	 *
	 * @return Auth_Manager
	 */
	private function get_auth_manager() {
		if ( null === $this->auth_manager ) {
			$this->auth_manager = new Auth_Manager();
		}
		return $this->auth_manager;
	}
}
