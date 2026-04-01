<?php
/**
 * Processes reviews through sentiment analysis.
 *
 * @package WooAIReviewManager
 */

declare(strict_types=1);

namespace WooAIReviewManager;

defined( 'ABSPATH' ) || exit;

final class Sentiment_Analyzer {

	public function __construct() {
		add_action( 'wairm_analyze_single_review', [ $this, 'analyze_review' ] );
	}

	/**
	 * Analyze a single review comment.
	 */
	public function analyze_review( int $comment_id ): void {
		global $wpdb;

		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return;
		}

		// Skip if already analyzed.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}wairm_review_sentiment WHERE comment_id = %d",
				$comment_id
			)
		);
		if ( $exists ) {
			return;
		}

		if ( ! AI_Client::is_available() ) {
			return;
		}

		$client = new AI_Client();

		$product_id   = (int) $comment->comment_post_ID;
		$product      = wc_get_product( $product_id );
		$product_name = $product ? $product->get_name() : '';
		$review_text  = wp_strip_all_tags( $comment->comment_content );

		if ( empty( $review_text ) ) {
			return;
		}

		try {
			$result = $client->analyze_sentiment( $review_text, $product_name );
		} catch ( \RuntimeException $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[WAIRM] Sentiment analysis failed for comment ' . $comment_id . ': ' . $e->getMessage() );
			return;
		}

		$wpdb->insert(
			$wpdb->prefix . 'wairm_review_sentiment',
			[
				'comment_id'  => $comment_id,
				'product_id'  => $product_id,
				'sentiment'   => $result['sentiment'],
				'score'       => $result['score'],
				'key_phrases' => wp_json_encode( $result['key_phrases'] ),
			],
			[ '%d', '%d', '%s', '%f', '%s' ]
		);

		// Auto-generate response for negative reviews.
		$threshold = (float) get_option( 'wairm_negative_threshold', '0.30' );
		if ( $result['score'] < $threshold ) {
			$this->generate_response_suggestion( $wpdb->insert_id, $comment, $product_name, $result['sentiment'] );
		}
	}

	/**
	 * Process all pending (unanalyzed) reviews.
	 */
	public static function process_pending(): void {
		global $wpdb;

		$unanalyzed = $wpdb->get_col(
			"SELECT c.comment_ID
			 FROM {$wpdb->comments} c
			 LEFT JOIN {$wpdb->prefix}wairm_review_sentiment s ON s.comment_id = c.comment_ID
			 WHERE c.comment_type = 'review'
			   AND c.comment_approved = '1'
			   AND s.id IS NULL
			 ORDER BY c.comment_date DESC
			 LIMIT 50"
		);

		if ( empty( $unanalyzed ) ) {
			return;
		}

		$analyzer = new self();
		foreach ( $unanalyzed as $comment_id ) {
			$analyzer->analyze_review( (int) $comment_id );
		}
	}

	/**
	 * Generate an AI response suggestion for a review.
	 */
	private function generate_response_suggestion( int $sentiment_id, \WP_Comment $comment, string $product_name, string $sentiment ): void {
		global $wpdb;

		if ( ! AI_Client::is_available() ) {
			return;
		}

		$client      = new AI_Client();
		$store_name  = get_bloginfo( 'name' );
		$review_text = wp_strip_all_tags( $comment->comment_content );

		try {
			$suggestion = $client->generate_response( $review_text, $sentiment, $product_name, $store_name );
		} catch ( \RuntimeException $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[WAIRM] Response generation failed for sentiment ' . $sentiment_id . ': ' . $e->getMessage() );
			return;
		}

		$wpdb->update(
			$wpdb->prefix . 'wairm_review_sentiment',
			[
				'ai_response_suggestion' => sanitize_textarea_field( $suggestion ),
				'ai_response_status'     => 'generated',
			],
			[ 'id' => $sentiment_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}
}
