<?php
/**
 * REST API controller for per-product sentiment statistics.
 *
 * @package WooAIReviewManager
 */

declare(strict_types=1);

namespace WooAIReviewManager\API;

defined( 'ABSPATH' ) || exit;

final class Sentiment_Controller {

	private const NAMESPACE = 'wairm/v1';

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/sentiment/product/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_product_sentiment' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'id' => [
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ): bool {
							return $value > 0;
						},
					],
				],
			]
		);
	}

	public function check_permissions(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public function get_product_sentiment( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$product_id = $request->get_param( 'id' );

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_found', 'message' => 'Product not found.' ],
				404
			);
		}

		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,
					SUM(CASE WHEN sentiment = 'positive' THEN 1 ELSE 0 END) AS positive,
					SUM(CASE WHEN sentiment = 'neutral' THEN 1 ELSE 0 END) AS neutral,
					SUM(CASE WHEN sentiment = 'negative' THEN 1 ELSE 0 END) AS negative,
					AVG(score) AS avg_score
				 FROM {$wpdb->prefix}wairm_review_sentiment
				 WHERE product_id = %d",
				$product_id
			)
		);

		$recent = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.sentiment, s.score, s.key_phrases, s.analyzed_at,
				        c.comment_content, c.comment_author, c.comment_date
				 FROM {$wpdb->prefix}wairm_review_sentiment s
				 JOIN {$wpdb->comments} c ON c.comment_ID = s.comment_id
				 WHERE s.product_id = %d
				 ORDER BY s.analyzed_at DESC
				 LIMIT %d",
				$product_id,
				5
			)
		);

		return new \WP_REST_Response(
			[
				'product_id'   => $product_id,
				'product_name' => $product->get_name(),
				'stats'        => [
					'total'     => (int) ( $stats->total ?? 0 ),
					'positive'  => (int) ( $stats->positive ?? 0 ),
					'neutral'   => (int) ( $stats->neutral ?? 0 ),
					'negative'  => (int) ( $stats->negative ?? 0 ),
					'avg_score' => $stats->avg_score ? round( (float) $stats->avg_score, 2 ) : null,
				],
				'recent'       => array_map(
					static function ( object $row ): array {
						return [
							'author'      => $row->comment_author,
							'content'     => $row->comment_content,
							'date'        => $row->comment_date,
							'sentiment'   => $row->sentiment,
							'score'       => (float) $row->score,
							'key_phrases' => json_decode( $row->key_phrases ?? '[]', true ),
							'analyzed_at' => $row->analyzed_at,
						];
					},
					$recent
				),
			],
			200
		);
	}
}
