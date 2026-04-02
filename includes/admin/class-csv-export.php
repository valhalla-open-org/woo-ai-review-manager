<?php
/**
 * CSV export handler for reviews, invitations, and responses.
 *
 * @package WooAIReviewManager
 */

declare(strict_types=1);

namespace WooAIReviewManager\Admin;

defined( 'ABSPATH' ) || exit;

final class CSV_Export {

	public function __construct() {
		add_action( 'admin_post_wairm_export_csv', [ $this, 'handle_export' ] );
	}

	/**
	 * Handle the CSV export request.
	 */
	public function handle_export(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'woo-ai-review-manager' ), 403 );
		}

		check_admin_referer( 'wairm_export_csv' );

		$type = sanitize_key( $_GET['export_type'] ?? '' );

		switch ( $type ) {
			case 'reviews':
				$this->export_reviews();
				break;
			case 'invitations':
				$this->export_invitations();
				break;
			case 'responses':
				$this->export_responses();
				break;
			default:
				wp_die( esc_html__( 'Invalid export type.', 'woo-ai-review-manager' ) );
		}
	}

	/**
	 * Send CSV headers.
	 */
	private function send_headers( string $filename ): void {
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
	}

	/**
	 * Export all analyzed reviews with sentiment data.
	 */
	private function export_reviews(): void {
		global $wpdb;

		$this->send_headers( 'reviews-export-' . gmdate( 'Y-m-d' ) . '.csv' );
		$output = fopen( 'php://output', 'w' );

		fputcsv( $output, [
			'Review ID',
			'Product',
			'Author',
			'Review Date',
			'Review Text',
			'Sentiment',
			'Score',
			'Key Phrases',
			'Response Status',
		] );

		$rows = $wpdb->get_results(
			"SELECT s.*, c.comment_content, c.comment_author, c.comment_date,
			        p.post_title AS product_name
			 FROM {$wpdb->prefix}wairm_review_sentiment s
			 JOIN {$wpdb->comments} c ON c.comment_ID = s.comment_id
			 JOIN {$wpdb->posts} p ON p.ID = s.product_id
			 ORDER BY s.analyzed_at DESC"
		);

		foreach ( $rows as $row ) {
			fputcsv( $output, [
				$row->comment_id,
				$row->product_name,
				$row->comment_author,
				$row->comment_date,
				$row->comment_content,
				$row->sentiment,
				$row->score,
				$row->key_phrases,
				$row->ai_response_status ?? '',
			] );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Export all invitations.
	 */
	private function export_invitations(): void {
		global $wpdb;

		$this->send_headers( 'invitations-export-' . gmdate( 'Y-m-d' ) . '.csv' );
		$output = fopen( 'php://output', 'w' );

		fputcsv( $output, [
			'ID',
			'Customer Name',
			'Customer Email',
			'Order ID',
			'Status',
			'Created',
			'Expires',
			'Emails Sent',
			'Emails Failed',
		] );

		$rows = $wpdb->get_results(
			"SELECT i.*,
			        (SELECT COUNT(*) FROM {$wpdb->prefix}wairm_email_queue eq WHERE eq.invitation_id = i.id AND eq.status = 'sent') AS emails_sent,
			        (SELECT COUNT(*) FROM {$wpdb->prefix}wairm_email_queue eq WHERE eq.invitation_id = i.id AND eq.status = 'failed') AS emails_failed
			 FROM {$wpdb->prefix}wairm_review_invitations i
			 ORDER BY i.created_at DESC"
		);

		foreach ( $rows as $row ) {
			fputcsv( $output, [
				$row->id,
				$row->customer_name,
				$row->customer_email,
				$row->order_id,
				$row->status,
				$row->created_at,
				$row->expires_at,
				$row->emails_sent,
				$row->emails_failed,
			] );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Export all AI response suggestions.
	 */
	private function export_responses(): void {
		global $wpdb;

		$this->send_headers( 'responses-export-' . gmdate( 'Y-m-d' ) . '.csv' );
		$output = fopen( 'php://output', 'w' );

		fputcsv( $output, [
			'Review ID',
			'Product',
			'Author',
			'Sentiment',
			'Score',
			'Response Status',
			'AI Suggested Response',
		] );

		$rows = $wpdb->get_results(
			"SELECT s.*, c.comment_author, p.post_title AS product_name
			 FROM {$wpdb->prefix}wairm_review_sentiment s
			 JOIN {$wpdb->comments} c ON c.comment_ID = s.comment_id
			 JOIN {$wpdb->posts} p ON p.ID = s.product_id
			 WHERE s.ai_response_suggestion IS NOT NULL
			 ORDER BY s.analyzed_at DESC"
		);

		foreach ( $rows as $row ) {
			fputcsv( $output, [
				$row->comment_id,
				$row->product_name,
				$row->comment_author,
				$row->sentiment,
				$row->score,
				$row->ai_response_status,
				$row->ai_response_suggestion,
			] );
		}

		fclose( $output );
		exit;
	}
}
