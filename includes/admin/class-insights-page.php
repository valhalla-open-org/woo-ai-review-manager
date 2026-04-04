<?php
/**
 * Admin page for AI-powered review insights.
 *
 * Provides actionable insights from customer reviews across four categories:
 * Product-Level, Trends, Operational, and Strategic. Results are persisted
 * in the database with full history. Reviews are filtered by time period
 * and sampled proportionally by sentiment when exceeding limits.
 *
 * @package WooAIReviewManager
 */

declare(strict_types=1);

namespace WooAIReviewManager\Admin;

defined( 'ABSPATH' ) || exit;

final class Insights_Page {

	private const CATEGORIES = [
		'product'     => 'Product-Level',
		'trends'      => 'Trends',
		'operational' => 'Operational',
		'strategic'   => 'Strategic',
	];

	private const CATEGORY_DESCRIPTIONS = [
		'product'     => 'Quality issues, strengths, and recurring patterns per product.',
		'trends'      => 'Sentiment changes over time and emerging issues.',
		'operational' => 'Shipping, fulfillment, price perception, and expectations vs reality.',
		'strategic'   => 'Feature requests, product ideas, and competitive insights.',
	];

	private const PERIODS = [
		'30'  => 'Last 30 days',
		'90'  => 'Last 90 days',
		'all' => 'All time',
	];

	/** @var int Maximum reviews to send to AI per request. */
	private const MAX_REVIEWS = 30;

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_submenu_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_wairm_generate_insight', [ $this, 'ajax_generate_insight' ] );
		add_action( 'wp_ajax_wairm_load_insight', [ $this, 'ajax_load_insight' ] );
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

		// Retrieve initial data to embed in the page for JS rendering.
		global $wpdb;

		$active_tab = sanitize_key( $_GET['tab'] ?? 'product' );
		if ( ! isset( self::CATEGORIES[ $active_tab ] ) ) {
			$active_tab = 'product';
		}
		if ( ! warc_fs()->is_paying() && 'product' !== $active_tab ) {
			$active_tab = 'product';
		}

		$initial_data = null;
		$initial_html = null;
		$page_history = $this->get_history( $active_tab );
		if ( ! empty( $page_history ) ) {
			$raw = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT content FROM {$wpdb->prefix}wairm_insights WHERE id = %d",
					$page_history[0]['id']
				)
			);
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$initial_data = $decoded;
			} else {
				$initial_html = $raw;
			}
		}

		wp_localize_script(
			'wairm-insights',
			'wairmInsights',
			[
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'wairm_insights' ),
				'initial_data'  => $initial_data,
				'initial_html'  => $initial_html,
				'product_urls'  => $this->get_product_url_map(),
				'i18n'         => [
					'generating'       => __( 'Analyzing reviews...', 'woo-ai-review-manager' ),
					'error'            => __( 'Failed to generate insights. Please try again.', 'woo-ai-review-manager' ),
					'reviews'          => __( 'reviews', 'woo-ai-review-manager' ),
					'no_data'          => __( 'Insufficient data', 'woo-ai-review-manager' ),
					'strengths'        => __( 'Strengths', 'woo-ai-review-manager' ),
					'complaints'       => __( 'Complaints', 'woo-ai-review-manager' ),
					'sizing'           => __( 'Sizing', 'woo-ai-review-manager' ),
					'priority_action'  => __( 'Priority Action', 'woo-ai-review-manager' ),
					'emerging_issues'  => __( 'Emerging Issues', 'woo-ai-review-manager' ),
					'product_shifts'   => __( 'Product Shifts', 'woo-ai-review-manager' ),
					'patterns'         => __( 'Patterns', 'woo-ai-review-manager' ),
					'shipping'         => __( 'Shipping & Fulfillment', 'woo-ai-review-manager' ),
					'expectations'     => __( 'Expectations vs Reality', 'woo-ai-review-manager' ),
					'price_value'      => __( 'Price & Value', 'woo-ai-review-manager' ),
					'support'          => __( 'Customer Support', 'woo-ai-review-manager' ),
					'priority_actions' => __( 'Priority Actions', 'woo-ai-review-manager' ),
					'feature_requests' => __( 'Feature Requests', 'woo-ai-review-manager' ),
					'competitive'      => __( 'Competitive Mentions', 'woo-ai-review-manager' ),
					'repeat_signals'   => __( 'Repeat Purchase Signals', 'woo-ai-review-manager' ),
					'marketing_quotes' => __( 'Marketing Quotes', 'woo-ai-review-manager' ),
					'improving'        => __( 'Improving', 'woo-ai-review-manager' ),
					'stable'           => __( 'Stable', 'woo-ai-review-manager' ),
					'declining'        => __( 'Declining', 'woo-ai-review-manager' ),
					'positive'         => __( 'Positive', 'woo-ai-review-manager' ),
					'mixed'            => __( 'Mixed', 'woo-ai-review-manager' ),
					'negative'         => __( 'Negative', 'woo-ai-review-manager' ),
					'mentions'         => __( 'mentions', 'woo-ai-review-manager' ),
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

		if ( ! warc_fs()->is_paying() && 'product' !== $category ) {
			wp_send_json_error( [ 'message' => __( 'This insight category requires a Pro license.', 'woo-ai-review-manager' ) ], 403 );
		}

		$period = sanitize_key( $_POST['period'] ?? '90' );
		if ( ! isset( self::PERIODS[ $period ] ) ) {
			$period = '90';
		}

		$reviews = $this->get_reviews_for_insights( $period );
		if ( count( $reviews ) < 3 ) {
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %s: period label */
					__( 'Not enough reviews for "%s" to generate meaningful insights. At least 3 analyzed reviews are needed.', 'woo-ai-review-manager' ),
					self::PERIODS[ $period ]
				),
			] );
		}

		if ( ! \WooAIReviewManager\AI_Client::is_available() ) {
			wp_send_json_error( [ 'message' => __( 'AI Client is not available.', 'woo-ai-review-manager' ) ] );
		}

		// Sample if we have more than the limit.
		$sampled = $this->sample_reviews( $reviews );

		try {
			$client = new \WooAIReviewManager\AI_Client();
			$data   = $client->generate_insights( $sampled, $category, self::period_label( $period ) );
		} catch ( \RuntimeException $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[WAIRM] Insight generation failed for ' . $category . ': ' . $e->getMessage() );
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}

		global $wpdb;

		$review_count = count( $sampled );
		$generated_at = current_time( 'mysql' );

		$wpdb->insert(
			$wpdb->prefix . 'wairm_insights',
			[
				'category'     => $category,
				'period'       => $period,
				'content'      => wp_json_encode( $data ),
				'review_count' => $review_count,
				'generated_at' => $generated_at,
			],
			[ '%s', '%s', '%s', '%d', '%s' ]
		);

		$insight_id = $wpdb->insert_id;
		if ( ! $insight_id ) {
			wp_send_json_error( [ 'message' => __( 'Failed to save insight.', 'woo-ai-review-manager' ) ] );
		}

		$history = $this->get_history( $category );

		wp_send_json_success( [
			'id'           => $insight_id,
			'category'     => $category,
			'data'         => $data,
			'review_count' => $review_count,
			'period'       => $period,
			'generated_at' => $generated_at,
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
				"SELECT id, category, content, review_count, period, generated_at
				 FROM {$wpdb->prefix}wairm_insights WHERE id = %d",
				$insight_id
			)
		);

		if ( ! $row ) {
			wp_send_json_error( [ 'message' => __( 'Insight not found.', 'woo-ai-review-manager' ) ] );
		}

		if ( ! warc_fs()->is_paying() && 'product' !== $row->category ) {
			wp_send_json_error( [ 'message' => __( 'This insight category requires a Pro license.', 'woo-ai-review-manager' ) ], 403 );
		}

		$data = json_decode( $row->content, true );

		wp_send_json_success( [
			'id'           => (int) $row->id,
			'category'     => $row->category,
			'data'         => is_array( $data ) ? $data : null,
			'html'         => ! is_array( $data ) ? $row->content : null,
			'review_count' => (int) $row->review_count,
			'period'       => $row->period,
			'generated_at' => $row->generated_at,
		] );
	}

	/**
	 * Get insight history entries for a category.
	 *
	 * @return array<int, array{id: int, generated_at: string, review_count: int, period: string}>
	 */
	private function get_history( string $category ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, generated_at, review_count, period
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
				'period'       => $row->period ?? 'all',
			];
		}

		return $history;
	}

	/**
	 * Gather reviews filtered by time period.
	 *
	 * @return array<int, array{product: string, content: string, sentiment: string, score: float, date: string}>
	 */
	private function get_reviews_for_insights( string $period ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wairm_review_sentiment';

		$date_filter = '';
		if ( 'all' !== $period ) {
			$days = absint( $period );
			$date_filter = $wpdb->prepare(
				' AND c.comment_date >= %s',
				gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) )
			);
		}

		// Fetch more than MAX_REVIEWS so we can sample proportionally.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $date_filter built via prepare().
		$rows = $wpdb->get_results(
			"SELECT s.sentiment, s.score, s.product_id,
			        c.comment_content, c.comment_date,
			        p.post_title AS product_name
			 FROM {$table} s
			 JOIN {$wpdb->comments} c ON c.comment_ID = s.comment_id
			 JOIN {$wpdb->posts} p ON p.ID = s.product_id
			 WHERE 1=1{$date_filter}
			 ORDER BY c.comment_date DESC"
		);

		$reviews = [];
		foreach ( $rows as $row ) {
			$reviews[] = [
				'product'    => $row->product_name,
				'product_id' => (int) $row->product_id,
				'content'    => wp_strip_all_tags( $row->comment_content ),
				'sentiment'  => $row->sentiment,
				'score'      => (float) $row->score,
				'date'       => $row->comment_date,
			];
		}

		return $reviews;
	}

	/**
	 * Sample reviews proportionally by sentiment if exceeding MAX_REVIEWS.
	 *
	 * Maintains the same positive/neutral/negative ratio as the full set.
	 *
	 * @param array $reviews Full review set.
	 * @return array Sampled subset (max MAX_REVIEWS).
	 */
	private function sample_reviews( array $reviews ): array {
		if ( count( $reviews ) <= self::MAX_REVIEWS ) {
			return $reviews;
		}

		// Group by sentiment.
		$buckets = [ 'positive' => [], 'neutral' => [], 'negative' => [] ];
		foreach ( $reviews as $review ) {
			$s = $review['sentiment'];
			if ( isset( $buckets[ $s ] ) ) {
				$buckets[ $s ][] = $review;
			}
		}

		$total   = count( $reviews );
		$sampled = [];

		foreach ( $buckets as $sentiment_reviews ) {
			if ( empty( $sentiment_reviews ) ) {
				continue;
			}
			// Proportional allocation, at least 1 per non-empty bucket.
			$share = max( 1, (int) round( ( count( $sentiment_reviews ) / $total ) * self::MAX_REVIEWS ) );
			// Shuffle to get a random sample, then take the share.
			shuffle( $sentiment_reviews );
			$sampled = array_merge( $sampled, array_slice( $sentiment_reviews, 0, $share ) );
		}

		// Trim to MAX_REVIEWS if rounding caused overshoot.
		if ( count( $sampled ) > self::MAX_REVIEWS ) {
			$sampled = array_slice( $sampled, 0, self::MAX_REVIEWS );
		}

		// Sort by date so the AI sees chronological order.
		usort( $sampled, static function ( $a, $b ) {
			return strcmp( $a['date'], $b['date'] );
		} );

		return $sampled;
	}

	/**
	 * Get a period label for display.
	 */
	private static function period_label( string $period ): string {
		return self::PERIODS[ $period ] ?? self::PERIODS['all'];
	}

	/**
	 * Build a product name => URL map from reviewed products.
	 */
	private function get_product_url_map(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wairm_review_sentiment';

		$rows = $wpdb->get_results(
			"SELECT DISTINCT s.product_id, p.post_title AS product_name
			 FROM {$table} s
			 JOIN {$wpdb->posts} p ON p.ID = s.product_id"
		);

		$map = [];
		foreach ( $rows as $row ) {
			$url = get_permalink( (int) $row->product_id );
			if ( $url ) {
				$map[ $row->product_name ] = $url;
			}
		}

		return $map;
	}

	public function render_page(): void {
		global $wpdb;

		$active_tab = sanitize_key( $_GET['tab'] ?? 'product' );
		if ( ! isset( self::CATEGORIES[ $active_tab ] ) ) {
			$active_tab = 'product';
		}

		// Free users can only access the product tab.
		if ( ! warc_fs()->is_paying() && 'product' !== $active_tab ) {
			$active_tab = 'product';
		}

		$history      = $this->get_history( $active_tab );
		$has_insights = ! empty( $history );

		$has_json_data  = false;
		$latest_html    = null;
		if ( $has_insights ) {
			$latest_raw = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT content FROM {$wpdb->prefix}wairm_insights WHERE id = %d",
					$history[0]['id']
				)
			);
			$decoded = json_decode( $latest_raw, true );
			if ( is_array( $decoded ) ) {
				$has_json_data = true; // JS will render from wairmInsights.initial_data.
			} else {
				$latest_html = $latest_raw;
			}
		}

		$base_url = admin_url( 'admin.php?page=wairm-insights' );
		?>
		<div class="wrap wairm-insights">
			<h1><?php esc_html_e( 'Review Insights', 'woo-ai-review-manager' ); ?></h1>
			<hr class="wp-header-end">

			<?php $is_paying = warc_fs()->is_paying(); ?>
			<nav class="nav-tab-wrapper wairm-insights-tabs">
				<?php foreach ( self::CATEGORIES as $slug => $label ) : ?>
					<?php $is_free_tab = 'product' === $slug; ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $slug, $base_url ) ); ?>"
					   class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?> <?php echo ! $is_paying && ! $is_free_tab ? 'wairm-pro-disabled' : ''; ?>"
					   data-tab="<?php echo esc_attr( $slug ); ?>"
					   <?php echo ! $is_paying && ! $is_free_tab ? 'onclick="return false;"' : ''; ?>>
						<?php echo esc_html( $label ); ?>
						<?php if ( ! $is_paying && ! $is_free_tab ) : ?>
							<span class="wairm-pro-badge" style="font-size:9px;padding:1px 4px;"><?php esc_html_e( 'Pro', 'woo-ai-review-manager' ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="wairm-insights-content" data-category="<?php echo esc_attr( $active_tab ); ?>">
				<div class="wairm-insights-header">
					<p class="wairm-insights-description">
						<?php echo esc_html( self::CATEGORY_DESCRIPTIONS[ $active_tab ] ?? '' ); ?>
					</p>
					<div class="wairm-insights-actions">
						<select id="wairm-insight-period" class="wairm-insight-period">
							<?php foreach ( self::PERIODS as $val => $label ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, '90' ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>

						<?php if ( $has_insights ) : ?>
						<select id="wairm-insight-history" class="wairm-insight-history">
							<?php foreach ( $history as $entry ) : ?>
								<option value="<?php echo absint( $entry['id'] ); ?>">
									<?php
									$period_text = self::period_label( $entry['period'] );
									echo esc_html(
										wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $entry['generated_at'] ) )
										. ' — ' . $period_text
										. ' (' . $entry['review_count'] . ' ' . __( 'reviews', 'woo-ai-review-manager' ) . ')'
									);
									?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php endif; ?>

						<button type="button" class="button button-primary" id="wairm-generate-insight">
							<span class="dashicons dashicons-update"></span>
							<?php echo $has_insights ? esc_html__( 'Generate New', 'woo-ai-review-manager' ) : esc_html__( 'Generate', 'woo-ai-review-manager' ); ?>
						</button>
					</div>
				</div>

				<div id="wairm-insight-output" class="wairm-insight-output">
					<?php if ( $has_json_data ) : ?>
						<!-- JS renders cards from wairmInsights.initial_data -->
					<?php elseif ( $latest_html ) : ?>
						<div class="wairm-insight-body">
							<?php echo wp_kses_post( $latest_html ); ?>
						</div>
					<?php else : ?>
						<div class="wairm-insight-empty">
							<div class="wairm-insight-empty-icon">
								<span class="dashicons dashicons-lightbulb"></span>
							</div>
							<p><?php esc_html_e( 'No insights generated yet for this category.', 'woo-ai-review-manager' ); ?></p>
							<p class="description"><?php esc_html_e( 'Select a time period and click "Generate" to analyze your reviews.', 'woo-ai-review-manager' ); ?></p>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}
}
