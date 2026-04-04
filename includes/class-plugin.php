<?php
/**
 * Main plugin orchestrator.
 *
 * @package WooAIReviewManager
 */

declare(strict_types=1);

namespace WooAIReviewManager;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->maybe_upgrade();
		$this->init_hooks();
	}

	/**
	 * Run the installer if the DB version is outdated (e.g. new tables added).
	 */
	private function maybe_upgrade(): void {
		$db_version = get_option( 'wairm_version', '0' );
		if ( version_compare( $db_version, WAIRM_VERSION, '<' ) ) {
			Installer::activate();
		}
	}

	/**
	 * Expire stale invitations that have passed their expiry date.
	 */
	public static function expire_stale_invitations(): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}wairm_review_invitations
				 SET status = 'expired'
				 WHERE status IN ('pending', 'sent', 'clicked')
				   AND expires_at < %s",
				current_time( 'mysql', true )
			)
		);

		// Cancel queued emails for expired invitations.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}wairm_email_queue eq
				 JOIN {$wpdb->prefix}wairm_review_invitations ri ON ri.id = eq.invitation_id
				 SET eq.status = 'cancelled'
				 WHERE eq.status = 'queued' AND ri.status = %s",
				'expired'
			)
		);
	}

	/**
	 * Register placeholder menu pages for pro features so free users can see them.
	 */
	private function register_pro_upgrade_pages(): void {
		$pages = [
			[
				'title' => __( 'Insights', 'woo-ai-review-manager' ),
				'slug'  => 'wairm-insights',
				'desc'  => __( 'Get AI-generated insights about product feedback, trends, and operational improvements.', 'woo-ai-review-manager' ),
			],
		];

		add_action( 'admin_menu', function () use ( $pages ): void {
			foreach ( $pages as $page ) {
				add_submenu_page(
					'wairm-dashboard',
					$page['title'],
					$page['title'] . ' <span class="wairm-pro-badge" style="font-size:9px;padding:1px 5px;margin-left:4px;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;border-radius:3px;vertical-align:middle;">PRO</span>',
					'manage_woocommerce',
					$page['slug'],
					function () use ( $page ): void {
						printf(
							'<div class="wrap"><div class="wairm-pro-page-prompt"><h2>%s</h2><p>%s</p><a href="%s" class="button button-primary">%s</a></div></div>',
							esc_html( $page['title'] ),
							esc_html( $page['desc'] ),
							esc_url( warc_fs()->get_upgrade_url() ),
							esc_html__( 'Upgrade to Pro', 'woo-ai-review-manager' )
						);
					}
				);
			}
		} );
	}

	private function init_hooks(): void {
		$is_paying = warc_fs()->is_paying();

		// Admin.
		if ( is_admin() ) {
			new Admin\Dashboard_Page();
			new Admin\Invitations_Page();
			new Admin\Settings_Page();

			new Admin\Responses_Page();

			if ( $is_paying ) {
				new Admin\Insights_Page();
				new Admin\CSV_Export();
			} else {
				$this->register_pro_upgrade_pages();
			}
		}

		// Core modules.
		new Review_Collector();
		new Sentiment_Analyzer();
		new Email_Sender();

		if ( $is_paying ) {
			new Response_Generator();
		}

		// REST API (lazy-loaded — controllers instantiated only on REST requests).
		add_action( 'rest_api_init', static function (): void {
			( new API\Reviews_Controller() )->register_routes();
			( new API\Sentiment_Controller() )->register_routes();
		} );

		// Cron.
		add_action( 'wairm_send_review_invitations', [ Email_Sender::class, 'process_queue' ] );
		add_action( 'wairm_expire_invitations', [ self::class, 'expire_stale_invitations' ] );
	}
}
