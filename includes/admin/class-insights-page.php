<?php
/**
 * Admin page for AI-powered review insights.
 *
 * Provides actionable insights from customer reviews across four categories:
 * Product-Level, Trends, Operational, and Strategic.
 *
 * @package WooAIReviewManager
 */

declare(strict_types=1);

namespace WooAIReviewManager\Admin;

defined( 'ABSPATH' ) || exit;

final class Insights_Page {

	/** @var string Transient prefix for cached insights. */
	private const CACHE_PREFIX = 'wairm_insight_';

	/** @var int Cache TTL in seconds (24 hours). */
	private const CACHE_TTL = 86400;

	/** @var array<string, string> Valid insight categories. */
	private const CATEGORIES = [
		'product'     => 'Product-Level',
		'trends'      => 'Trends',
		'operational' => 'Operational',
		'strategic'   => 'Strategic',
	];

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_submenu_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_wairm_generate_insight', [ $this, 'ajax_generate_insight' ] );
	}

	public function add_submenu_page(): void {
		add_submenu_page(
			'wairm-dashboard',
			__( 'Insights', 'woo-ai-review-manager' ),
			__( 'Insights', 'woo-ai-review-manager' ),
			'manage_woocommerce',
			'wairm-insights',
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'ai-reviews_page_wairm-insights' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wairm-admin',
			WAIRM_PLUGIN_URL . 'assets/css/admin.css',
			[],
			WAIRM_VERSION
		);

		wp_enqueue_script(
			'wairm-insights',
			WAIRM_PLUGIN_URL . 'assets/js/insights.js',
			[],
			WAIRM_VERSION,
			true
		);

		wp_localize_script(
			'wairm-insights',
			'wairmInsights',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wairm_insights' ),
				'i18n'     => [
					'generating'    => __( 'Analyzing reviews...', 'woo-ai-review-manager' ),
					'error'         => __( 'Failed to generate insights. Please try again.', 'woo-ai-review-manager' ),
					'no_reviews'    => __( 'Not enough review data to generate insights. Analyze more reviews first.', 'woo-ai-review-manager' ),
					'refresh'       => __( 'Refresh', 'woo-ai-review-manager' ),
					'last_updated'  => __( 'Last updated:', 'woo-ai-review-manager' ),
				],
			]
		);
	}

	/**
	 * AJAX: Generate insights for a specific category.
	 */
	public function ajax_generate_insight(): void {
		check_ajax_referer( 'wairm_insights', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-ai-review-manager' ) ], 403 );
		}

		$category = sanitize_key( $_POST['category'] ?? '' );
		if ( ! isset( self::CATEGORIES[ $category ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid category.', 'woo-ai-review-manager' ) ] );
		}

		$force_refresh = ! empty( $_POST['refresh'] );

		// Check cache first.
		if ( ! $force_refresh ) {
			$cached = get_transient( self::CACHE_PREFIX . $category );
			if ( false !== $cached ) {
				wp_send_json_success( $cached );
			}
		}

		// Gather review data.
		$reviews = $this->get_reviews_for_insights( $category );
		if ( count( $reviews ) < 3 ) {
			wp_send_json_error( [ 'message' => __( 'Not enough reviews to generate meaningful insights. At least 3 analyzed reviews are needed.', 'woo-ai-review-manager' ) ] );
		}

		if ( ! \WooAIReviewManager\AI_Client::is_available() ) {
			wp_send_json_error( [ 'message' => __( 'AI Client is not available.', 'woo-ai-review-manager' ) ] );
		}

		try {
			$client  = new \WooAIReviewManager\AI_Client();
			$insight = $client->generate_insights( $reviews, $category );
		} catch ( \RuntimeException $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}

		$result = [
			'html'       => $insight,
			'generated'  => current_time( 'mysql' ),
			'review_count' => count( $reviews ),
		];

		// Cache the result.
		set_transient( self::CACHE_PREFIX . $category, $result, self::CACHE_TTL );

		wp_send_json_success( $result );
	}

	/**
	 * Gather review data relevant to the insight category.
	 *
	 * @return array<int, array{product: string, author: string, content: string, sentiment: string, score: float, date: string, key_phrases: string}>
	 */
	private function get_reviews_for_insights( string $category ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wairm_review_sentiment';

		$limit = 'product' === $category ? 100 : 50;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.sentiment, s.score, s.key_phrases, s.analyzed_at,
				        c.comment_content, c.comment_author, c.comment_date,
				        p.post_title AS product_name
				 FROM {$table} s
				 JOIN {$wpdb->comments} c ON c.comment_ID = s.comment_id
				 JOIN {$wpdb->posts} p ON p.ID = s.product_id
				 ORDER BY s.analyzed_at DESC
				 LIMIT %d",
				$limit
			)
		);

		$reviews = [];
		foreach ( $rows as $row ) {
			$reviews[] = [
				'product'     => $row->product_name,
				'author'      => $row->comment_author,
				'content'     => wp_strip_all_tags( $row->comment_content ),
				'sentiment'   => $row->sentiment,
				'score'       => (float) $row->score,
				'date'        => $row->comment_date,
				'key_phrases' => $row->key_phrases,
			];
		}

		return $reviews;
	}

	public function render_page(): void {
		$active_tab   = sanitize_key( $_GET['tab'] ?? 'product' );
		if ( ! isset( self::CATEGORIES[ $active_tab ] ) ) {
			$active_tab = 'product';
		}

		$base_url = admin_url( 'admin.php?page=wairm-insights' );
		?>
		<div class="wrap wairm-insights">
			<h1><?php esc_html_e( 'Review Insights', 'woo-ai-review-manager' ); ?></h1>
			<hr class="wp-header-end">

			<nav class="nav-tab-wrapper wairm-insights-tabs">
				<?php foreach ( self::CATEGORIES as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $slug, $base_url ) ); ?>"
					   class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>"
					   data-tab="<?php echo esc_attr( $slug ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="wairm-insights-content" data-category="<?php echo esc_attr( $active_tab ); ?>">
				<div class="wairm-insights-header">
					<p class="wairm-insights-description">
						<?php $this->render_tab_description( $active_tab ); ?>
					</p>
					<div class="wairm-insights-actions">
						<button type="button" class="button" id="wairm-refresh-insight" title="<?php esc_attr_e( 'Refresh insights', 'woo-ai-review-manager' ); ?>">
							<span class="dashicons dashicons-update" style="line-height: 1.4;"></span>
							<?php esc_html_e( 'Refresh', 'woo-ai-review-manager' ); ?>
						</button>
						<span class="wairm-insights-meta" id="wairm-insight-meta"></span>
					</div>
				</div>

				<div id="wairm-insight-output" class="wairm-insight-output">
					<div class="wairm-insight-loading">
						<span class="spinner is-active" style="float: none; margin: 0 8px 0 0;"></span>
						<?php esc_html_e( 'Analyzing reviews...', 'woo-ai-review-manager' ); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the description text for each tab.
	 */
	private function render_tab_description( string $tab ): void {
		switch ( $tab ) {
			case 'product':
				esc_html_e( 'Quality issues, strengths, and recurring patterns per product.', 'woo-ai-review-manager' );
				break;
			case 'trends':
				esc_html_e( 'Sentiment changes over time and emerging issues.', 'woo-ai-review-manager' );
				break;
			case 'operational':
				esc_html_e( 'Shipping, fulfillment, price perception, and expectations vs reality.', 'woo-ai-review-manager' );
				break;
			case 'strategic':
				esc_html_e( 'Feature requests, product ideas, and competitive insights.', 'woo-ai-review-manager' );
				break;
		}
	}
}
