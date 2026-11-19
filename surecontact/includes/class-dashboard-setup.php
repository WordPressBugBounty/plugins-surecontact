<?php
/**
 * Dashboard Setup
 *
 * Decides whether a connected site sees the Setup screen or the dashboard.
 *
 * @since 1.6.0
 *
 * @package SureContact
 */

namespace SureContact;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard_Setup class
 *
 * Setup is finished when BOTH are true:
 *  - at least one integration is turned on (any enabled rule or global toggle), and
 *  - at least one contact has synced from this site (live sync or Bulk Sync).
 * Until then the dashboard shows the Setup screen, unless the user chose "Skip setup".
 * Everything here is local: no API call.
 *
 * @since 1.6.0
 */
class Dashboard_Setup {

	/**
	 * Option holding the time the first contact synced from this site.
	 *
	 * @since 1.6.0
	 */
	const SYNCED_OPTION = 'surecontact_first_contact_synced_at';

	/**
	 * User meta set when the user skips setup.
	 *
	 * @since 1.6.0
	 */
	const SKIP_META = 'surecontact_dashboard_setup_skipped';

	/**
	 * Transient that remembers a "not synced yet" answer for a short while, so the
	 * one-time queue check doesn't run on every page load.
	 *
	 * @since 1.6.0
	 */
	const NOT_SYNCED_TRANSIENT = 'surecontact_setup_not_synced';

	/**
	 * Endpoints whose success means a contact reached SureContact.
	 *
	 * @since 1.6.0
	 */
	const SYNC_ENDPOINTS = array( 'wordpress/sync-contact', 'wordpress/sync-contacts-bulk' );

	/**
	 * Available integrations, injectable for tests.
	 *
	 * @since 1.6.0
	 *
	 * @var array|null
	 */
	private $integrations;

	/**
	 * Constructor
	 *
	 * @since 1.6.0
	 *
	 * @param array|null $integrations Optional integrations list (slug => config), as from
	 *                                 Integrations_Loader::get_available_integrations().
	 */
	public function __construct( $integrations = null ) {
		$this->integrations = $integrations;
	}

	/**
	 * Setup state for the React app.
	 *
	 * @since 1.6.0
	 *
	 * @return array
	 */
	public function get_state() {
		$detected        = $this->get_detected_integrations();
		$integration_on  = in_array( true, array_column( $detected, 'enabled' ), true );
		$contacts_synced = self::has_synced();
		$skipped         = (bool) get_user_meta( get_current_user_id(), self::SKIP_META, true );
		$complete        = $integration_on && $contacts_synced;

		return array(
			'complete'       => $complete,
			'skipped'        => $skipped,
			'showSetup'      => ! $complete && ! $skipped,
			'integrationOn'  => $integration_on,
			'contactsSynced' => $contacts_synced,
			'integrations'   => $detected,
		);
	}

	/**
	 * Remember that the current user skipped setup.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public static function skip() {
		update_user_meta( get_current_user_id(), self::SKIP_META, 1 );
	}

	/**
	 * Record the first successful contact sync. Called by SaaS_Client after a sync succeeds.
	 *
	 * @since 1.6.0
	 *
	 * @param string $endpoint API endpoint that succeeded.
	 * @return void
	 */
	public static function maybe_record_sync( $endpoint ) {
		if ( ! in_array( ltrim( (string) $endpoint, '/' ), self::SYNC_ENDPOINTS, true ) ) {
			return;
		}
		if ( ! get_option( self::SYNCED_OPTION ) ) {
			update_option( self::SYNCED_OPTION, time(), false );
			delete_transient( self::NOT_SYNCED_TRANSIENT );
		}
	}

	/**
	 * Whether any contact has synced from this site.
	 *
	 * Sites that synced before this option existed are found once in the API queue log
	 * (successful sync requests), then remembered in the option.
	 *
	 * @since 1.6.0
	 *
	 * @return bool
	 */
	public static function has_synced() {
		if ( get_option( self::SYNCED_OPTION ) ) {
			return true;
		}

		if ( get_transient( self::NOT_SYNCED_TRANSIENT ) ) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'surecontact_api_queue';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One cheap indexed lookup, result cached in an option or transient.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = %s AND endpoint IN (%s, %s) LIMIT 1",
				'success',
				self::SYNC_ENDPOINTS[0],
				self::SYNC_ENDPOINTS[1]
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $found ) {
			update_option( self::SYNCED_OPTION, time(), false );
			return true;
		}

		set_transient( self::NOT_SYNCED_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS );
		return false;
	}

	/**
	 * Integrations whose plugin is active on this site, with whether each is turned on.
	 *
	 * @since 1.6.0
	 *
	 * @return array List of { slug, name, iconUrl, enabled }.
	 */
	private function get_detected_integrations() {
		$integrations = $this->integrations;

		if ( null === $integrations ) {
			global $surecontact;
			$integrations = isset( $surecontact->integrations_loader ) ? $surecontact->integrations_loader->get_available_integrations() : array();
		}

		$detected = array();
		foreach ( (array) $integrations as $slug => $config ) {
			if ( empty( $config['available'] ) ) {
				continue;
			}
			$detected[] = array(
				'slug'    => sanitize_key( (string) $slug ),
				'name'    => isset( $config['name'] ) ? (string) $config['name'] : (string) $slug,
				'iconUrl' => isset( $config['icon_url'] ) ? esc_url_raw( (string) $config['icon_url'] ) : '',
				'enabled' => ! empty( $config['enabled'] ),
			);
		}

		return $detected;
	}
}
