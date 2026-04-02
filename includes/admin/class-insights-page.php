<?php
/**
 * Admin page for AI-powered review insights.
 *
 * Provides actionable insights from customer reviews across four categories:
 * Product-Level, Trends, Operational, and Strategic. Results are persisted
 * in the database with full history.
 *
 * @package WooAIReviewManager
 */

declare(strict_types=1);

namespace WooAIReviewManager\Admin;

defined( 'ABSPATH' ) || exit;

final class Insights_Page {

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
		add_action( 'wp_ajax_wairm_load_insight', [ $this, 'ajax_load_insight' ] );
		add_action( 'wp_ajax_wairm_load_history', [ $this, 'ajax_load_history' ] );
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
					'generating'   => __( 'Analyzing reviews...', 'woo-ai-review-manager' ),
					'error'        => __( 'Failed to generate insights. Please try again.', 'woo-ai-review-manager' ),
					'no_data'      => __( 'No insights generated yet for this category. Click "Generate" to create the first one.', 'woo-ai-review-manager' ),
					'confirm_new'  => __( 'Generate a new insight analysis? This will use AI credits.', 'woo-ai-review-manager' ),
					'reviews'      => __( 'reviews', 'woo-ai-review-manager' ),
				],
			]
		);
	}

	/**
	 * AJAX: Generate a new insight and save to DB.
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

		$reviews = $this->get_reviews_for_insights( $category );
		if ( count( $reviews ) < 3 ) {
			wp_send_json_error( [ 'message' => __( 'Not enough reviews to generate meaningful insights. At least 3 analyzed reviews are needed.', 'woo-ai-review-manager' ) ] );
		}

		if ( ! \WooAIReviewManager\AI_Client::is_available() ) {
			wp_send_json_error( [ 'message' => __( 'AI Client is not available.', 'woo-ai-review-manager' ) ] );
		}

		try {
			$client = new \WooAIReviewManager\AI_Client();
			$html   = $client->generate_insights( $reviews, $category );
		} catch ( \RuntimeException $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[WAIRM] Insight generation failed for ' . $category . ': ' . $e->getMessage() );
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}

		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'wairm_insights',
			[
				'category'     => $category,
				'content'      => $html,
				'review_count' => count( $reviews ),
				'generated_at' => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%d', '%s' ]
		);

		$insight_id = $wpdb->insert_id;
		if ( ! $insight_id ) {
			wp_send_json_error( [ 'message' => __( 'Failed to save insight.', 'woo-ai-review-manager' ) ] );
		}

		// Return the new insight plus updated history.
		$history = $this->get_history( $category );

		wp_send_json_success( [
			'id'           => $insight_id,
			'html'         => $html,
			'review_count' => count( $reviews ),
			'generated_at' => current_time( 'mysql' ),
			'history'      => $history,
		] );
	}

	/**
	 * AJAX: Load a specific insight by ID.
	 */
	public function ajax_load_insight(): void {
		check_ajax_referer( 'wairm_insights', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-ai-review-manager' ) ], 403 );
		}

		global $wpdb;

		$insight_id = absint( $_POST['insight_id'] ?? 0 );
		if ( ! $insight_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'woo-ai-review-manager' ) ] );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wairm_insights WHERE id = %d",
				$insight_id
			)
		);

		if ( ! $row ) {
			wp_send_json_error( [ 'message' => __( 'Insight not found.', 'woo-ai-review-manager' ) ] );
		}

		wp_send_json_success( [
			'id'           => (int) $row->id,
			'html'         => $row->content,
			'review_count' => (int) $row->review_count,
			'generated_at' => $row->generated_at,
		] );
	}

	/**
	 * AJAX: Load history list for a category.
	 */
	public function ajax_load_history(): void {
		check_ajax_referer( 'wairm_insights', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-ai-review-manager' ) ], 403 );
		}

		$category = sanitize_key( $_POST['category'] ?? '' );
		if ( ! isset( self::CATEGORIES[ $category ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid category.', 'woo-ai-review-manager' ) ] );
		}

		wp_send_json_success( $this->get_history( $category ) );
	}

	/**
	 * Get insight history entries for a category.
	 *
	 * @return array<int, array{id: int, generated_at: string, review_count: int}>
	 */
	private function get_history( string $category ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, generated_at, review_count
				 FROM {$wpdb->prefix}wairm_insights
				 WHERE category = %s
				 ORDER BY generated_at DESC
				 LIMIT 50",
				$category
			)
		);

		$history = [];
		foreach ( $rows as $row ) {
			$history[] = [
				'id'           => (int) $row->id,
				'generated_at' => $row->generated_at,
				'review_count' => (int) $row->review_count,
			];
		}

		return $history;
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
		global $wpdb;

		$active_tab = sanitize_key( $_GET['tab'] ?? 'product' );
		if ( ! isset( self::CATEGORIES[ $active_tab ] ) ) {
			$active_tab = 'product';
		}

		// Get the latest insight and history for the active tab.
		$latest = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wairm_insights
				 WHERE category = %s
				 ORDER BY generated_at DESC
				 LIMIT 1",
				$active_tab
			)
		);

		$history = $this->get_history( $active_tab );

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
						<?php if ( ! empty( $history ) ) : ?>
						<select id="wairm-insight-history" class="wairm-insight-history">
							<?php foreach ( $history as $entry ) : ?>
								<option value="<?php echo absint( $entry['id'] ); ?>">
									<?php
									echo esc_html(
										wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $entry['generated_at'] ) )
										. ' (' . $entry['review_count'] . ' ' . __( 'reviews', 'woo-ai-review-manager' ) . ')'
									);
									?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php endif; ?>
						<button type="button" class="button button-primary" id="wairm-generate-insight">
							<span class="dashicons dashicons-update" style="line-height: 1.4;"></span>
							<?php echo $latest ? esc_html__( 'Generate New', 'woo-ai-review-manager' ) : esc_html__( 'Generate', 'woo-ai-review-manager' ); ?>
						</button>
					</div>
				</div>

				<div id="wairm-insight-output" class="wairm-insight-output">
					<?php if ( $latest ) : ?>
						<div class="wairm-insight-body">
							<?php echo wp_kses_post( $latest->content ); ?>
						</div>
					<?php else : ?>
						<div class="wairm-insight-empty">
							<span class="dashicons dashicons-lightbulb" style="font-size: 48px; width: 48px; height: 48px; color: #c3c4c7;"></span>
							<p><?php esc_html_e( 'No insights generated yet for this category.', 'woo-ai-review-manager' ); ?></p>
							<p class="description"><?php esc_html_e( 'Click "Generate" to analyze your reviews and create actionable insights.', 'woo-ai-review-manager' ); ?></p>
						</div>
					<?php endif; ?>
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
