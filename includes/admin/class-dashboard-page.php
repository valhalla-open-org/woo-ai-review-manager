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
			__( 'AI Review Manager', 'ai-review-manager-for-woocommerce' ),
			__( 'AI Reviews', 'ai-review-manager-for-woocommerce' ),
			'manage_woocommerce',
			'wairm-dashboard',
			[ $this, 'render_page' ],
			'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIGZpbGw9Im5vbmUiIHZpZXdCb3g9IjAgMCAyNCAyNCIgaGVpZ2h0PSIyMCIgd2lkdGg9IjIwIj48cGF0aCBmaWxsPSJibGFjayIgZD0iTTE3LjA4NTkgNC41YzAuNzgxLTAuNzgwNzkgMi4wNDcyLTAuNzgwNzggMi44MjgyIDBMMjEuNSA2LjA4NTk0YzAuNzgwOCAwLjc4MSAwLjc4MDggMi4wNDcxMiAwIDIuODI4MTJMOC43MDcwMyAyMS43MDdDOC41MTk1MSAyMS44OTQ1IDguMjY1MTYgMjIgOCAyMkg1Yy0wLjU1MjIxIDAtMC45OTk4OC0wLjQ0NzgtMS0xdi0zbDAuMDA0ODgtMC4wOTg2YzAuMDIyNzQtMC4yMjg5IDAuMTI0MDctMC40NDQ0IDAuMjg4MDktMC42MDg0ek02IDEuNWMwLjUwNDkzIDAgMC45MjY3OSAwLjMyNjQ0IDEuMDgwMDggMC43NzI0NmwwLjAyNzM0IDAuMDkwODIgMC4wNjY0MSAwLjIyODUyQzcuNTQ0MjcgMy43MjEgOC40NzU1IDQuNTk4MjYgOS42MzY3MiA0Ljg5MjU4IDEwLjEyODQgNS4wMTcxMiAxMC41IDUuNDYxNTEgMTAuNSA2YzAgMC41Mzg0OC0wLjM3MTcgMC45ODE5LTAuODYzMjggMS4xMDY0NS0xLjIzODY2IDAuMzEzODYtMi4yMTY0MyAxLjI5MTYxLTIuNTMwMjcgMi41MzAyN0M2Ljk4MTkxIDEwLjEyODQgNi41Mzg0OSAxMC41IDYgMTAuNXMtMC45ODE5MS0wLjM3MTYtMS4xMDY0NS0wLjg2MzI4QzQuNTc5NyA4LjM5ODA2IDMuNjAxOTQgNy40MjAzMSAyLjM2MzI4IDcuMTA2NDVjLTAuNDYwOTctMC4xMTY3OC0wLjgxNjg2LTAuNTEzODItMC44NTkzNy0xLjAwNjg0TDEuNSA2bDAuMDAzOTEtMC4wOTk2MWMwLjA0MjQ5LTAuNDkzMDUgMC4zOTgzOS0wLjg5MTAzIDAuODU5MzctMS4wMDc4MSAxLjIzODQ2LTAuMzEzOSAyLjIxNTM5LTEuMjkwODUgMi41MjkzLTIuNTI5M0M1LjAxNzEyIDEuODcxNjUgNS40NjE1MSAxLjUgNiAxLjVtOS43MDcgNy4yMDcwMyAxLjU4NiAxLjU4NTk3TDIwLjA4NTkgNy41IDE4LjUgNS45MTQwNnoiLz48L3N2Zz4=',
			56
		);

		// Override the auto-created first submenu label from "AI Reviews" to "Dashboard".
		add_submenu_page(
			'wairm-dashboard',
			__( 'Dashboard', 'ai-review-manager-for-woocommerce' ),
			__( 'Dashboard', 'ai-review-manager-for-woocommerce' ),
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
					'analyze_button'  => __( 'Analyze Old Reviews', 'ai-review-manager-for-woocommerce' ),
					'analyzing'       => __( 'Analyzing...', 'ai-review-manager-for-woocommerce' ),
					'batch_progress'  => /* translators: 1: processed, 2: total */ __( 'Analyzed %1$d of %2$d...', 'ai-review-manager-for-woocommerce' ),
					'complete'        => __( 'All done! Reloading...', 'ai-review-manager-for-woocommerce' ),
					'nothing'         => __( 'No unanalyzed reviews found.', 'ai-review-manager-for-woocommerce' ),
					'error'           => __( 'An error occurred. Please try again.', 'ai-review-manager-for-woocommerce' ),
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
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-review-manager-for-woocommerce' ) ], 403 );
		}

		if ( ! \WooAIReviewManager\AI_Client::is_available() ) {
			wp_send_json_error( [ 'message' => __( 'AI Client is not available. Please configure AI credentials first.', 'ai-review-manager-for-woocommerce' ) ] );
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
				'label' => __( 'WordPress 7.0+ with AI Client API required', 'ai-review-manager-for-woocommerce' ),
				'url'   => '',
			];
		} elseif ( ! \WooAIReviewManager\AI_Client::is_text_supported() ) {
			$incomplete['ai_connector'] = [
				'label' => __( 'Configure an AI connector for text generation', 'ai-review-manager-for-woocommerce' ),
				'url'   => admin_url( 'options-connectors.php' ),
			];
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			$incomplete['woocommerce'] = [
				'label' => __( 'WooCommerce must be installed and active', 'ai-review-manager-for-woocommerce' ),
				'url'   => admin_url( 'plugins.php' ),
			];
		}

		$support_email = get_option( 'wairm_support_email', '' );
		if ( empty( $support_email ) ) {
			$incomplete['support_email'] = [
				'label' => __( 'Set a support email for AI responses', 'ai-review-manager-for-woocommerce' ),
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$review_trends = $wpdb->get_results(
			"SELECT {$group_expr} as period_key,
					COUNT(*) as review_count,
					AVG(score) as avg_score
			 FROM {$sentiment_table} s
			 WHERE 1=1 {$date_filter}
			 GROUP BY period_key
			 ORDER BY period_key ASC"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$prev_sentiment = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) as total_reviews, AVG(score) as avg_score
				 FROM {$sentiment_table}
				 WHERE analyzed_at >= %s AND analyzed_at < %s",
				$prev_start,
				$prev_end
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$prev_email = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
			esc_html__( 'vs prev period', 'ai-review-manager-for-woocommerce' )
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$actionable_responses = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table}
				 WHERE ai_response_suggestion IS NOT NULL
				   AND ai_response_status IN (%s, %s)",
				'generated',
				'approved'
			)
		);

		// Failure stats for the error widget.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$failed_emails = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wairm_email_queue WHERE status = 'failed'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		// Pro dashboard data.
		$is_paying = warc_fs()->is_paying();

		if ( $is_paying ) {
			$sparkline_data = $this->get_sparkline_data( $period );
			$prev_stats     = $this->get_previous_period_stats( $period );
			$email_funnel   = $this->get_email_funnel( $date_where );

			$current_conversion = $email_funnel->sent > 0
				? round( ( $email_funnel->reviewed / $email_funnel->sent ) * 100, 1 )
				: 0.0;
		} else {
			$sparkline_data     = [ 'reviews' => [], 'scores' => [], 'conversions' => [] ];
			$prev_stats         = (object) [ 'total_reviews' => 0, 'avg_score' => 0, 'conversion_rate' => 0 ];
			$email_funnel       = (object) [ 'sent' => 0, 'clicked' => 0, 'reviewed' => 0 ];
			$current_conversion = 0.0;
		}

		// Negative reviews needing response.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$negative_needing_response = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$recent = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
		// Calculate deltas.
		if ( $is_paying ) {
			$review_delta     = $this->format_delta( (float) ( $stats->total_reviews ?? 0 ), (float) $prev_stats->total_reviews, 'percent', $period );
			$score_delta      = $this->format_delta( (float) ( $stats->avg_score ?? 0 ), $prev_stats->avg_score, 'absolute', $period );
			$conversion_delta = $this->format_delta( $current_conversion, $prev_stats->conversion_rate, 'absolute', $period );
		} else {
			$empty_delta      = [ 'html' => '', 'is_positive' => true ];
			$review_delta     = $empty_delta;
			$score_delta      = $empty_delta;
			$conversion_delta = $empty_delta;
		}

		$needs_action_count = $negative_needing_response + $pending_count;

		// First-run check.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_all_time = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		?>
		<div class="wrap wairm-dashboard">
			<hr class="wp-header-end">
			<div class="wairm-page-header">
				<h1><?php esc_html_e( 'AI Reviews', 'ai-review-manager-for-woocommerce' ); ?></h1>
				<div class="wairm-toolbar">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-responses' ) ); ?>"
					   class="wairm-toolbar-btn <?php echo $is_paying && $actionable_responses > 0 ? 'has-badge' : ''; ?>">
						<?php esc_html_e( 'Responses', 'ai-review-manager-for-woocommerce' ); ?>
						<?php if ( $is_paying && $actionable_responses > 0 ) : ?>
							<span class="toolbar-badge"><?php echo absint( $actionable_responses ); ?></span>
						<?php elseif ( ! $is_paying ) : ?>
							<span class="wairm-pro-badge"><?php esc_html_e( 'Pro', 'ai-review-manager-for-woocommerce' ); ?></span>
						<?php endif; ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-invitations' ) ); ?>" class="wairm-toolbar-btn">
						<?php esc_html_e( 'Invitations', 'ai-review-manager-for-woocommerce' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-insights' ) ); ?>" class="wairm-toolbar-btn">
						<?php esc_html_e( 'Insights', 'ai-review-manager-for-woocommerce' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-settings' ) ); ?>" class="wairm-toolbar-btn">
						<?php esc_html_e( 'Settings', 'ai-review-manager-for-woocommerce' ); ?>
					</a>
					<a href="<?php echo $is_paying ? esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wairm_export_csv&export_type=reviews' ), 'wairm_export_csv' ) ) : '#'; ?>"
					   class="wairm-toolbar-btn <?php echo ! $is_paying ? 'wairm-pro-disabled' : ''; ?>">
						<?php esc_html_e( 'Export CSV', 'ai-review-manager-for-woocommerce' ); ?>
						<?php if ( ! $is_paying ) : ?>
							<span class="wairm-pro-badge"><?php esc_html_e( 'Pro', 'ai-review-manager-for-woocommerce' ); ?></span>
						<?php endif; ?>
					</a>
					<?php if ( $pending_count > 0 ) : ?>
						<button class="wairm-toolbar-btn" id="wairm-analyze-old-reviews">
							<?php
							printf(
								/* translators: %d: number of unanalyzed reviews */
								esc_html__( 'Analyze %d Reviews', 'ai-review-manager-for-woocommerce' ),
								absint( $pending_count )
							);
							?>
						</button>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( ! empty( $checklist ) ) : ?>
			<div class="wairm-setup-checklist">
				<h3><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Setup Incomplete', 'ai-review-manager-for-woocommerce' ); ?></h3>
				<p><?php esc_html_e( 'Complete these steps to get the most out of AI Review Manager:', 'ai-review-manager-for-woocommerce' ); ?></p>
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
						/* translators: %1$d: number of failed emails, %2$s: opening link tag, %3$s: closing link tag */
						esc_html( _n(
							'%1$d email failed to send. %2$sView invitations%3$s to investigate.',
							'%1$d emails failed to send. %2$sView invitations%3$s to investigate.',
							$failed_emails,
							'ai-review-manager-for-woocommerce'
						) ),
						absint( $failed_emails ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=wairm-invitations' ) ) . '">',
						'</a>'
					);
					?>
				</p>
			</div>
			<?php endif; ?>

			<?php if ( $pending_count > 0 ) : ?>
			<div id="wairm-analyze-progress" class="wairm-progress-wrap" style="display: none;">
				<div class="wairm-progress-track">
					<div id="wairm-progress-bar" class="wairm-progress-fill"></div>
				</div>
				<p id="wairm-progress-text" class="wairm-progress-text"></p>
			</div>
			<?php endif; ?>

			<?php if ( 0 === $total_all_time ) : ?>
			<div class="wairm-welcome-banner">
				<h2><?php esc_html_e( 'Welcome to AI Reviews', 'ai-review-manager-for-woocommerce' ); ?></h2>
				<p><?php esc_html_e( 'Your dashboard will populate as reviews come in. To get started:', 'ai-review-manager-for-woocommerce' ); ?></p>
				<ol>
					<li><?php esc_html_e( 'Verify your AI connector in Settings', 'ai-review-manager-for-woocommerce' ); ?></li>
					<li><?php esc_html_e( 'Send your first review invitation', 'ai-review-manager-for-woocommerce' ); ?></li>
				</ol>
				<div class="wairm-welcome-actions">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-settings' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Go to Settings', 'ai-review-manager-for-woocommerce' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-invitations' ) ); ?>" class="button">
						<?php esc_html_e( 'Send Invitations', 'ai-review-manager-for-woocommerce' ); ?>
					</a>
				</div>
			</div>
			<?php endif; ?>

			<div class="wairm-period-filter" role="group" aria-label="<?php esc_attr_e( 'Filter by time period', 'ai-review-manager-for-woocommerce' ); ?>">
				<?php
				$period_base = admin_url( 'admin.php?page=wairm-dashboard' );
				$periods     = [
					'7'   => __( 'Last 7 days', 'ai-review-manager-for-woocommerce' ),
					'30'  => __( 'Last 30 days', 'ai-review-manager-for-woocommerce' ),
					'90'  => __( 'Last 90 days', 'ai-review-manager-for-woocommerce' ),
					'all' => __( 'All time', 'ai-review-manager-for-woocommerce' ),
				];
				foreach ( $periods as $key => $label ) :
				?>
					<a href="<?php echo esc_url( add_query_arg( 'period', $key, $period_base ) ); ?>"
					   class="button <?php echo $period === $key ? 'button-primary' : ''; ?>"
					   <?php echo $period === $key ? 'aria-pressed="true"' : 'aria-pressed="false"'; ?>>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</div>

			<!-- KPI Cards Row -->
			<div class="wairm-stats-grid">
				<?php $sparkline_reviews = $this->render_sparkline_svg( $sparkline_data['reviews'], $review_delta['is_positive'] ); ?>
				<div class="wairm-kpi-card" role="group"
				     aria-label="<?php /* translators: %d: total number of reviews */ printf( esc_attr__( 'Total Reviews: %d', 'ai-review-manager-for-woocommerce' ), absint( $stats->total_reviews ?? 0 ) ); ?>">
					<div class="kpi-label"><?php esc_html_e( 'Total Reviews', 'ai-review-manager-for-woocommerce' ); ?></div>
					<div class="wairm-kpi-body">
						<div>
							<div class="kpi-value"><?php echo absint( $stats->total_reviews ?? 0 ); ?></div>
							<?php echo $review_delta['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<?php if ( $sparkline_reviews ) : ?>
							<div class="wairm-kpi-sparkline" aria-label="<?php esc_attr_e( 'Review count trend', 'ai-review-manager-for-woocommerce' ); ?>">
								<?php echo $sparkline_reviews; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<?php $sparkline_scores = $this->render_sparkline_svg( $sparkline_data['scores'], $score_delta['is_positive'] ); ?>
				<div class="wairm-kpi-card" role="group"
				     aria-label="<?php /* translators: %s: average sentiment score */ printf( esc_attr__( 'Average Score: %s', 'ai-review-manager-for-woocommerce' ), number_format( (float) ( $stats->avg_score ?? 0 ), 2 ) ); ?>">
					<div class="kpi-label"><?php esc_html_e( 'Avg Score', 'ai-review-manager-for-woocommerce' ); ?></div>
					<div class="wairm-kpi-body">
						<div>
							<div class="kpi-value"><?php echo esc_html( number_format( (float) ( $stats->avg_score ?? 0 ), 2 ) ); ?></div>
							<?php echo $score_delta['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<?php if ( $sparkline_scores ) : ?>
							<div class="wairm-kpi-sparkline" aria-label="<?php esc_attr_e( 'Sentiment score trend', 'ai-review-manager-for-woocommerce' ); ?>">
								<?php echo $sparkline_scores; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<?php $sparkline_conv = $this->render_sparkline_svg( $sparkline_data['conversions'], $conversion_delta['is_positive'] ); ?>
				<div class="wairm-kpi-card" role="group"
				     aria-label="<?php /* translators: %s: conversion rate percentage */ printf( esc_attr__( 'Email to Review conversion: %s%%', 'ai-review-manager-for-woocommerce' ), esc_attr( (string) $current_conversion ) ); ?>">
					<div class="kpi-label"><?php esc_html_e( 'Email → Review', 'ai-review-manager-for-woocommerce' ); ?></div>
					<div class="wairm-kpi-body">
						<div>
							<div class="kpi-value"><?php echo esc_html( $current_conversion . '%' ); ?></div>
							<?php echo $conversion_delta['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<?php if ( $sparkline_conv ) : ?>
							<div class="wairm-kpi-sparkline" aria-label="<?php esc_attr_e( 'Conversion rate trend', 'ai-review-manager-for-woocommerce' ); ?>">
								<?php echo $sparkline_conv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<div class="wairm-kpi-card <?php echo $needs_action_count > 0 ? 'needs-action' : 'all-clear'; ?>" role="group"
				     aria-label="<?php /* translators: %d: number of items needing action */ printf( esc_attr__( 'Needs Action: %d items', 'ai-review-manager-for-woocommerce' ), absint( $needs_action_count ) ); ?>">
					<div class="kpi-label"><?php esc_html_e( 'Needs Action', 'ai-review-manager-for-woocommerce' ); ?></div>
					<div>
						<div class="kpi-value"><?php echo absint( $needs_action_count ); ?></div>
						<div class="wairm-kpi-pills">
							<?php if ( $negative_needing_response > 0 ) : ?>
								<span class="wairm-kpi-pill pill-negative">
									<?php /* translators: %d: number of negative reviews needing response */ printf( esc_html__( '%d negative', 'ai-review-manager-for-woocommerce' ), absint( $negative_needing_response ) ); ?>
								</span>
							<?php endif; ?>
							<?php if ( $pending_count > 0 ) : ?>
								<span class="wairm-kpi-pill pill-pending">
									<?php /* translators: %d: number of reviews pending analysis */ printf( esc_html__( '%d pending', 'ai-review-manager-for-woocommerce' ), absint( $pending_count ) ); ?>
								</span>
							<?php endif; ?>
							<?php if ( 0 === $needs_action_count ) : ?>
								<span class="wairm-kpi-pill pill-clear"><?php esc_html_e( 'All clear', 'ai-review-manager-for-woocommerce' ); ?></span>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>

			<!-- Mid Row: Sentiment Breakdown + Email Funnel -->
			<div class="wairm-mid-row">
				<div class="wairm-widget-card" role="region" aria-label="<?php esc_attr_e( 'Sentiment Breakdown', 'ai-review-manager-for-woocommerce' ); ?>">
					<h2 class="widget-title"><?php esc_html_e( 'Sentiment Breakdown', 'ai-review-manager-for-woocommerce' ); ?></h2>
					<?php if ( (int) ( $stats->total_reviews ?? 0 ) > 0 ) : ?>
						<?php
						$total      = (int) $stats->total_reviews;
						$sentiments = [
							'positive' => [ 'count' => (int) $stats->positive, 'label' => __( 'Positive', 'ai-review-manager-for-woocommerce' ) ],
							'neutral'  => [ 'count' => (int) $stats->neutral,  'label' => __( 'Neutral', 'ai-review-manager-for-woocommerce' ) ],
							'negative' => [ 'count' => (int) $stats->negative, 'label' => __( 'Negative', 'ai-review-manager-for-woocommerce' ) ],
						];
						foreach ( $sentiments as $key => $s ) :
							$pct = round( ( $s['count'] / $total ) * 100, 1 );
						?>
						<div class="wairm-sentiment-row">
							<div class="bar-label">
								<span class="bar-label-left">
									<span class="wairm-sentiment-dot dot-<?php echo esc_attr( $key ); ?>"></span>
									<span><?php echo esc_html( $s['label'] ); ?></span>
								</span>
								<span class="bar-label-right"
								      role="img"
								      aria-label="<?php /* translators: 1: sentiment label, 2: review count, 3: percentage */ printf( esc_attr__( '%1$s: %2$d reviews, %3$s percent', 'ai-review-manager-for-woocommerce' ), esc_attr( $s['label'] ), absint( $s['count'] ), esc_attr( (string) $pct ) ); ?>">
									<?php echo absint( $s['count'] ); ?>
									<span class="bar-pct">(<?php echo esc_html( (string) $pct ); ?>%)</span>
								</span>
							</div>
							<div class="wairm-bar-track">
								<div class="wairm-bar-fill fill-<?php echo esc_attr( $key === 'neutral' ? 'neutral' : $key ); ?>"
								     data-width="<?php echo esc_attr( (string) $pct ); ?>"
								     style="width: 0;"></div>
							</div>
						</div>
						<?php endforeach; ?>
					<?php else : ?>
						<div class="wairm-widget-empty">
							<p><?php esc_html_e( 'No reviews analyzed in this period.', 'ai-review-manager-for-woocommerce' ); ?></p>
						</div>
					<?php endif; ?>
				</div>

				<div class="wairm-widget-card" role="region" aria-label="<?php esc_attr_e( 'Email Funnel', 'ai-review-manager-for-woocommerce' ); ?>">
					<h2 class="widget-title"><?php esc_html_e( 'Email Funnel', 'ai-review-manager-for-woocommerce' ); ?></h2>
					<?php if ( ! $is_paying ) : ?>
						<div class="wairm-widget-empty">
							<p><?php esc_html_e( 'Track how invitations convert to clicks and reviews.', 'ai-review-manager-for-woocommerce' ); ?></p>
							<a href="<?php echo esc_url( warc_fs()->get_upgrade_url() ); ?>" class="button" style="margin-top: 8px;">
								<?php esc_html_e( 'Upgrade to Pro', 'ai-review-manager-for-woocommerce' ); ?>
							</a>
						</div>
					<?php else : ?>
					<?php if ( $email_funnel->sent > 0 ) : ?>
						<?php
						$funnel_steps = [
							[ 'label' => __( 'Sent', 'ai-review-manager-for-woocommerce' ),     'count' => $email_funnel->sent,     'pct' => 100,                                                                    'width' => 100 ],
							[ 'label' => __( 'Clicked', 'ai-review-manager-for-woocommerce' ),   'count' => $email_funnel->clicked,  'pct' => round( ( $email_funnel->clicked / $email_funnel->sent ) * 100, 1 ),      'width' => round( ( $email_funnel->clicked / $email_funnel->sent ) * 100, 1 ) ],
							[ 'label' => __( 'Reviewed', 'ai-review-manager-for-woocommerce' ),  'count' => $email_funnel->reviewed, 'pct' => round( ( $email_funnel->reviewed / $email_funnel->sent ) * 100, 1 ),     'width' => round( ( $email_funnel->reviewed / $email_funnel->sent ) * 100, 1 ) ],
						];

						$dropoffs = [
							$email_funnel->sent - $email_funnel->clicked,
							$email_funnel->clicked - $email_funnel->reviewed,
						];
						$biggest_dropoff_idx = $dropoffs[0] >= $dropoffs[1] ? 0 : 1;

						foreach ( $funnel_steps as $idx => $step ) :
						?>
						<div class="wairm-funnel-step">
							<div class="funnel-label">
								<span class="funnel-label-name"><?php echo esc_html( $step['label'] ); ?></span>
								<span class="funnel-label-value"
								      role="img"
								      aria-label="<?php /* translators: 1: funnel step label, 2: count, 3: percentage of sent */ printf( esc_attr__( '%1$s: %2$d, %3$s percent of sent', 'ai-review-manager-for-woocommerce' ), esc_attr( $step['label'] ), absint( $step['count'] ), esc_attr( (string) $step['pct'] ) ); ?>">
									<?php echo absint( $step['count'] ); ?>
									<?php if ( $idx > 0 ) : ?>
										<span class="funnel-pct">(<?php echo esc_html( (string) $step['pct'] ); ?>%)</span>
									<?php endif; ?>
								</span>
							</div>
							<div class="wairm-bar-track">
								<div class="wairm-bar-fill fill-accent"
								     data-width="<?php echo esc_attr( (string) $step['width'] ); ?>"
								     style="width: 0;"></div>
							</div>
						</div>
						<?php
						if ( $idx < 2 && $idx === $biggest_dropoff_idx && $dropoffs[ $idx ] > 0 ) :
							$dropoff_pct = round( ( $dropoffs[ $idx ] / $email_funnel->sent ) * 100, 1 );
						?>
						<div class="wairm-funnel-dropoff">
							<?php /* translators: %s: drop-off percentage */ printf( esc_html__( '%s%% drop-off', 'ai-review-manager-for-woocommerce' ), esc_html( (string) $dropoff_pct ) ); ?>
						</div>
						<?php endif; ?>
						<?php endforeach; ?>
					<?php else : ?>
						<div class="wairm-widget-empty">
							<p><?php esc_html_e( 'No invitations sent in this period.', 'ai-review-manager-for-woocommerce' ); ?></p>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-invitations' ) ); ?>" class="empty-subtext">
								<?php esc_html_e( 'Go to Invitations', 'ai-review-manager-for-woocommerce' ); ?>
							</a>
						</div>
					<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>

			<!-- Bottom Row: Recent Reviews + Top Products -->
			<div class="wairm-two-column">
				<div class="wairm-column">
					<div class="wairm-widget-card" role="region" aria-label="<?php esc_attr_e( 'Recent Reviews', 'ai-review-manager-for-woocommerce' ); ?>">
						<h2 class="widget-title"><?php esc_html_e( 'Recent Reviews', 'ai-review-manager-for-woocommerce' ); ?></h2>
						<?php if ( $recent ) : ?>
							<div class="wairm-recent-reviews">
								<?php foreach ( $recent as $review ) : ?>
								<div class="wairm-review-card">
									<div class="review-header">
										<span class="product-name"><?php echo esc_html( $review->product_name ); ?></span>
										<span class="sentiment-badge sentiment-<?php echo esc_attr( $review->sentiment ); ?>"><?php echo esc_html( ucfirst( $review->sentiment ) ); ?></span>
										<span class="review-date"><?php echo esc_html( human_time_diff( strtotime( $review->comment_date ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'ai-review-manager-for-woocommerce' ) ); ?></span>
									</div>
									<div class="review-excerpt"><?php echo esc_html( wp_trim_words( $review->comment_content, 30 ) ); ?></div>
									<div class="review-meta">
										<?php if ( $review->ai_response_suggestion && 'sent' !== $review->ai_response_status ) : ?>
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-responses&status=actionable' ) ); ?>" class="wairm-respond-link">
												<?php esc_html_e( 'Respond', 'ai-review-manager-for-woocommerce' ); ?> &rarr;
											</a>
										<?php elseif ( 'sent' === $review->ai_response_status ) : ?>
											<span class="dashicons dashicons-yes-alt" title="<?php esc_attr_e( 'Reply posted', 'ai-review-manager-for-woocommerce' ); ?>"></span>
										<?php endif; ?>
									</div>
								</div>
								<?php endforeach; ?>
							</div>
						<?php else : ?>
							<div class="wairm-widget-empty">
								<p><?php esc_html_e( 'No reviews yet.', 'ai-review-manager-for-woocommerce' ); ?></p>
								<p class="empty-subtext"><?php esc_html_e( 'Reviews will appear here once customers leave feedback and sentiment analysis runs.', 'ai-review-manager-for-woocommerce' ); ?></p>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<div class="wairm-column">
					<div class="wairm-widget-card" role="region" aria-label="<?php esc_attr_e( 'Top Products', 'ai-review-manager-for-woocommerce' ); ?>">
						<h2 class="widget-title"><?php esc_html_e( 'Top Products', 'ai-review-manager-for-woocommerce' ); ?></h2>
						<?php if ( $top_products ) : ?>
							<table class="wp-list-table widefat fixed striped">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Product', 'ai-review-manager-for-woocommerce' ); ?></th>
										<th><?php esc_html_e( 'Reviews', 'ai-review-manager-for-woocommerce' ); ?></th>
										<th><?php esc_html_e( 'Avg. Score', 'ai-review-manager-for-woocommerce' ); ?></th>
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
							<div class="wairm-widget-empty">
								<p><?php esc_html_e( 'No product data yet.', 'ai-review-manager-for-woocommerce' ); ?></p>
								<p class="empty-subtext"><?php esc_html_e( 'Product scores will appear after reviews are analyzed.', 'ai-review-manager-for-woocommerce' ); ?></p>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
