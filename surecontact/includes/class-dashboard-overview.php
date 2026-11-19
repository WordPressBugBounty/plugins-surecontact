<?php
/**
 * Dashboard Overview
 *
 * Account numbers for the dashboard's cards, from the SaaS.
 *
 * @since 1.6.0
 *
 * @package SureContact
 */

namespace SureContact;

use WP_Error;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard_Overview class
 *
 * Calls POST wordpress/overview and wordpress/recent-contacts on the SaaS (scoped to the
 * connected workspace there), caches the answers briefly so the WP dashboard stays fast,
 * and passes on only known fields with safe types.
 *
 * @since 1.6.0
 */
class Dashboard_Overview {

	/**
	 * Ranges the SaaS accepts, in days.
	 *
	 * @since 1.6.0
	 */
	const RANGES = array( 7, 30, 90 );

	/**
	 * Cache key prefix (per workspace and range).
	 *
	 * @since 1.6.0
	 */
	const CACHE_PREFIX = 'surecontact_dash_overview_';

	/**
	 * Recent contacts cache key prefix (per workspace).
	 *
	 * @since 1.6.0
	 */
	const RECENT_CACHE_PREFIX = 'surecontact_dash_recent_';

	/**
	 * SaaS client.
	 *
	 * @since 1.6.0
	 *
	 * @var SaaS_Client|null
	 */
	private $saas_client;

	/**
	 * Auth manager.
	 *
	 * @since 1.6.0
	 *
	 * @var Auth_Manager|null
	 */
	private $auth_manager;

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
	 * Account overview for the given range.
	 *
	 * @since 1.6.0
	 *
	 * @param int $days 7, 30 or 90.
	 * @return array|WP_Error
	 */
	public function get_overview( $days = 30 ) {
		$days      = in_array( (int) $days, self::RANGES, true ) ? (int) $days : 30;
		$workspace = $this->get_workspace_uuid();
		if ( ! $workspace ) {
			return new WP_Error( 'surecontact_not_connected', __( 'Connect your site to SureContact first.', 'surecontact' ), array( 'status' => 400 ) );
		}

		$cache_key = self::CACHE_PREFIX . md5( $workspace . '|' . $days );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = $this->get_saas_client()->post( 'wordpress/overview', array( 'days' => $days ) );
		if ( is_wp_error( $response ) || empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return new WP_Error( 'surecontact_overview_unavailable', __( 'Account numbers are not available right now.', 'surecontact' ), array( 'status' => 502 ) );
		}

		$overview = self::sanitize_overview( $response['data'] );
		set_transient( $cache_key, $overview, 5 * MINUTE_IN_SECONDS );

		return $overview;
	}

	/**
	 * Newest contacts from this site.
	 *
	 * @since 1.6.0
	 *
	 * @return array|WP_Error
	 */
	public function get_recent_contacts() {
		$workspace = $this->get_workspace_uuid();
		if ( ! $workspace ) {
			return new WP_Error( 'surecontact_not_connected', __( 'Connect your site to SureContact first.', 'surecontact' ), array( 'status' => 400 ) );
		}

		$cache_key = self::RECENT_CACHE_PREFIX . md5( $workspace );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = $this->get_saas_client()->post( 'wordpress/recent-contacts' );
		if ( is_wp_error( $response ) || ! isset( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return new WP_Error( 'surecontact_recent_unavailable', __( 'Recent contacts are not available right now.', 'surecontact' ), array( 'status' => 502 ) );
		}

		$contacts = array();
		foreach ( array_slice( $response['data'], 0, 5 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$contacts[] = array(
				'name'      => self::text( $row, 'name' ),
				'initials'  => self::text( $row, 'initials' ),
				'source'    => isset( $row['source'] ) && is_string( $row['source'] ) ? sanitize_key( $row['source'] ) : '',
				'createdAt' => self::text( $row, 'created_at' ),
			);
		}

		set_transient( $cache_key, $contacts, 2 * MINUTE_IN_SECONDS );

		return $contacts;
	}

	/**
	 * Keep only the overview fields the cards use, with safe types.
	 *
	 * @since 1.6.0
	 *
	 * @param array $data Data from the SaaS.
	 * @return array
	 */
	public static function sanitize_overview( array $data ) {
		$num = static function ( $value ) {
			return is_numeric( $value ) ? 0 + $value : null;
		};

		$growth = array();
		foreach ( is_array( $data['growth'] ?? null ) ? $data['growth'] : array() as $day ) {
			if ( is_array( $day ) ) {
				$growth[] = array(
					'date'  => self::text( $day, 'date' ),
					'count' => absint( $day['count'] ?? 0 ),
				);
			}
		}

		$last_campaign = null;
		if ( is_array( $data['last_campaign'] ?? null ) ) {
			$campaign      = $data['last_campaign'];
			$last_campaign = array(
				'name'       => self::text( $campaign, 'name' ),
				'sentAt'     => self::text( $campaign, 'sent_at' ),
				'recipients' => absint( $campaign['recipients'] ?? 0 ),
				'openRate'   => (float) ( $num( $campaign['open_rate'] ?? 0 ) ?? 0 ),
				'clickRate'  => (float) ( $num( $campaign['click_rate'] ?? 0 ) ?? 0 ),
			);
		}

		$automations = array();
		foreach ( is_array( $data['automations']['top'] ?? null ) ? $data['automations']['top'] : array() as $automation ) {
			if ( is_array( $automation ) ) {
				$automations[] = array(
					'name'    => self::text( $automation, 'name' ),
					'trigger' => self::text( $automation, 'trigger' ),
					'runs'    => absint( $automation['runs'] ?? 0 ),
				);
			}
		}

		$usage = static function ( $block ) {
			$block = is_array( $block ) ? $block : array();
			return array(
				'used'  => absint( $block['used'] ?? 0 ),
				'limit' => isset( $block['limit'] ) && is_numeric( $block['limit'] ) ? (int) $block['limit'] : null,
			);
		};

		return array(
			'rangeDays'    => absint( $data['range_days'] ?? 30 ),
			'contacts'     => array(
				'total'  => absint( $data['contacts']['total'] ?? 0 ),
				'new'    => absint( $data['contacts']['new'] ?? 0 ),
				'change' => $num( $data['contacts']['change'] ?? null ),
			),
			'emails'       => array(
				'sent'   => absint( $data['emails']['sent'] ?? 0 ),
				'change' => $num( $data['emails']['change'] ?? null ),
			),
			'engagement'   => array(
				'openRate'        => (float) ( $num( $data['engagement']['open_rate'] ?? 0 ) ?? 0 ),
				'clickRate'       => (float) ( $num( $data['engagement']['click_rate'] ?? 0 ) ?? 0 ),
				'openRateChange'  => $num( $data['engagement']['open_rate_change'] ?? null ),
				'clickRateChange' => $num( $data['engagement']['click_rate_change'] ?? null ),
			),
			'revenue'      => array(
				'currency' => self::currency( $data['revenue']['currency'] ?? 'USD' ),
				'amount'   => (float) ( $num( $data['revenue']['amount'] ?? 0 ) ?? 0 ),
				'orders'   => absint( $data['revenue']['orders'] ?? 0 ),
			),
			'growth'       => $growth,
			'lastCampaign' => $last_campaign,
			'automations'  => array(
				'active' => absint( $data['automations']['active'] ?? 0 ),
				'top'    => $automations,
			),
			'plan'         => array(
				'name'     => self::text( is_array( $data['plan'] ?? null ) ? $data['plan'] : array(), 'name' ),
				'contacts' => $usage( $data['plan']['contacts'] ?? null ),
				'emails'   => $usage( $data['plan']['emails'] ?? null ),
			),
		);
	}

	/**
	 * A three-letter ISO currency code, or USD.
	 *
	 * @since 1.6.0
	 *
	 * @param mixed $value Currency from the SaaS.
	 * @return string
	 */
	private static function currency( $value ) {
		$code = strtoupper( (string) ( is_scalar( $value ) ? $value : '' ) );
		return 1 === preg_match( '/^[A-Z]{3}$/', $code ) ? $code : 'USD';
	}

	/**
	 * A plain-text field from an array.
	 *
	 * @since 1.6.0
	 *
	 * @param array  $row Row.
	 * @param string $key Key.
	 * @return string
	 */
	private static function text( array $row, $key ) {
		return isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? sanitize_text_field( (string) $row[ $key ] ) : '';
	}

	/**
	 * Connected workspace, or empty when not connected.
	 *
	 * @since 1.6.0
	 *
	 * @return string
	 */
	private function get_workspace_uuid() {
		$auth = $this->get_auth_manager();
		return $auth->is_authenticated() ? (string) $auth->get_workspace_uuid() : '';
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
