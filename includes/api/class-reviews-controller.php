<?php
/**
 * REST API controller for reviews with sentiment data.
 *
 * @package WooAIReviewManager
 */

declare(strict_types=1);

namespace WooAIReviewManager\API;

defined( 'ABSPATH' ) || exit;

final class Reviews_Controller {

	private const NAMESPACE = 'wairm/v1';

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/reviews',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'per_page' => [
						'default'           => 10,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ): bool {
							return $value >= 1 && $value <= 100;
						},
					],
					'page'     => [
						'default'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ): bool {
							return $value >= 1;
						},
					],
					'sentiment' => [
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ): bool {
							return '' === $value || in_array( $value, \WooAIReviewManager\AI_Client::VALID_SENTIMENTS, true );
						},
					],
				],
				'schema' => [ $this, 'get_item_schema' ],
			]
		);
	}

	/**
	 * Get the response schema for a single review item.
	 */
	public function get_item_schema(): array {
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wairm-review',
			'type'       => 'array',
			'items'      => [
				'type'       => 'object',
				'properties' => [
					'id'                     => [ 'type' => 'integer', 'description' => __( 'Sentiment record ID.', 'woo-ai-review-manager' ) ],
					'comment_id'             => [ 'type' => 'integer', 'description' => __( 'Comment ID.', 'woo-ai-review-manager' ) ],
					'product_id'             => [ 'type' => 'integer', 'description' => __( 'Product ID.', 'woo-ai-review-manager' ) ],
					'product_name'           => [ 'type' => 'string', 'description' => __( 'Product name.', 'woo-ai-review-manager' ) ],
					'author'                 => [ 'type' => 'string', 'description' => __( 'Review author name.', 'woo-ai-review-manager' ) ],
					'content'                => [ 'type' => 'string', 'description' => __( 'Review text.', 'woo-ai-review-manager' ) ],
					'date'                   => [ 'type' => 'string', 'format' => 'date-time', 'description' => __( 'Review date.', 'woo-ai-review-manager' ) ],
					'sentiment'              => [ 'type' => 'string', 'enum' => \WooAIReviewManager\AI_Client::VALID_SENTIMENTS, 'description' => __( 'Sentiment classification.', 'woo-ai-review-manager' ) ],
					'score'                  => [ 'type' => 'number', 'description' => __( 'Sentiment score (0.0–1.0).', 'woo-ai-review-manager' ) ],
					'key_phrases'            => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => __( 'Key phrases extracted from the review.', 'woo-ai-review-manager' ) ],
					'ai_response_suggestion' => [ 'type' => [ 'string', 'null' ], 'description' => __( 'AI-generated response suggestion.', 'woo-ai-review-manager' ) ],
					'ai_response_status'     => [ 'type' => [ 'string', 'null' ], 'description' => __( 'Response status.', 'woo-ai-review-manager' ) ],
					'analyzed_at'            => [ 'type' => 'string', 'format' => 'date-time', 'description' => __( 'Analysis timestamp.', 'woo-ai-review-manager' ) ],
				],
			],
		];
	}

	public function check_permissions(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public function get_items( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$per_page  = $request->get_param( 'per_page' );
		$page      = $request->get_param( 'page' );
		$sentiment = $request->get_param( 'sentiment' );
		$offset    = ( $page - 1 ) * $per_page;

		$where = '';
		$args  = [];

		if ( '' !== $sentiment ) {
			$where = 'AND s.sentiment = %s';
			$args[] = $sentiment;
		}

		$args[] = $per_page;
		$args[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built safely above.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, c.comment_content, c.comment_author, c.comment_date, p.post_title AS product_name
				 FROM {$wpdb->prefix}wairm_review_sentiment s
				 JOIN {$wpdb->comments} c ON c.comment_ID = s.comment_id
				 JOIN {$wpdb->posts} p ON p.ID = s.product_id
				 WHERE 1=1 {$where}
				 ORDER BY s.analyzed_at DESC
				 LIMIT %d OFFSET %d",
				...$args
			)
		);

		$items = array_map( [ $this, 'prepare_item' ], $results );

		return new \WP_REST_Response( $items, 200 );
	}

	private function prepare_item( object $row ): array {
		return [
			'id'                     => (int) $row->id,
			'comment_id'             => (int) $row->comment_id,
			'product_id'             => (int) $row->product_id,
			'product_name'           => $row->product_name,
			'author'                 => $row->comment_author,
			'content'                => $row->comment_content,
			'date'                   => $row->comment_date,
			'sentiment'              => $row->sentiment,
			'score'                  => (float) $row->score,
			'key_phrases'            => json_decode( $row->key_phrases ?? '[]', true ),
			'ai_response_suggestion' => $row->ai_response_suggestion,
			'ai_response_status'     => $row->ai_response_status,
			'analyzed_at'            => $row->analyzed_at,
		];
	}
}
