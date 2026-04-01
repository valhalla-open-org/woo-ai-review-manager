<?php
/**
 * Generates AI response suggestions for reviews beyond the automatic
 * negative-review flow in Sentiment_Analyzer.
 *
 * Handles on-demand generation for reviews that weren't auto-processed
 * (e.g. positive reviews when the setting is enabled).
 *
 * @package WooAIReviewManager
 */

declare(strict_types=1);

namespace WooAIReviewManager;

defined( 'ABSPATH' ) || exit;

final class Response_Generator {

	public function __construct() {
		add_action( 'wairm_generate_response', [ $this, 'generate_for_sentiment' ] );
	}

	/**
	 * Generate a response suggestion for a sentiment record that lacks one.
	 */
	public function generate_for_sentiment( int $sentiment_id ): void {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.*, c.comment_content
				 FROM {$wpdb->prefix}wairm_review_sentiment s
				 JOIN {$wpdb->comments} c ON c.comment_ID = s.comment_id
				 WHERE s.id = %d AND s.ai_response_status = 'pending'",
				$sentiment_id
			)
		);

		if ( ! $row ) {
			return;
		}

		if ( ! AI_Client::is_available() ) {
			return;
		}

		$product      = wc_get_product( (int) $row->product_id );
		$product_name = $product ? $product->get_name() : '';
		$store_name   = get_bloginfo( 'name' );
		$review_text  = wp_strip_all_tags( $row->comment_content );

		$client = new AI_Client();

		try {
			$suggestion = $client->generate_response( $review_text, $row->sentiment, $product_name, $store_name );
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
