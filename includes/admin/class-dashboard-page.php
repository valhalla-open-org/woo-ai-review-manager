<?php
/**
 * Admin dashboard showing sentiment analytics.
 *
 * @package WooAIReviewManager
 */

declare(strict_types=1);

namespace WooAIReviewManager\Admin;

defined( 'ABSPATH' ) || exit;

final class Dashboard_Page {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_wairm_analyze_batch', [ $this, 'ajax_analyze_batch' ] );
	}

	public function add_menu_page(): void {
		add_menu_page(
			__( 'AI Review Manager', 'woo-ai-review-manager' ),
			__( 'AI Reviews', 'woo-ai-review-manager' ),
			'manage_woocommerce',
			'wairm-dashboard',
			[ $this, 'render_page' ],
			'dashicons-chart-bar',
			56
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_wairm-dashboard' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wairm-admin',
			WAIRM_PLUGIN_URL . 'assets/css/admin.css',
			[],
			WAIRM_VERSION
		);

		wp_enqueue_script(
			'wairm-charts',
			'https://cdn.jsdelivr.net/npm/chart.js',
			[],
			WAIRM_VERSION,
			true
		);

		wp_enqueue_script(
			'wairm-dashboard',
			WAIRM_PLUGIN_URL . 'assets/js/dashboard.js',
			[ 'wairm-charts' ],
			WAIRM_VERSION,
			true
		);
	}

	/**
	 * Localize dashboard script with chart data.
	 */
	private function localize_dashboard_data( object $stats, int $pending_count, int $actionable_responses ): void {
		wp_localize_script(
			'wairm-dashboard',
			'wairm',
			[
				'ajax_url'             => admin_url( 'admin-ajax.php' ),
				'nonce'                => wp_create_nonce( 'wairm_dashboard' ),
				'pending_count'        => $pending_count,
				'chart'                => [
					'positive' => absint( $stats->positive ?? 0 ),
					'neutral'  => absint( $stats->neutral ?? 0 ),
					'negative' => absint( $stats->negative ?? 0 ),
				],
				'i18n'                 => [
					'analyze_button'  => __( 'Analyze Old Reviews', 'woo-ai-review-manager' ),
					'analyzing'       => __( 'Analyzing...', 'woo-ai-review-manager' ),
					'batch_progress'  => /* translators: 1: processed, 2: total */ __( 'Analyzed %1$d of %2$d...', 'woo-ai-review-manager' ),
					'complete'        => __( 'All done! Reloading...', 'woo-ai-review-manager' ),
					'nothing'         => __( 'No unanalyzed reviews found.', 'woo-ai-review-manager' ),
					'error'           => __( 'An error occurred. Please try again.', 'woo-ai-review-manager' ),
					'chart_positive'  => __( 'Positive', 'woo-ai-review-manager' ),
					'chart_neutral'   => __( 'Neutral', 'woo-ai-review-manager' ),
					'chart_negative'  => __( 'Negative', 'woo-ai-review-manager' ),
					'no_chart_data'   => __( 'No sentiment data yet. Analyze some reviews to see the chart.', 'woo-ai-review-manager' ),
				],
			]
		);
	}

	/**
	 * AJAX handler: analyze one batch of reviews and report progress.
	 */
	public function ajax_analyze_batch(): void {
		check_ajax_referer( 'wairm_dashboard', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-ai-review-manager' ) ], 403 );
		}

		if ( ! \WooAIReviewManager\AI_Client::is_available() ) {
			wp_send_json_error( [ 'message' => __( 'AI Client is not available. Please configure AI credentials first.', 'woo-ai-review-manager' ) ] );
		}

		$result = \WooAIReviewManager\Sentiment_Analyzer::process_pending( 10 );

		wp_send_json_success( $result );
	}

	public function render_page(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'wairm_review_sentiment';

		// Overall sentiment stats.
		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total_reviews,
					SUM(CASE WHEN sentiment = %s THEN 1 ELSE 0 END) as positive,
					SUM(CASE WHEN sentiment = %s THEN 1 ELSE 0 END) as neutral,
					SUM(CASE WHEN sentiment = %s THEN 1 ELSE 0 END) as negative,
					AVG(score) as avg_score
				 FROM {$table}",
				'positive',
				'neutral',
				'negative'
			)
		);

		// Unanalyzed count.
		$pending_count = \WooAIReviewManager\Sentiment_Analyzer::count_pending();

		// Actionable responses count.
		$actionable_responses = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE ai_response_suggestion IS NOT NULL
				   AND ai_response_status IN (%s, %s)",
				'generated',
				'approved'
			)
		);

		$this->localize_dashboard_data( $stats, $pending_count, $actionable_responses );

		// Recent reviews.
		$recent = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, c.comment_content, c.comment_author, c.comment_date, p.post_title as product_name
				 FROM {$table} s
				 JOIN {$wpdb->comments} c ON c.comment_ID = s.comment_id
				 JOIN {$wpdb->posts} p ON p.ID = s.product_id
				 ORDER BY s.analyzed_at DESC
				 LIMIT %d",
				10
			)
		);

		// Top products.
		$top_products = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, COUNT(*) as review_count, AVG(score) as avg_score,
				        p.post_title as product_name
				 FROM {$table} s
				 JOIN {$wpdb->posts} p ON p.ID = s.product_id
				 WHERE p.post_type = %s
				 GROUP BY product_id
				 ORDER BY review_count DESC
				 LIMIT %d",
				'product',
				5
			)
		);
		?>
		<div class="wrap wairm-dashboard">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'AI Review Manager Dashboard', 'woo-ai-review-manager' ); ?></h1>

			<?php if ( $actionable_responses > 0 ) : ?>
			<div class="notice notice-info" style="margin: 15px 0;">
				<p>
					<?php
					printf(
						/* translators: 1: count, 2: link open, 3: link close */
						esc_html__( 'You have %1$s AI response suggestions waiting for review. %2$sManage Responses%3$s', 'woo-ai-review-manager' ),
						'<strong>' . esc_html( (string) $actionable_responses ) . '</strong>',
						'<a href="' . esc_url( admin_url( 'admin.php?page=wairm-responses' ) ) . '">',
						'</a>'
					);
					?>
				</p>
			</div>
			<?php endif; ?>

			<div class="wairm-stats-grid">
				<div class="wairm-stat-card">
					<h3><?php esc_html_e( 'Total Analyzed', 'woo-ai-review-manager' ); ?></h3>
					<div class="stat-number"><?php echo absint( $stats->total_reviews ?? 0 ); ?></div>
				</div>

				<div class="wairm-stat-card positive">
					<h3><?php esc_html_e( 'Positive', 'woo-ai-review-manager' ); ?></h3>
					<div class="stat-number"><?php echo absint( $stats->positive ?? 0 ); ?></div>
					<?php if ( $stats->total_reviews > 0 ) : ?>
						<div class="stat-percent"><?php echo esc_html( round( ( $stats->positive / $stats->total_reviews ) * 100, 1 ) ); ?>%</div>
					<?php endif; ?>
				</div>

				<div class="wairm-stat-card neutral">
					<h3><?php esc_html_e( 'Neutral', 'woo-ai-review-manager' ); ?></h3>
					<div class="stat-number"><?php echo absint( $stats->neutral ?? 0 ); ?></div>
					<?php if ( $stats->total_reviews > 0 ) : ?>
						<div class="stat-percent"><?php echo esc_html( round( ( $stats->neutral / $stats->total_reviews ) * 100, 1 ) ); ?>%</div>
					<?php endif; ?>
				</div>

				<div class="wairm-stat-card negative">
					<h3><?php esc_html_e( 'Negative', 'woo-ai-review-manager' ); ?></h3>
					<div class="stat-number"><?php echo absint( $stats->negative ?? 0 ); ?></div>
					<?php if ( $stats->total_reviews > 0 ) : ?>
						<div class="stat-percent"><?php echo esc_html( round( ( $stats->negative / $stats->total_reviews ) * 100, 1 ) ); ?>%</div>
					<?php endif; ?>
				</div>
			</div>

			<div class="wairm-chart-section">
				<h2><?php esc_html_e( 'Sentiment Distribution', 'woo-ai-review-manager' ); ?></h2>
				<div style="max-width: 500px;">
					<canvas id="wairm-sentiment-chart"></canvas>
				</div>
			</div>

			<div class="wairm-two-column">
				<div class="wairm-column">
					<h2><?php esc_html_e( 'Recent Reviews', 'woo-ai-review-manager' ); ?></h2>
					<?php if ( $recent ) : ?>
						<div class="wairm-recent-reviews">
							<?php foreach ( $recent as $review ) : ?>
							<div class="wairm-review-card sentiment-<?php echo esc_attr( $review->sentiment ); ?>">
								<div class="review-header">
									<span class="product-name"><?php echo esc_html( $review->product_name ); ?></span>
									<span class="sentiment-badge"><?php echo esc_html( ucfirst( $review->sentiment ) ); ?></span>
									<span class="review-date"><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $review->comment_date ) ) ); ?></span>
								</div>
								<div class="review-excerpt"><?php echo esc_html( wp_trim_words( $review->comment_content, 30 ) ); ?></div>
								<div class="review-meta">
									<span class="review-author"><?php echo esc_html( $review->comment_author ); ?></span>
									<span class="review-score"><?php esc_html_e( 'Score:', 'woo-ai-review-manager' ); ?> <?php echo esc_html( number_format( $review->score, 2 ) ); ?></span>
									<?php if ( $review->ai_response_suggestion && 'sent' !== $review->ai_response_status ) : ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-responses&status=actionable' ) ); ?>" class="button button-small">
											<?php esc_html_e( 'Respond', 'woo-ai-review-manager' ); ?>
										</a>
									<?php elseif ( 'sent' === $review->ai_response_status ) : ?>
										<span class="dashicons dashicons-yes-alt" style="color: #2ecc71;" title="<?php esc_attr_e( 'Reply posted', 'woo-ai-review-manager' ); ?>"></span>
									<?php endif; ?>
								</div>
							</div>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<p><?php esc_html_e( 'No reviews analyzed yet.', 'woo-ai-review-manager' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="wairm-column">
					<h2><?php esc_html_e( 'Top Products', 'woo-ai-review-manager' ); ?></h2>
					<?php if ( $top_products ) : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Product', 'woo-ai-review-manager' ); ?></th>
									<th><?php esc_html_e( 'Reviews', 'woo-ai-review-manager' ); ?></th>
									<th><?php esc_html_e( 'Avg. Score', 'woo-ai-review-manager' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $top_products as $product ) : ?>
								<tr>
									<td><?php echo esc_html( $product->product_name ); ?></td>
									<td><?php echo absint( $product->review_count ); ?></td>
									<td>
										<?php echo esc_html( number_format( $product->avg_score, 2 ) ); ?>
										<span class="score-bar" style="display: inline-block; width: 50px; height: 6px; background: #e0e0e0; margin-left: 10px; vertical-align: middle;">
											<span style="display: block; width: <?php echo esc_attr( $product->avg_score * 100 ); ?>%; height: 100%; background: <?php echo $product->avg_score > 0.65 ? '#2ecc71' : ( $product->avg_score > 0.35 ? '#f39c12' : '#e74c3c' ); ?>;"></span>
										</span>
									</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p><?php esc_html_e( 'No product data yet.', 'woo-ai-review-manager' ); ?></p>
					<?php endif; ?>

					<h2 style="margin-top: 30px;"><?php esc_html_e( 'Quick Actions', 'woo-ai-review-manager' ); ?></h2>
					<div class="wairm-quick-actions">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-responses' ) ); ?>" class="button button-primary">
							<?php esc_html_e( 'Manage Responses', 'woo-ai-review-manager' ); ?>
							<?php if ( $actionable_responses > 0 ) : ?>
								<span class="wairm-badge"><?php echo absint( $actionable_responses ); ?></span>
							<?php endif; ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-settings' ) ); ?>" class="button">
							<?php esc_html_e( 'Settings', 'woo-ai-review-manager' ); ?>
						</a>

						<?php if ( $pending_count > 0 ) : ?>
						<div id="wairm-analyze-section" style="margin-top: 15px;">
							<button class="button" id="wairm-analyze-old-reviews">
								<?php
								printf(
									/* translators: %d: number of unanalyzed reviews */
									esc_html__( 'Analyze %d Unanalyzed Reviews', 'woo-ai-review-manager' ),
									$pending_count
								);
								?>
							</button>
							<div id="wairm-analyze-progress" style="display: none; margin-top: 10px;">
								<div style="background: #e0e0e0; border-radius: 4px; overflow: hidden; height: 20px;">
									<div id="wairm-progress-bar" style="background: #3498db; height: 100%; width: 0%; transition: width 0.3s;"></div>
								</div>
								<p id="wairm-progress-text" style="margin: 5px 0; font-size: 13px; color: #666;"></p>
							</div>
						</div>
						<?php endif; ?>
					</div>

					<?php
					$text_supported  = \WooAIReviewManager\AI_Client::is_text_supported();
					$dash_providers  = \WooAIReviewManager\AI_Client::discover_providers();
					$dash_model_pref = get_option( 'wairm_model_preference', '' );
					?>
					<div class="wairm-api-status" style="margin-top: 30px; padding: 15px; background: #f5f5f5; border-radius: 4px;">
						<h3><?php esc_html_e( 'AI Status', 'woo-ai-review-manager' ); ?></h3>
						<?php if ( $text_supported ) : ?>
							<p style="color: #2ecc71;">
								<strong><?php esc_html_e( 'AI text generation is available.', 'woo-ai-review-manager' ); ?></strong>
							</p>
							<?php
							$configured_providers = array_filter( $dash_providers, static function ( $p ) {
								return $p['configured'];
							} );
							if ( ! empty( $configured_providers ) ) :
							?>
								<p>
									<?php
									$provider_names = wp_list_pluck( $configured_providers, 'name' );
									printf(
										/* translators: %s: comma-separated list of provider names */
										esc_html__( 'Active connectors: %s', 'woo-ai-review-manager' ),
										esc_html( implode( ', ', $provider_names ) )
									);
									?>
								</p>
							<?php endif; ?>
							<?php if ( ! empty( $dash_model_pref ) ) : ?>
								<p>
									<?php
									printf(
										/* translators: %s: model ID */
										esc_html__( 'Preferred model: %s', 'woo-ai-review-manager' ),
										'<code>' . esc_html( $dash_model_pref ) . '</code>'
									);
									?>
								</p>
							<?php endif; ?>
						<?php elseif ( \WooAIReviewManager\AI_Client::is_available() ) : ?>
							<p style="color: #e74c3c;">
								<strong><?php esc_html_e( 'No AI connectors configured for text generation.', 'woo-ai-review-manager' ); ?></strong>
							</p>
							<a href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>" class="button button-secondary">
								<?php esc_html_e( 'Configure AI Connectors', 'woo-ai-review-manager' ); ?>
							</a>
						<?php else : ?>
							<p style="color: #e74c3c;">
								<strong><?php esc_html_e( 'WordPress AI Client is not available.', 'woo-ai-review-manager' ); ?></strong>
							</p>
							<p><?php esc_html_e( 'This plugin requires WordPress 7.0 or later.', 'woo-ai-review-manager' ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
