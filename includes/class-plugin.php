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

	private function init_hooks(): void {
		// Admin.
		if ( is_admin() ) {
			new Admin\Settings_Page();
			new Admin\Dashboard_Page();
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
	}
}
