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
		$this->init_hooks();
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

	private function init_hooks(): void {
		// Admin.
		if ( is_admin() ) {
			new Admin\Settings_Page();
			new Admin\Dashboard_Page();
			new Admin\Responses_Page();
		}

		// Core modules.
		new Review_Collector();
		new Sentiment_Analyzer();
		new Response_Generator();
		new Email_Sender();

		// REST API (lazy-loaded — controllers instantiated only on REST requests).
		add_action( 'rest_api_init', static function (): void {
			( new API\Reviews_Controller() )->register_routes();
			( new API\Sentiment_Controller() )->register_routes();
		} );

		// Cron.
		add_action( 'wairm_process_pending_reviews', [ Sentiment_Analyzer::class, 'process_pending' ] );
		add_action( 'wairm_send_review_invitations', [ Email_Sender::class, 'process_queue' ] );
		add_action( 'wairm_expire_invitations', [ self::class, 'expire_stale_invitations' ] );
	}
}
