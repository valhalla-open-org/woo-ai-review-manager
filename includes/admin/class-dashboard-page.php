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
			[ 'jquery', 'wairm-charts' ],
			WAIRM_VERSION,
			true
		);

		wp_localize_script(
			'wairm-dashboard',
			'wairm',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wairm_dashboard' ),
			]
		);
	}

	public function render_page(): void {
		global $wpdb;

		// Get overall sentiment stats.
		$stats = $wpdb->get_row(
			"SELECT
				COUNT(*) as total_reviews,
				SUM(CASE WHEN sentiment = 'positive' THEN 1 ELSE 0 END) as positive,
				SUM(CASE WHEN sentiment = 'neutral' THEN 1 ELSE 0 END) as neutral,
				SUM(CASE WHEN sentiment = 'negative' THEN 1 ELSE 0 END) as negative,
				AVG(score) as avg_score
			 FROM {$wpdb->prefix}wairm_review_sentiment"
		);

		// Get recent reviews with sentiment.
		$recent = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, c.comment_content, c.comment_date, p.post_title as product_name
				 FROM {$wpdb->prefix}wairm_review_sentiment s
				 JOIN {$wpdb->comments} c ON c.comment_ID = s.comment_id
				 JOIN {$wpdb->posts} p ON p.ID = s.product_id
				 ORDER BY s.analyzed_at DESC
				 LIMIT %d",
				10
			)
		);

		// Get top products by review count.
		$top_products = $wpdb->get_results(
			"SELECT
				product_id,
				COUNT(*) as review_count,
				AVG(score) as avg_score,
				p.post_title as product_name
			 FROM {$wpdb->prefix}wairm_review_sentiment s
			 JOIN {$wpdb->posts} p ON p.ID = s.product_id
			 WHERE p.post_type = 'product'
			 GROUP BY product_id
			 ORDER BY review_count DESC
			 LIMIT 5"
		);
		?>
		<div class="wrap wairm-dashboard">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'AI Review Manager Dashboard', 'woo-ai-review-manager' ); ?></h1>

			<div class="wairm-stats-grid">
				<div class="wairm-stat-card">
					<h3><?php esc_html_e( 'Total Reviews Analyzed', 'woo-ai-review-manager' ); ?></h3>
					<div class="stat-number"><?php echo absint( $stats->total_reviews ?? 0 ); ?></div>
				</div>

				<div class="wairm-stat-card positive">
					<h3><?php esc_html_e( 'Positive', 'woo-ai-review-manager' ); ?></h3>
					<div class="stat-number"><?php echo absint( $stats->positive ?? 0 ); ?></div>
					<?php if ( $stats->total_reviews > 0 ) : ?>
						<div class="stat-percent">
							<?php echo esc_html( round( ( $stats->positive / $stats->total_reviews ) * 100, 1 ) ); ?>%
						</div>
					<?php endif; ?>
				</div>

				<div class="wairm-stat-card neutral">
					<h3><?php esc_html_e( 'Neutral', 'woo-ai-review-manager' ); ?></h3>
					<div class="stat-number"><?php echo absint( $stats->neutral ?? 0 ); ?></div>
					<?php if ( $stats->total_reviews > 0 ) : ?>
						<div class="stat-percent">
							<?php echo esc_html( round( ( $stats->neutral / $stats->total_reviews ) * 100, 1 ) ); ?>%
						</div>
					<?php endif; ?>
				</div>

				<div class="wairm-stat-card negative">
					<h3><?php esc_html_e( 'Negative', 'woo-ai-review-manager' ); ?></h3>
					<div class="stat-number"><?php echo absint( $stats->negative ?? 0 ); ?></div>
					<?php if ( $stats->total_reviews > 0 ) : ?>
						<div class="stat-percent">
							<?php echo esc_html( round( ( $stats->negative / $stats->total_reviews ) * 100, 1 ) ); ?>%
						</div>
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
								<div class="review-score">Score: <?php echo esc_html( number_format( $review->score, 2 ) ); ?></div>
								<?php if ( $review->ai_response_suggestion ) : ?>
									<div class="ai-suggestion">
										<strong><?php esc_html_e( 'AI Response Suggestion:', 'woo-ai-review-manager' ); ?></strong>
										<p><?php echo esc_html( wp_trim_words( $review->ai_response_suggestion, 20 ) ); ?></p>
										<button class="button button-small view-suggestion" data-suggestion="<?php echo esc_attr( wp_json_encode( $review->ai_response_suggestion ) ); ?>">
											<?php esc_html_e( 'View Full', 'woo-ai-review-manager' ); ?>
										</button>
									</div>
								<?php endif; ?>
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
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-settings' ) ); ?>" class="button button-primary">
							<?php esc_html_e( 'Settings', 'woo-ai-review-manager' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>" class="button">
							<?php esc_html_e( 'View Products', 'woo-ai-review-manager' ); ?>
						</a>
						<button class="button" id="wairm-analyze-old-reviews">
							<?php esc_html_e( 'Analyze Old Reviews', 'woo-ai-review-manager' ); ?>
						</button>
					</div>

					<div class="wairm-api-status" style="margin-top: 30px; padding: 15px; background: #f5f5f5; border-radius: 4px;">
						<h3><?php esc_html_e( 'AI Status', 'woo-ai-review-manager' ); ?></h3>
						<?php if ( \WooAIReviewManager\AI_Client::is_available() ) : ?>
							<p style="color: #2ecc71;">
								<strong><?php esc_html_e( 'WordPress AI Client is available.', 'woo-ai-review-manager' ); ?></strong>
							</p>
							<p><?php esc_html_e( 'Sentiment analysis and AI response generation are active.', 'woo-ai-review-manager' ); ?></p>
						<?php else : ?>
							<p style="color: #e74c3c;">
								<strong><?php esc_html_e( 'WordPress AI Client is not available.', 'woo-ai-review-manager' ); ?></strong>
							</p>
							<p><?php esc_html_e( 'Sentiment analysis and AI responses require the WordPress AI Client and a configured AI provider.', 'woo-ai-review-manager' ); ?></p>
							<a href="<?php echo esc_url( admin_url( 'options-general.php?page=ai-credentials' ) ); ?>" class="button button-secondary">
								<?php esc_html_e( 'Configure AI Credentials', 'woo-ai-review-manager' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>

		<script>
		document.addEventListener('DOMContentLoaded', function() {
			const ctx = document.getElementById('wairm-sentiment-chart').getContext('2d');
			new Chart(ctx, {
				type: 'pie',
				data: {
					labels: ['Positive', 'Neutral', 'Negative'],
					datasets: [{
						data: [
							<?php echo absint( $stats->positive ?? 0 ); ?>,
							<?php echo absint( $stats->neutral ?? 0 ); ?>,
							<?php echo absint( $stats->negative ?? 0 ); ?>
						],
						backgroundColor: [
							'#2ecc71',
							'#f39c12',
							'#e74c3c'
						]
					}]
				},
				options: {
					responsive: true,
					plugins: {
						legend: { position: 'bottom' }
					}
				}
			});

			// View suggestion modal.
			document.querySelectorAll('.view-suggestion').forEach(btn => {
				btn.addEventListener('click', function() {
					const suggestion = JSON.parse(this.dataset.suggestion);
					alert('AI Response Suggestion:\n\n' + suggestion);
				});
			});

			// Analyze old reviews.
			document.getElementById('wairm-analyze-old-reviews').addEventListener('click', function() {
				if (confirm('<?php esc_html_e( 'This will analyze up to 50 unanalyzed reviews. Continue?', 'woo-ai-review-manager' ); ?>')) {
					const btn = this;
					btn.disabled = true;
					btn.textContent = '<?php esc_html_e( 'Processing...', 'woo-ai-review-manager' ); ?>';

					fetch(ajaxurl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: new URLSearchParams({
							action: 'wairm_analyze_old_reviews',
							nonce: wairm.nonce
						})
					})
					.then(res => res.json())
					.then(data => {
						alert(data.message || '<?php esc_html_e( 'Processing complete.', 'woo-ai-review-manager' ); ?>');
						if (data.success) {
							location.reload();
						}
					})
					.catch(() => {
						alert('<?php esc_html_e( 'Request failed.', 'woo-ai-review-manager' ); ?>');
					})
					.finally(() => {
						btn.disabled = false;
						btn.textContent = '<?php esc_html_e( 'Analyze Old Reviews', 'woo-ai-review-manager' ); ?>';
					});
				}
			});
		});
		</script>
		<?php
	}
}