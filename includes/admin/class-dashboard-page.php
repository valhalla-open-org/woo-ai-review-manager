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
			'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIGZpbGw9Im5vbmUiIHZpZXdCb3g9IjAgMCAyNCAyNCIgaGVpZ2h0PSIyMCIgd2lkdGg9IjIwIj48cGF0aCBmaWxsPSJibGFjayIgZD0iTTE3LjA4NTkgNC41YzAuNzgxLTAuNzgwNzkgMi4wNDcyLTAuNzgwNzggMi44MjgyIDBMMjEuNSA2LjA4NTk0YzAuNzgwOCAwLjc4MSAwLjc4MDggMi4wNDcxMiAwIDIuODI4MTJMOC43MDcwMyAyMS43MDdDOC41MTk1MSAyMS44OTQ1IDguMjY1MTYgMjIgOCAyMkg1Yy0wLjU1MjIxIDAtMC45OTk4OC0wLjQ0NzgtMS0xdi0zbDAuMDA0ODgtMC4wOTg2YzAuMDIyNzQtMC4yMjg5IDAuMTI0MDctMC40NDQ0IDAuMjg4MDktMC42MDg0ek02IDEuNWMwLjUwNDkzIDAgMC45MjY3OSAwLjMyNjQ0IDEuMDgwMDggMC43NzI0NmwwLjAyNzM0IDAuMDkwODIgMC4wNjY0MSAwLjIyODUyQzcuNTQ0MjcgMy43MjEgOC40NzU1IDQuNTk4MjYgOS42MzY3MiA0Ljg5MjU4IDEwLjEyODQgNS4wMTcxMiAxMC41IDUuNDYxNTEgMTAuNSA2YzAgMC41Mzg0OC0wLjM3MTcgMC45ODE5LTAuODYzMjggMS4xMDY0NS0xLjIzODY2IDAuMzEzODYtMi4yMTY0MyAxLjI5MTYxLTIuNTMwMjcgMi41MzAyN0M2Ljk4MTkxIDEwLjEyODQgNi41Mzg0OSAxMC41IDYgMTAuNXMtMC45ODE5MS0wLjM3MTYtMS4xMDY0NS0wLjg2MzI4QzQuNTc5NyA4LjM5ODA2IDMuNjAxOTQgNy40MjAzMSAyLjM2MzI4IDcuMTA2NDVjLTAuNDYwOTctMC4xMTY3OC0wLjgxNjg2LTAuNTEzODItMC44NTkzNy0xLjAwNjg0TDEuNSA2bDAuMDAzOTEtMC4wOTk2MWMwLjA0MjQ5LTAuNDkzMDUgMC4zOTgzOS0wLjg5MTAzIDAuODU5MzctMS4wMDc4MSAxLjIzODQ2LTAuMzEzOSAyLjIxNTM5LTEuMjkwODUgMi41MjkzLTIuNTI5M0M1LjAxNzEyIDEuODcxNjUgNS40NjE1MSAxLjUgNiAxLjVtOS43MDcgNy4yMDcwMyAxLjU4NiAxLjU4NTk3TDIwLjA4NTkgNy41IDE4LjUgNS45MTQwNnoiLz48L3N2Zz4=',
			56
		);

		// Override the auto-created first submenu label from "AI Reviews" to "Dashboard".
		add_submenu_page(
			'wairm-dashboard',
			__( 'Dashboard', 'woo-ai-review-manager' ),
			__( 'Dashboard', 'woo-ai-review-manager' ),
			'manage_woocommerce',
			'wairm-dashboard',
			[ $this, 'render_page' ]
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
			'wairm-dashboard',
			WAIRM_PLUGIN_URL . 'assets/js/dashboard.js',
			[],
			WAIRM_VERSION,
			true
		);
	}

	/**
	 * Localize dashboard script with chart data.
	 */
	private function localize_dashboard_data( object $stats, int $pending_count, int $actionable_responses, array $sparkline_data ): void {
		wp_localize_script(
			'wairm-dashboard',
			'wairm',
			[
				'ajax_url'        => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'wairm_dashboard' ),
				'pending_count'   => $pending_count,
				'sparkline_data'  => $sparkline_data,
				'i18n'            => [
					'analyze_button'  => __( 'Analyze Old Reviews', 'woo-ai-review-manager' ),
					'analyzing'       => __( 'Analyzing...', 'woo-ai-review-manager' ),
					'batch_progress'  => /* translators: 1: processed, 2: total */ __( 'Analyzed %1$d of %2$d...', 'woo-ai-review-manager' ),
					'complete'        => __( 'All done! Reloading...', 'woo-ai-review-manager' ),
					'nothing'         => __( 'No unanalyzed reviews found.', 'woo-ai-review-manager' ),
					'error'           => __( 'An error occurred. Please try again.', 'woo-ai-review-manager' ),
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

		$result = \WooAIReviewManager\Sentiment_Analyzer::process_pending( 1 );

		wp_send_json_success( $result );
	}

	/**
	 * Check setup completeness and return incomplete steps.
	 *
	 * @return array<string, array{label: string, url: string}>
	 */
	private function get_setup_checklist(): array {
		$incomplete = [];

		if ( ! \WooAIReviewManager\AI_Client::is_available() ) {
			$incomplete['ai_client'] = [
				'label' => __( 'WordPress 7.0+ with AI Client API required', 'woo-ai-review-manager' ),
				'url'   => '',
			];
		} elseif ( ! \WooAIReviewManager\AI_Client::is_text_supported() ) {
			$incomplete['ai_connector'] = [
				'label' => __( 'Configure an AI connector for text generation', 'woo-ai-review-manager' ),
				'url'   => admin_url( 'options-connectors.php' ),
			];
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			$incomplete['woocommerce'] = [
				'label' => __( 'WooCommerce must be installed and active', 'woo-ai-review-manager' ),
				'url'   => admin_url( 'plugins.php' ),
			];
		}

		$support_email = get_option( 'wairm_support_email', '' );
		if ( empty( $support_email ) ) {
			$incomplete['support_email'] = [
				'label' => __( 'Set a support email for AI responses', 'woo-ai-review-manager' ),
				'url'   => admin_url( 'admin.php?page=wairm-settings&tab=general' ),
			];
		}

		return $incomplete;
	}

	/**
	 * Get sparkline trend data for KPI cards.
	 *
	 * @param string $period Current period filter value.
	 * @return array{reviews: array, scores: array, conversions: array}
	 */
	private function get_sparkline_data( string $period ): array {
		global $wpdb;

		$sentiment_table   = $wpdb->prefix . 'wairm_review_sentiment';
		$invitations_table = $wpdb->prefix . 'wairm_review_invitations';

		$use_weekly = in_array( $period, [ '90', 'all' ], true );
		$group_expr = $use_weekly ? 'YEARWEEK(s.analyzed_at, 1)' : 'DATE(s.analyzed_at)';
		$inv_group  = $use_weekly ? 'YEARWEEK(i.sent_at, 1)' : 'DATE(i.sent_at)';

		$date_filter     = '';
		$inv_date_filter = '';
		if ( 'all' !== $period ) {
			$cutoff          = gmdate( 'Y-m-d H:i:s', strtotime( "-{$period} days" ) );
			$date_filter     = $wpdb->prepare( ' AND s.analyzed_at >= %s', $cutoff );
			$inv_date_filter = $wpdb->prepare( ' AND i.sent_at >= %s', $cutoff );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$review_trends = $wpdb->get_results(
			"SELECT {$group_expr} as period_key,
					COUNT(*) as review_count,
					AVG(score) as avg_score
			 FROM {$sentiment_table} s
			 WHERE 1=1 {$date_filter}
			 GROUP BY period_key
			 ORDER BY period_key ASC"
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$conversion_trends = $wpdb->get_results(
			"SELECT {$inv_group} as period_key,
					COUNT(*) as total_sent,
					SUM(CASE WHEN i.status = 'reviewed' THEN 1 ELSE 0 END) as reviewed
			 FROM {$invitations_table} i
			 WHERE i.status != 'pending' {$inv_date_filter}
			 GROUP BY period_key
			 ORDER BY period_key ASC"
		);

		return [
			'reviews'     => array_map( static fn( $r ) => (int) $r->review_count, $review_trends ),
			'scores'      => array_map( static fn( $r ) => round( (float) $r->avg_score, 2 ), $review_trends ),
			'conversions' => array_map( static function ( $r ) {
				return $r->total_sent > 0 ? round( ( $r->reviewed / $r->total_sent ) * 100, 1 ) : 0;
			}, $conversion_trends ),
		];
	}

	/**
	 * Get stats for the previous equivalent period (for delta calculation).
	 *
	 * @param string $period Current period filter value.
	 * @return object{total_reviews: int, avg_score: float, conversion_rate: float}
	 */
	private function get_previous_period_stats( string $period ): object {
		global $wpdb;

		$sentiment_table   = $wpdb->prefix . 'wairm_review_sentiment';
		$invitations_table = $wpdb->prefix . 'wairm_review_invitations';

		$defaults = (object) [
			'total_reviews'   => 0,
			'avg_score'       => 0.0,
			'conversion_rate' => 0.0,
		];

		if ( 'all' === $period ) {
			return $defaults;
		}

		$days       = (int) $period;
		$prev_end   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$prev_start = gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( $days * 2 ) . ' days' ) );

		$prev_sentiment = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) as total_reviews, AVG(score) as avg_score
				 FROM {$sentiment_table}
				 WHERE analyzed_at >= %s AND analyzed_at < %s",
				$prev_start,
				$prev_end
			)
		);

		$prev_email = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total_sent,
					SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as reviewed
				 FROM {$invitations_table}
				 WHERE status != 'pending'
				   AND sent_at >= %s AND sent_at < %s",
				$prev_start,
				$prev_end
			)
		);

		return (object) [
			'total_reviews'   => (int) ( $prev_sentiment->total_reviews ?? 0 ),
			'avg_score'       => round( (float) ( $prev_sentiment->avg_score ?? 0 ), 2 ),
			'conversion_rate' => ( $prev_email && $prev_email->total_sent > 0 )
				? round( ( $prev_email->reviewed / $prev_email->total_sent ) * 100, 1 )
				: 0.0,
		];
	}

	/**
	 * Get email funnel counts for the current period.
	 *
	 * @param string $date_where SQL WHERE fragment for date filtering.
	 * @return object{sent: int, clicked: int, reviewed: int}
	 */
	private function get_email_funnel( string $date_where ): object {
		global $wpdb;

		$invitations_table = $wpdb->prefix . 'wairm_review_invitations';
		$inv_date_where    = str_replace( 's.analyzed_at', 'i.sent_at', $date_where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			"SELECT
				COUNT(CASE WHEN i.status IN ('sent','clicked','reviewed','expired') THEN 1 END) as sent,
				COUNT(CASE WHEN i.status IN ('clicked','reviewed') THEN 1 END) as clicked,
				COUNT(CASE WHEN i.status = 'reviewed' THEN 1 END) as reviewed
			 FROM {$invitations_table} i
			 WHERE 1=1 {$inv_date_where}"
		);

		return (object) [
			'sent'     => (int) ( $row->sent ?? 0 ),
			'clicked'  => (int) ( $row->clicked ?? 0 ),
			'reviewed' => (int) ( $row->reviewed ?? 0 ),
		];
	}

	/**
	 * Generate an inline SVG sparkline from a data array.
	 *
	 * @param array<float|int> $data        Data points.
	 * @param bool             $is_positive Whether the trend is positive.
	 * @return string SVG markup.
	 */
	private function render_sparkline_svg( array $data, bool $is_positive ): string {
		if ( count( $data ) < 2 ) {
			return '';
		}

		$width  = 64;
		$height = 28;
		$color  = $is_positive ? 'var(--positive)' : 'var(--negative)';
		$count  = count( $data );
		$max    = max( $data );
		$min    = min( $data );
		$range  = $max - $min ?: 1;

		$points = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$x = round( ( $i / ( $count - 1 ) ) * $width, 1 );
			$y = round( $height - ( ( $data[ $i ] - $min ) / $range ) * ( $height - 4 ) - 2, 1 );
			$points[] = "{$x},{$y}";
		}

		$polyline  = implode( ' ', $points );
		$fill_path = "M{$points[0]} " . implode( ' L', array_slice( $points, 1 ) ) . " L{$width},{$height} L0,{$height} Z";

		$gradient_id = 'sg' . wp_unique_id();

		return sprintf(
			'<svg width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d" aria-hidden="true" focusable="false">
				<defs><linearGradient id="%3$s" x1="0" y1="0" x2="0" y2="1">
					<stop offset="0%%" stop-color="%4$s" stop-opacity="0.15"/>
					<stop offset="100%%" stop-color="%4$s" stop-opacity="0"/>
				</linearGradient></defs>
				<path d="%5$s" fill="url(#%3$s)"/>
				<polyline points="%6$s" fill="none" stroke="%4$s" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>',
			$width,
			$height,
			esc_attr( $gradient_id ),
			esc_attr( $color ),
			esc_attr( $fill_path ),
			esc_attr( $polyline )
		);
	}

	/**
	 * Format a delta value for display.
	 *
	 * @param float  $current  Current value.
	 * @param float  $previous Previous value.
	 * @param string $format   'percent' for % change, 'absolute' for raw difference.
	 * @param string $period   Current period ('all' = no delta shown).
	 * @return array{html: string, is_positive: bool}
	 */
	private function format_delta( float $current, float $previous, string $format, string $period ): array {
		if ( 'all' === $period || 0.0 === $previous ) {
			return [
				'html'        => '<span class="wairm-kpi-delta is-neutral">&mdash;</span>',
				'is_positive' => true,
			];
		}

		if ( 'percent' === $format ) {
			$change  = ( ( $current - $previous ) / $previous ) * 100;
			$display = ( $change >= 0 ? '+' : '' ) . round( $change, 1 ) . '%';
		} else {
			$change  = $current - $previous;
			$display = ( $change >= 0 ? '+' : '' ) . number_format( $change, 2 );
		}

		$is_positive = $change >= 0;
		$arrow       = $is_positive ? '&#9650;' : '&#9660;';
		$class       = $is_positive ? 'is-positive' : 'is-negative';

		$html = sprintf(
			'<span class="wairm-kpi-delta %s">%s %s <span class="delta-context">%s</span></span>',
			esc_attr( $class ),
			$arrow,
			esc_html( $display ),
			esc_html__( 'vs prev period', 'woo-ai-review-manager' )
		);

		return [
			'html'        => $html,
			'is_positive' => $is_positive,
		];
	}

	public function render_page(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'wairm_review_sentiment';

		// Date range filter.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter parameter.
		$period      = sanitize_key( $_GET['period'] ?? 'all' );
		$valid_periods = [ '7', '30', '90', 'all' ];
		if ( ! in_array( $period, $valid_periods, true ) ) {
			$period = 'all';
		}

		$date_where = '';
		if ( 'all' !== $period ) {
			$date_where = $wpdb->prepare( ' AND s.analyzed_at >= %s', gmdate( 'Y-m-d H:i:s', strtotime( "-{$period} days" ) ) );
		}

		// Overall sentiment stats.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $date_where built with prepare().
		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total_reviews,
					SUM(CASE WHEN sentiment = %s THEN 1 ELSE 0 END) as positive,
					SUM(CASE WHEN sentiment = %s THEN 1 ELSE 0 END) as neutral,
					SUM(CASE WHEN sentiment = %s THEN 1 ELSE 0 END) as negative,
					AVG(score) as avg_score
				 FROM {$table} s
				 WHERE 1=1 {$date_where}",
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

		// Failure stats for the error widget.
		$failed_emails = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wairm_email_queue WHERE status = 'failed'"
		);

		// New dashboard data.
		$sparkline_data = $this->get_sparkline_data( $period );
		$prev_stats     = $this->get_previous_period_stats( $period );
		$email_funnel   = $this->get_email_funnel( $date_where );

		// Current period conversion rate.
		$current_conversion = $email_funnel->sent > 0
			? round( ( $email_funnel->reviewed / $email_funnel->sent ) * 100, 1 )
			: 0.0;

		// Negative reviews needing response.
		$negative_needing_response = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE sentiment = %s
				   AND ai_response_suggestion IS NOT NULL
				   AND ai_response_status IN (%s, %s)",
				'negative',
				'generated',
				'approved'
			)
		);

		$this->localize_dashboard_data( $stats, $pending_count, $actionable_responses, $sparkline_data );

		// Setup checklist.
		$checklist = $this->get_setup_checklist();

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

		// Top products (respects date filter).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $date_where built with prepare().
		$top_products = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, COUNT(*) as review_count, AVG(score) as avg_score,
				        p.post_title as product_name
				 FROM {$table} s
				 JOIN {$wpdb->posts} p ON p.ID = s.product_id
				 WHERE p.post_type = %s {$date_where}
				 GROUP BY product_id
				 ORDER BY review_count DESC
				 LIMIT %d",
				'product',
				5
			)
		);
		?>
		<div class="wrap wairm-dashboard">
			<div class="wairm-page-header">
				<h1><?php esc_html_e( 'AI Review Manager Dashboard', 'woo-ai-review-manager' ); ?></h1>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wairm_export_csv&export_type=reviews' ), 'wairm_export_csv' ) ); ?>" class="page-title-action">
					<?php esc_html_e( 'Export Reviews CSV', 'woo-ai-review-manager' ); ?>
				</a>
			</div>

			<?php if ( ! empty( $checklist ) ) : ?>
			<div class="wairm-setup-checklist">
				<h3><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Setup Incomplete', 'woo-ai-review-manager' ); ?></h3>
				<p><?php esc_html_e( 'Complete these steps to get the most out of AI Review Manager:', 'woo-ai-review-manager' ); ?></p>
				<ul>
					<?php foreach ( $checklist as $item ) : ?>
						<li>
							<?php if ( ! empty( $item['url'] ) ) : ?>
								<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $item['label'] ); ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>

			<?php if ( $failed_emails > 0 ) : ?>
			<div class="wairm-failure-widget">
				<span class="dashicons dashicons-warning"></span>
				<p>
					<?php
					printf(
						/* translators: 1: count of failed emails, 2: link open, 3: link close */
						esc_html( _n(
							'%1$d email failed to send. %2$sView invitations%3$s to investigate.',
							'%1$d emails failed to send. %2$sView invitations%3$s to investigate.',
							$failed_emails,
							'woo-ai-review-manager'
						) ),
						$failed_emails,
						'<a href="' . esc_url( admin_url( 'admin.php?page=wairm-invitations' ) ) . '">',
						'</a>'
					);
					?>
				</p>
			</div>
			<?php endif; ?>

			<div class="wairm-period-filter">
				<?php
				$period_base = admin_url( 'admin.php?page=wairm-dashboard' );
				$periods     = [
					'7'   => __( 'Last 7 days', 'woo-ai-review-manager' ),
					'30'  => __( 'Last 30 days', 'woo-ai-review-manager' ),
					'90'  => __( 'Last 90 days', 'woo-ai-review-manager' ),
					'all' => __( 'All time', 'woo-ai-review-manager' ),
				];
				foreach ( $periods as $key => $label ) :
				?>
					<a href="<?php echo esc_url( add_query_arg( 'period', $key, $period_base ) ); ?>" class="button <?php echo $period === $key ? 'button-primary' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</div>

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

			<div class="wairm-quick-actions-section">
				<h2><?php esc_html_e( 'Quick Actions', 'woo-ai-review-manager' ); ?></h2>
				<div class="wairm-quick-actions">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-responses' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Manage Responses', 'woo-ai-review-manager' ); ?>
						<?php if ( $actionable_responses > 0 ) : ?>
							<span class="wairm-badge"><?php echo absint( $actionable_responses ); ?></span>
						<?php endif; ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-invitations' ) ); ?>" class="button">
						<?php esc_html_e( 'Invitations', 'woo-ai-review-manager' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-insights' ) ); ?>" class="button">
						<?php esc_html_e( 'Insights', 'woo-ai-review-manager' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-settings' ) ); ?>" class="button">
						<?php esc_html_e( 'Settings', 'woo-ai-review-manager' ); ?>
					</a>

					<?php if ( $pending_count > 0 ) : ?>
					<button class="button" id="wairm-analyze-old-reviews">
						<?php
						printf(
							/* translators: %d: number of unanalyzed reviews */
							esc_html__( 'Analyze %d Unanalyzed Reviews', 'woo-ai-review-manager' ),
							$pending_count
						);
						?>
					</button>
					<?php endif; ?>

				</div>

				<?php if ( $pending_count > 0 ) : ?>
				<div id="wairm-analyze-progress" class="wairm-progress-wrap" style="display: none;">
					<div class="wairm-progress-track">
						<div id="wairm-progress-bar" class="wairm-progress-fill"></div>
					</div>
					<p id="wairm-progress-text" class="wairm-progress-text"></p>
				</div>
				<?php endif; ?>
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
									<span class="sentiment-badge sentiment-<?php echo esc_attr( $review->sentiment ); ?>"><?php echo esc_html( ucfirst( $review->sentiment ) ); ?></span>
									<span class="review-date"><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $review->comment_date ) ) ); ?></span>
								</div>
								<div class="review-excerpt"><?php echo esc_html( wp_trim_words( $review->comment_content, 30 ) ); ?></div>
								<div class="review-meta">
									<span class="review-author"><?php echo esc_html( $review->comment_author ); ?></span>
									<span class="review-score"><?php esc_html_e( 'Score:', 'woo-ai-review-manager' ); ?> <?php echo esc_html( number_format( (float) $review->score, 2 ) ); ?></span>
									<?php if ( $review->ai_response_suggestion && 'sent' !== $review->ai_response_status ) : ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-responses&status=actionable' ) ); ?>" class="button button-small">
											<?php esc_html_e( 'Respond', 'woo-ai-review-manager' ); ?>
										</a>
									<?php elseif ( 'sent' === $review->ai_response_status ) : ?>
										<span class="dashicons dashicons-yes-alt" title="<?php esc_attr_e( 'Reply posted', 'woo-ai-review-manager' ); ?>"></span>
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
										<?php echo esc_html( number_format( (float) $product->avg_score, 2 ) ); ?>
										<?php
										$score_class = $product->avg_score > 0.65 ? 'score-positive' : ( $product->avg_score > 0.35 ? 'score-mixed' : 'score-negative' );
										?>
										<span class="wairm-score-bar-track">
											<span class="wairm-score-bar-fill <?php echo esc_attr( $score_class ); ?>" style="width: <?php echo esc_attr( (string) round( (float) $product->avg_score * 100 ) ); ?>%;"></span>
										</span>
									</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p><?php esc_html_e( 'No product data yet.', 'woo-ai-review-manager' ); ?></p>
					<?php endif; ?>

					<?php
					$text_supported  = \WooAIReviewManager\AI_Client::is_text_supported();
					$dash_providers  = \WooAIReviewManager\AI_Client::discover_providers();
					$dash_model_pref = get_option( 'wairm_model_preference', '' );
					?>
					<div class="wairm-api-status">
						<h3><?php esc_html_e( 'AI Status', 'woo-ai-review-manager' ); ?></h3>
						<?php if ( $text_supported ) : ?>
							<p>
								<span class="wairm-ai-available"><?php esc_html_e( 'AI text generation is available.', 'woo-ai-review-manager' ); ?></span>
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
									echo wp_kses(
										sprintf(
											/* translators: %s: model ID wrapped in <code> */
											__( 'Preferred model: %s', 'woo-ai-review-manager' ),
											'<code>' . esc_html( $dash_model_pref ) . '</code>'
										),
										[ 'code' => [] ]
									);
									?>
								</p>
							<?php endif; ?>
						<?php elseif ( \WooAIReviewManager\AI_Client::is_available() ) : ?>
							<p>
								<span class="wairm-ai-unavailable"><?php esc_html_e( 'No AI connectors configured for text generation.', 'woo-ai-review-manager' ); ?></span>
							</p>
							<a href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>" class="button button-secondary">
								<?php esc_html_e( 'Configure AI Connectors', 'woo-ai-review-manager' ); ?>
							</a>
						<?php else : ?>
							<p>
								<span class="wairm-ai-unavailable"><?php esc_html_e( 'WordPress AI Client is not available.', 'woo-ai-review-manager' ); ?></span>
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
