<?php
/**
 * Promo Admin
 *
 * The two BFCM surfaces outside SureContact's own pages: a link in the
 * plugin's row on the Plugins screen, and one dismissible admin notice.
 *
 * @since 1.6.0
 *
 * @package SureContact
 */

namespace SureContact\Admin;

use SureContact\Promo_Service;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Promo_Admin class
 *
 * Kept tight for WordPress.org guideline 11: no sidebar badge, the notice
 * only shows on the WP Dashboard and Plugins screens, only to admins, it
 * can be dismissed, and both surfaces hide themselves when the sale ends.
 *
 * @since 1.6.0
 */
class Promo_Admin {

	/**
	 * Screens the notice may appear on.
	 *
	 * @since 1.6.0
	 */
	const NOTICE_SCREENS = array( 'dashboard', 'plugins' );

	/**
	 * Promo state for this request, worked out on first use.
	 *
	 * @since 1.6.0
	 *
	 * @var array|null
	 */
	private $state = null;

	/**
	 * Promo service, created on first use.
	 *
	 * @since 1.6.0
	 *
	 * @var Promo_Service|null
	 */
	private $service = null;

	/**
	 * Constructor
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		add_filter( 'plugin_action_links_' . SURECONTACT_PLUGIN_BASENAME, array( $this, 'add_sale_action_link' ), 20 );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Put a "Get 83% off Lifetime" link first in the plugin's row.
	 *
	 * @since 1.6.0
	 *
	 * @param array $links Plugin action links.
	 * @return array
	 */
	public function add_sale_action_link( $links ) {
		$state = $this->get_state();

		if ( empty( $state['eligible'] ) || empty( $state['links']['plugins-row'] ) ) {
			return $links;
		}

		$is_prospect = 'prospect' === $state['audience'];
		$label       = $is_prospect
			/* translators: %d: discount percent */
			? sprintf( __( 'Sign up · %d%% off Lifetime', 'surecontact' ), $state['discountPercent'] )
			/* translators: %d: discount percent */
			: sprintf( __( 'Get %d%% off Lifetime', 'surecontact' ), $state['discountPercent'] );

		$link = sprintf(
			'<a href="%1$s"%2$s style="color:#8345dd;font-weight:700;">%3$s</a>',
			esc_url( $state['links']['plugins-row'] ),
			// Prospects stay in WP admin (the connect screen); upgrades open the app in a new tab.
			$is_prospect ? '' : ' target="_blank" rel="noopener noreferrer"',
			esc_html( $label )
		);

		array_unshift( $links, $link );

		return $links;
	}

	/**
	 * Show the sale notice on the WP Dashboard and Plugins screens.
	 *
	 * Sites that aren't connected only see it on the Plugins screen.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function render_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, self::NOTICE_SCREENS, true ) ) {
			return;
		}

		$state = $this->get_state();
		if ( empty( $state['eligible'] ) || empty( $state['links']['admin-notice'] ) ) {
			return;
		}

		$is_prospect = 'prospect' === $state['audience'];
		if ( $is_prospect && 'plugins' !== $screen->id ) {
			return;
		}

		if ( $this->get_service()->is_dismissed( 'admin-notice' ) ) {
			return;
		}

		$last_call = ! empty( $state['lastChance'] );

		// The countdown's end is exclusive (midnight UTC), so show the last day the sale is open,
		// in the site's timezone and language.
		$end_time = $last_call || empty( $state['phaseEndsAt'] ) ? (int) $state['endsAt'] : (int) $state['phaseEndsAt'];

		$percent    = (int) $state['discountPercent'];
		$end_date   = wp_date( _x( 'j M', 'BFCM sale end date format', 'surecontact' ), $end_time - 1 );
		$new_tab    = $is_prospect ? '' : ' target="_blank" rel="noopener noreferrer"';
		$sale_label = $last_call ? __( 'Last chance', 'surecontact' ) : $this->get_phase_name( $state['phase'] );

		/* translators: %d: discount percent */
		$headline = sprintf( __( 'SureContact Lifetime is %d%% off', 'surecontact' ), $percent );

		if ( $is_prospect ) {
			$body = __( 'Create your free account, then lock in email marketing, automations and CRM for good.', 'surecontact' );
		} elseif ( $last_call ) {
			/* translators: %s: end date of the sale, e.g. 7 Dec */
			$body = sprintf( __( 'Pay once, keep email marketing, automations and CRM for good. The sale ends %s.', 'surecontact' ), $end_date );
		} else {
			/* translators: %s: end date of the current sale phase, e.g. 27 Nov */
			$body = sprintf( __( 'Pay once, keep email marketing, automations and CRM for good. Ends %s.', 'surecontact' ), $end_date );
		}

		$cta = $is_prospect
			? __( 'Create free account', 'surecontact' )
			/* translators: %d: discount percent */
			: sprintf( __( 'Claim %d%% off', 'surecontact' ), $percent );

		$this->print_notice_styles();
		?>
		<div class="notice surecontact-promo-notice" role="region" aria-label="<?php esc_attr_e( 'SureContact sale', 'surecontact' ); ?>">
			<img class="surecontact-promo-logo" src="<?php echo esc_url( SURECONTACT_PLUGIN_URL . 'assets/images/logo.svg' ); ?>" alt="" width="52" height="52" />
			<div class="surecontact-promo-copy">
				<span class="surecontact-promo-tag"><?php echo esc_html( $sale_label ); ?></span>
				<p class="surecontact-promo-headline"><?php echo esc_html( $headline ); ?></p>
				<p class="surecontact-promo-body"><?php echo esc_html( $body ); ?></p>
			</div>
			<div class="surecontact-promo-offer" aria-hidden="true">
				<span class="surecontact-promo-percent"><?php echo esc_html( $percent . '%' ); ?></span>
				<span class="surecontact-promo-off"><?php esc_html_e( 'OFF', 'surecontact' ); ?><br /><?php esc_html_e( 'Lifetime', 'surecontact' ); ?></span>
			</div>
			<a class="surecontact-promo-cta" href="<?php echo esc_url( $state['links']['admin-notice'] ); ?>"<?php echo $new_tab; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute string. ?>><?php echo esc_html( $cta ); ?></a>
			<button type="button" class="notice-dismiss surecontact-promo-dismiss"><span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'surecontact' ); ?></span></button>
		</div>
		<?php
		$this->print_dismiss_script();
	}

	/**
	 * Styles for the sale notice, scoped to its own class.
	 *
	 * WP admin has no stylesheet of ours on these screens, and the dismiss
	 * icon and hover states need real CSS, so this prints a small style block.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	private function print_notice_styles() {
		?>
		<style id="surecontact-promo-notice-css">
			.surecontact-promo-notice{position:relative;display:flex;align-items:center;gap:20px;flex-wrap:wrap;margin:16px 20px 16px 0;padding:18px 56px 18px 20px;border:0;border-radius:12px;overflow:hidden;color:#fff;background:radial-gradient(circle at 85% -40%,rgba(255,255,255,.18) 0,rgba(255,255,255,0) 45%),linear-gradient(90deg,#7c4dff 0%,#4a1fb8 50%,#1c0c4a 100%);box-shadow:0 10px 30px -14px rgba(28,12,74,.7)}
			.surecontact-promo-notice .surecontact-promo-logo{flex:none;box-sizing:border-box;width:52px;height:52px;padding:6px;border-radius:14px;background:#fff;box-shadow:0 6px 16px -6px rgba(0,0,0,.45)}
			.surecontact-promo-notice .surecontact-promo-copy{flex:1 1 280px;min-width:0}
			.surecontact-promo-notice .surecontact-promo-tag{display:inline-block;margin-bottom:6px;padding:2px 10px;border-radius:999px;background:rgba(255,255,255,.16);font-size:11px;font-weight:600;line-height:18px;letter-spacing:.08em;text-transform:uppercase}
			.surecontact-promo-notice p{margin:0;padding:0}
			.surecontact-promo-notice .surecontact-promo-headline{font-size:17px;font-weight:700;line-height:1.35;color:#fff}
			.surecontact-promo-notice .surecontact-promo-body{margin-top:2px;font-size:13px;line-height:1.5;color:rgba(255,255,255,.8)}
			.surecontact-promo-notice .surecontact-promo-offer{display:flex;align-items:center;gap:6px;flex:none;padding-right:20px;border-right:1px solid rgba(255,255,255,.2)}
			.surecontact-promo-notice .surecontact-promo-percent{font-family:"Castoro Titling",Georgia,"Times New Roman",serif;font-size:40px;line-height:1;color:#fff}
			.surecontact-promo-notice .surecontact-promo-off{font-size:11px;font-weight:700;line-height:1.2;letter-spacing:.08em;text-transform:uppercase}
			.surecontact-promo-notice .surecontact-promo-cta{flex:none;display:inline-block;padding:10px 18px;border-radius:8px;background:#fff;color:#1c0c4a;font-size:14px;font-weight:600;line-height:1.2;text-decoration:none;box-shadow:0 4px 14px -6px rgba(0,0,0,.4);transition:transform .15s ease,box-shadow .15s ease}
			.surecontact-promo-notice .surecontact-promo-cta:hover,.surecontact-promo-notice .surecontact-promo-cta:focus{color:#1c0c4a;transform:translateY(-1px);box-shadow:0 8px 18px -6px rgba(0,0,0,.45)}
			.surecontact-promo-notice .surecontact-promo-cta:focus{outline:2px solid #fff;outline-offset:2px}
			.surecontact-promo-notice .notice-dismiss{top:50%;transform:translateY(-50%)}
			.surecontact-promo-notice .notice-dismiss:before{color:rgba(255,255,255,.7)}
			.surecontact-promo-notice .notice-dismiss:hover:before,.surecontact-promo-notice .notice-dismiss:focus:before{color:#fff}
			@media (max-width:782px){.surecontact-promo-notice{margin-right:10px;gap:14px}.surecontact-promo-notice .surecontact-promo-offer{display:none}.surecontact-promo-notice .notice-dismiss{top:8px;transform:none}}
		</style>
		<?php
	}

	/**
	 * Small inline script that hides the notice and saves the dismissal.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	private function print_dismiss_script() {
		$config = array(
			'url'   => esc_url_raw( rest_url( 'surecontact/v1/promo/dismiss' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		);

		$script = sprintf(
			'(function(c){var b=document.querySelector(".surecontact-promo-dismiss");if(!b){return;}b.addEventListener("click",function(){var n=b.closest(".surecontact-promo-notice");if(n){n.remove();}window.fetch(c.url,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","X-WP-Nonce":c.nonce},body:JSON.stringify({surface:"admin-notice"})});});})(%s);',
			wp_json_encode( $config )
		);

		wp_print_inline_script_tag( $script );
	}

	/**
	 * Short sale name for the current phase.
	 *
	 * @since 1.6.0
	 *
	 * @param string $phase Phase key.
	 * @return string
	 */
	private function get_phase_name( $phase ) {
		switch ( $phase ) {
			case 'cyber_monday':
				return __( 'Cyber Monday', 'surecontact' );
			case 'extended':
				return __( 'BFCM Extended Sale', 'surecontact' );
			default:
				return __( 'Black Friday', 'surecontact' );
		}
	}

	/**
	 * Promo state, worked out once per request.
	 *
	 * @since 1.6.0
	 *
	 * @return array
	 */
	private function get_state() {
		if ( null === $this->state ) {
			$this->state = $this->get_service()->get_state();
		}
		return $this->state;
	}

	/**
	 * Promo service, created on first use. Built lazily so its Auth_Manager is
	 * created after admin_init and doesn't register a second set of auth hooks.
	 *
	 * @since 1.6.0
	 *
	 * @return Promo_Service
	 */
	private function get_service() {
		if ( null === $this->service ) {
			$this->service = new Promo_Service();
		}
		return $this->service;
	}
}
