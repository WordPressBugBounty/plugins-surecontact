<?php
/**
 * Promo Service
 *
 * Works out whether the BFCM sale is running and whether this site should
 * see it, for the plugin's upgrade nudges.
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
 * Promo_Service class
 *
 * Connected sites ask the SureContact API (POST /wordpress/promo), which knows
 * the plan and the sale dates. The answer is cached for up to an hour, so a
 * site that buys Lifetime stops seeing the sale soon after.
 * Disconnected sites never call the API (WordPress.org guideline 7); they use
 * the dates built into the plugin and are treated as prospects.
 *
 * Everything fails closed: no answer, an unknown plan or a past end date
 * (by this site's clock) means no sale UI.
 *
 * @since 1.6.0
 */
class Promo_Service {

	/**
	 * Campaign key, used in UTMs and in dismissal keys.
	 *
	 * @since 1.6.0
	 */
	const CAMPAIGN = 'bfcm-2026';

	/**
	 * Transient holding the API answer.
	 *
	 * @since 1.6.0
	 */
	const CACHE_KEY = 'surecontact_promo_bfcm_2026';

	/**
	 * User meta prefix for dismissals. The year is in the key so last year's
	 * dismissal never hides this year's sale.
	 *
	 * @since 1.6.0
	 */
	const DISMISS_META_PREFIX = 'surecontact_bfcm_2026_dismissed_';

	/**
	 * Surfaces that can be dismissed.
	 *
	 * @since 1.6.0
	 */
	const DISMISSIBLE_SURFACES = array( 'sale-bar', 'admin-notice' );

	/**
	 * Every surface that links to the sale. Each one is its own utm_medium,
	 * so we can see which surface sold.
	 *
	 * @since 1.6.0
	 */
	const SURFACES = array( 'sale-bar', 'signup', 'dashboard-ticket', 'sync-limit', 'plugins-row', 'admin-notice' );

	/**
	 * How long a good answer is cached.
	 *
	 * @since 1.6.0
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * How long a failed lookup is remembered, so a broken API isn't called on every page load.
	 *
	 * @since 1.6.0
	 */
	const FAILURE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * The last 48 hours of the sale use "last chance" copy.
	 *
	 * @since 1.6.0
	 */
	const LAST_CHANCE_WINDOW = 2 * DAY_IN_SECONDS;

	/**
	 * Built-in sale schedule (UTC), used for disconnected sites. Connected
	 * sites use the dates the API sends, so the sale can move without a release.
	 *
	 * Black Friday until 27 Nov 00:00 UTC (the same moment as the app's
	 * BFCM_SALE_END, so both timers match), Cyber Monday until the end of
	 * 30 Nov, and the extended sale until the end of 6 Dec.
	 *
	 * @since 1.6.0
	 */
	const FALLBACK_SCHEDULE = array(
		'starts_at'        => '2026-11-01T00:00:00Z',
		'discount_percent' => 83,
		'phases'           => array(
			array(
				'key'     => 'black_friday',
				'ends_at' => '2026-11-27T00:00:00Z',
			),
			array(
				'key'     => 'cyber_monday',
				'ends_at' => '2026-12-01T00:00:00Z',
			),
			array(
				'key'     => 'extended',
				'ends_at' => '2026-12-07T00:00:00Z',
			),
		),
	);

	/**
	 * How long after the built-in schedule ends connected sites still ask the API, in case
	 * the sale is extended. After that no request is made, so ended sales cost nothing.
	 *
	 * @since 1.6.0
	 */
	const REMOTE_GRACE = 7 * DAY_IN_SECONDS;

	/**
	 * Phase keys the plugin knows how to word.
	 *
	 * @since 1.6.0
	 */
	const KNOWN_PHASES = array( 'black_friday', 'cyber_monday', 'extended' );

	/**
	 * Auth manager.
	 *
	 * @since 1.6.0
	 *
	 * @var Auth_Manager
	 */
	private $auth_manager;

	/**
	 * SaaS client, created on first use.
	 *
	 * @since 1.6.0
	 *
	 * @var SaaS_Client|null
	 */
	private $saas_client;

	/**
	 * Constructor
	 *
	 * @since 1.6.0
	 *
	 * @param Auth_Manager|null $auth_manager Optional auth manager.
	 * @param SaaS_Client|null  $saas_client  Optional SaaS client.
	 */
	public function __construct( ?Auth_Manager $auth_manager = null, ?SaaS_Client $saas_client = null ) {
		$this->auth_manager = $auth_manager ? $auth_manager : new Auth_Manager();
		$this->saas_client  = $saas_client;
	}

	/**
	 * The sale state for this site and the current user, as sent to the React app.
	 *
	 * Keys: active, eligible, connected, audience ('free' | 'paid' | 'prospect' | null),
	 * phase, phaseEndsAt, endsAt (unix seconds), discountPercent, lastChance, upgradeUrl,
	 * links (surface => URL with UTMs), dismissed (surface => bool).
	 *
	 * @since 1.6.0
	 *
	 * @param int|null $now Optional current unix time (for tests).
	 * @return array
	 */
	public function get_state( $now = null ) {
		$now       = null === $now ? time() : (int) $now;
		$connected = $this->auth_manager->is_authenticated() && (bool) $this->auth_manager->get_workspace_uuid();

		$state = array(
			'active'          => false,
			'eligible'        => false,
			'connected'       => $connected,
			'audience'        => null,
			'phase'           => null,
			'phaseEndsAt'     => null,
			'endsAt'          => null,
			'discountPercent' => (int) self::FALLBACK_SCHEDULE['discount_percent'],
			'lastChance'      => false,
			'upgradeUrl'      => '',
			'links'           => array(),
			'dismissed'       => array(),
		);

		if ( ! current_user_can( 'manage_options' ) ) {
			return $state;
		}

		// Well past the sale: nothing to show, so don't call the API at all.
		if ( $now >= self::schedule_end( self::FALLBACK_SCHEDULE ) + self::REMOTE_GRACE ) {
			return $state;
		}

		if ( $connected ) {
			$remote = $this->get_remote( $now );
			if ( null === $remote ) {
				return $state;
			}

			$phase         = isset( $remote['phase'] ) ? (string) $remote['phase'] : '';
			$phase_ends_at = isset( $remote['phase_ends_at'] ) ? strtotime( (string) $remote['phase_ends_at'] ) : false;
			$ends_at       = isset( $remote['ends_at'] ) ? strtotime( (string) $remote['ends_at'] ) : false;
			$audience      = isset( $remote['audience'] ) && in_array( $remote['audience'], array( 'free', 'paid' ), true ) ? $remote['audience'] : null;
			$active        = ! empty( $remote['active'] ) && in_array( $phase, self::KNOWN_PHASES, true ) && $phase_ends_at && $ends_at && $now < $phase_ends_at && $now < $ends_at;

			$state['discountPercent'] = isset( $remote['discount_percent'] ) ? absint( $remote['discount_percent'] ) : $state['discountPercent'];
			$state['upgradeUrl']      = isset( $remote['upgrade_url'] ) ? self::upgrade_dialog_url( (string) $remote['upgrade_url'] ) : '';
		} else {
			$current       = self::phase_from_schedule( self::FALLBACK_SCHEDULE, $now );
			$phase         = $current ? $current['key'] : '';
			$phase_ends_at = $current ? $current['ends_at'] : false;
			$ends_at       = self::schedule_end( self::FALLBACK_SCHEDULE );
			$audience      = 'prospect';
			$active        = (bool) $current;
		}

		if ( ! $active ) {
			return $state;
		}

		$state['active']      = true;
		$state['audience']    = $audience;
		$state['eligible']    = null !== $audience && ( 'prospect' === $audience || '' !== $state['upgradeUrl'] );
		$state['phase']       = $phase;
		$state['phaseEndsAt'] = (int) $phase_ends_at;
		$state['endsAt']      = (int) $ends_at;
		$state['lastChance']  = ( (int) $ends_at - $now ) <= self::LAST_CHANCE_WINDOW;

		if ( ! $state['eligible'] ) {
			return $state;
		}

		foreach ( self::SURFACES as $surface ) {
			$state['links'][ $surface ] = self::build_url( $state, $surface );
		}

		foreach ( self::DISMISSIBLE_SURFACES as $surface ) {
			$state['dismissed'][ $surface ] = $this->is_dismissed( $surface );
		}

		return $state;
	}

	/**
	 * Turn the app URL from the API into a link that opens the upgrade dialog.
	 *
	 * The app opens its Upgrade Plans dialog (on the Lifetime tab) on any
	 * dashboard page that has ?plan=lifetime. We use /dashboard rather than the
	 * plans page, because the plans page also reads ?plan= and would open its
	 * own dialog on top. Only the scheme and host from the API are kept, so each
	 * environment (staging, qaing, production) still points at its own app.
	 *
	 * @since 1.6.0
	 *
	 * @param string $url App URL from the API, e.g. https://app.surecontact.com/organization/plans.
	 * @return string Empty when the URL can't be used.
	 */
	private static function upgrade_dialog_url( $url ) {
		$parts = wp_parse_url( esc_url_raw( $url ) );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) {
			return '';
		}

		$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		return add_query_arg( array( 'plan' => 'lifetime' ), $origin . '/dashboard' );
	}

	/**
	 * Build a link for a sale surface, with the campaign UTMs.
	 *
	 * Connected sites go to the app's upgrade dialog; prospects go to the
	 * plugin's connect / signup screen.
	 *
	 * @since 1.6.0
	 *
	 * @param array  $state  State from get_state().
	 * @param string $medium Which surface the click came from (e.g. 'sale-bar').
	 * @return string
	 */
	public static function build_url( array $state, $medium ) {
		$base = ( isset( $state['audience'] ) && 'prospect' === $state['audience'] )
			? admin_url( 'admin.php?page=surecontact-dashboard' )
			: ( isset( $state['upgradeUrl'] ) ? (string) $state['upgradeUrl'] : '' );

		if ( '' === $base ) {
			return '';
		}

		return add_query_arg(
			array(
				'utm_source'   => 'wp-plugin',
				'utm_medium'   => sanitize_key( $medium ),
				'utm_campaign' => self::CAMPAIGN,
			),
			$base
		);
	}

	/**
	 * Whether the current user has dismissed a surface.
	 *
	 * A dismissal is permanent: once closed, the surface never comes back for
	 * that user, including in the last-chance window (WordPress.org guideline 11).
	 * Any saved value counts, so older 'live' / 'last-chance' values still work.
	 *
	 * @since 1.6.0
	 *
	 * @param string $surface Surface key.
	 * @return bool
	 */
	public function is_dismissed( $surface ) {
		if ( ! in_array( $surface, self::DISMISSIBLE_SURFACES, true ) ) {
			return false;
		}

		return ! empty( get_user_meta( get_current_user_id(), self::DISMISS_META_PREFIX . $surface, true ) );
	}

	/**
	 * Remember that the current user dismissed a surface.
	 *
	 * @since 1.6.0
	 *
	 * @param string $surface Surface key.
	 * @return bool False for an unknown surface.
	 */
	public function dismiss( $surface ) {
		if ( ! in_array( $surface, self::DISMISSIBLE_SURFACES, true ) ) {
			return false;
		}

		update_user_meta( get_current_user_id(), self::DISMISS_META_PREFIX . $surface, 'dismissed' );

		return true;
	}

	/**
	 * The API answer for this workspace, from cache or a fresh call.
	 *
	 * @since 1.6.0
	 *
	 * @param int $now Current unix time.
	 * @return array|null Null when the lookup failed.
	 */
	private function get_remote( $now ) {
		$workspace_uuid = (string) $this->auth_manager->get_workspace_uuid();
		$cached         = get_transient( self::CACHE_KEY );

		// The cache is keyed to the workspace, so reconnecting to another workspace never reuses its plan.
		if ( is_array( $cached ) && isset( $cached['workspace'] ) && $cached['workspace'] === $workspace_uuid ) {
			return isset( $cached['data'] ) && is_array( $cached['data'] ) ? $cached['data'] : null;
		}

		$response = $this->get_saas_client()->post( 'wordpress/promo', array( 'workspace_uuid' => $workspace_uuid ) );

		if ( is_wp_error( $response ) || empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
			set_transient(
				self::CACHE_KEY,
				array(
					'workspace' => $workspace_uuid,
					'data'      => null,
				),
				self::FAILURE_TTL
			);
			return null;
		}

		$data = $response['data'];

		// Expire the cache when the phase changes, so the copy flips on time.
		$ttl           = self::CACHE_TTL;
		$phase_ends_at = isset( $data['phase_ends_at'] ) ? strtotime( (string) $data['phase_ends_at'] ) : false;
		if ( $phase_ends_at && $phase_ends_at > $now ) {
			$ttl = min( $ttl, max( MINUTE_IN_SECONDS, $phase_ends_at - $now ) );
		}

		set_transient(
			self::CACHE_KEY,
			array(
				'workspace' => $workspace_uuid,
				'data'      => $data,
			),
			$ttl
		);

		return $data;
	}

	/**
	 * The SaaS client, created on first use so pages that never need it don't build one.
	 *
	 * @since 1.6.0
	 *
	 * @return SaaS_Client
	 */
	private function get_saas_client() {
		if ( null === $this->saas_client ) {
			$this->saas_client = new SaaS_Client( $this->auth_manager );
		}
		return $this->saas_client;
	}

	/**
	 * The phase running at $now in a schedule, with its end as a unix time.
	 *
	 * @since 1.6.0
	 *
	 * @param array $schedule Schedule in the FALLBACK_SCHEDULE shape.
	 * @param int   $now      Current unix time.
	 * @return array|null { key: string, ends_at: int } or null outside the sale.
	 */
	private static function phase_from_schedule( array $schedule, $now ) {
		if ( $now < strtotime( $schedule['starts_at'] ) ) {
			return null;
		}

		foreach ( $schedule['phases'] as $phase ) {
			$ends_at = strtotime( $phase['ends_at'] );
			if ( $now < $ends_at ) {
				return array(
					'key'     => $phase['key'],
					'ends_at' => $ends_at,
				);
			}
		}

		return null;
	}

	/**
	 * When a schedule's last phase ends, as a unix time.
	 *
	 * @since 1.6.0
	 *
	 * @param array $schedule Schedule in the FALLBACK_SCHEDULE shape.
	 * @return int
	 */
	private static function schedule_end( array $schedule ) {
		$last = end( $schedule['phases'] );
		return (int) strtotime( $last['ends_at'] );
	}
}
