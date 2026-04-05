<?php
/**
 * WordPress AI Client wrapper for sentiment analysis and response generation.
 *
 * Uses the WordPress 7.0 AI Client API (wp_ai_client_prompt) instead of
 * direct provider API calls. API keys are managed through the WordPress
 * Connectors API (Settings > Connectors).
 *
 * @package WooAIReviewManager
 */

declare(strict_types=1);

namespace WooAIReviewManager;

defined( 'ABSPATH' ) || exit;

final class AI_Client {

	/** @var string[] Valid sentiment values returned by the AI. */
	public const VALID_SENTIMENTS = [ 'positive', 'neutral', 'negative' ];

	/**
	 * Check whether the WordPress AI Client is available.
	 */
	public static function is_available(): bool {
		return function_exists( 'wp_ai_client_prompt' );
	}

	/**
	 * Check whether text generation is actually supported (at least one provider configured).
	 */
	public static function is_text_supported(): bool {
		if ( ! self::is_available() ) {
			return false;
		}

		return wp_ai_client_prompt( 'test' )->is_supported_for_text_generation();
	}

	/**
	 * Discover available AI providers and their models.
	 *
	 * Uses wp_get_connectors() to list registered AI provider connectors,
	 * checks which have credentials configured, and queries the PHP AI Client
	 * registry for each provider's available models.
	 *
	 * @return array<string, array{name: string, configured: bool, models: array<string, string>}>
	 */
	public static function discover_providers(): array {
		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return [];
		}

		$connectors = wp_get_connectors();
		if ( empty( $connectors ) || ! is_array( $connectors ) ) {
			return [];
		}

		// Build a model map from the PHP AI Client registry if available.
		$registry_models = self::get_registry_models();

		$providers = [];
		foreach ( $connectors as $id => $connector ) {
			// Only include AI provider connectors.
			if ( ! isset( $connector['type'] ) || 'ai_provider' !== $connector['type'] ) {
				continue;
			}

			$name = $connector['name'] ?? ucfirst( $id );

			// Check if the connector has credentials configured.
			$configured = false;
			if ( ! empty( $connector['authentication']['setting_name'] ) ) {
				$configured = ! empty( get_option( $connector['authentication']['setting_name'], '' ) );
			}

			// Get models from the registry for this provider.
			$models = $registry_models[ $id ] ?? [];

			$providers[ $id ] = [
				'name'       => $name,
				'configured' => $configured,
				'models'     => $models,
			];
		}

		return $providers;
	}

	/**
	 * Query the PHP AI Client registry for provider models.
	 *
	 * @return array<string, array<string, string>> Models keyed by provider ID.
	 */
	private static function get_registry_models(): array {
		if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			return [];
		}

		$models_by_provider = [];

		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();

			if ( ! is_object( $registry ) || ! method_exists( $registry, 'getProviders' ) ) {
				return [];
			}

			foreach ( $registry->getProviders() as $provider ) {
				$provider_id = method_exists( $provider, 'getId' ) ? $provider->getId() : (string) $provider;

				$models = [];
				if ( method_exists( $provider, 'getModels' ) ) {
					foreach ( $provider->getModels() as $model ) {
						$model_id   = method_exists( $model, 'getId' ) ? $model->getId() : (string) $model;
						$model_name = method_exists( $model, 'getName' ) ? $model->getName() : $model_id;
						$models[ $model_id ] = $model_name;
					}
				}

				$models_by_provider[ $provider_id ] = $models;
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Registry not available or method signatures differ.
		}

		return $models_by_provider;
	}

	/**
	 * Apply the saved model preference to a prompt builder.
	 *
	 * @param \WP_AI_Client_Prompt_Builder $builder The prompt builder instance.
	 * @return \WP_AI_Client_Prompt_Builder
	 */
	private static function apply_model_preference( object $builder ): object {
		$preference = get_option( 'wairm_model_preference', '' );

		if ( ! empty( $preference ) ) {
			$builder->using_model_preference( $preference );
		}

		return $builder;
	}

	/**
	 * Analyze sentiment of a review text.
	 *
	 * @return array{sentiment: string, score: float, key_phrases: string[]}
	 * @throws \RuntimeException On API failure.
	 */
	public function analyze_sentiment( string $review_text, string $product_name = '' ): array {
		$context = $product_name ? "for the product \"{$product_name}\"" : '';

		$prompt = "Analyze the sentiment of this customer review {$context}.\n\nReview: \"{$review_text}\"";

		$negative_threshold = (float) get_option( 'wairm_negative_threshold', 0.30 );
		$positive_threshold = max( $negative_threshold + 0.30, 0.65 );

		$schema = [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'sentiment'   => [
					'type' => 'string',
					'enum' => self::VALID_SENTIMENTS,
				],
				'score'       => [
					'type'        => 'number',
					'description' => sprintf(
						'Sentiment score: 0.0 (most negative) to 1.0 (most positive). >= %.2f = positive, %.2f-%.2f = neutral, < %.2f = negative.',
						$positive_threshold,
						$negative_threshold,
						$positive_threshold - 0.01,
						$negative_threshold
					),
				],
				'key_phrases' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
			],
			'required'             => [ 'sentiment', 'score', 'key_phrases' ],
		];

		$system = implode( "\n", [
			'You are a sentiment analysis engine for product reviews.',
			'Rules:',
			sprintf( '- score >= %.2f = positive', $positive_threshold ),
			sprintf( '- score %.2f-%.2f = neutral', $negative_threshold, $positive_threshold - 0.01 ),
			sprintf( '- score < %.2f = negative', $negative_threshold ),
			'- key_phrases: extract 1-5 notable phrases from the review in the review\'s original language',
		] );

		$builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system )
			->using_temperature( 0.3 )
			->using_max_tokens( 500 )
			->as_json_response( $schema );

		$result = self::apply_model_preference( $builder )->generate_text();

		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( 'AI sentiment analysis failed: ' . esc_html( $result->get_error_message() ) );
		}

		return $this->parse_sentiment_response( $result );
	}

	/**
	 * Generate a response suggestion for a review.
	 *
	 * @return string The suggested response text.
	 * @throws \RuntimeException On API failure.
	 */
	public function generate_response( string $review_text, string $sentiment, string $product_name, string $store_name ): string {
		$tone_guidance = match ( $sentiment ) {
			'negative' => 'Sincere and empathetic. Apologize that their experience fell short, but do NOT amplify their criticism or agree that the product is bad (never say "that\'s not acceptable" or similar). If the product is physically broken or defective, offer a replacement or refund. If the issue is subjective (fit, color, expectations), be sympathetic and direct them to support — do NOT offer refunds for subjective complaints. No humor, no jokes, no witty remarks.',
			'neutral'  => 'Friendly and conversational. Pick up on something specific they mentioned and respond to that. Keep it light and casual.',
			'positive' => 'Warm and brief — match their energy. If they were casual, be casual back. A short, genuine reply beats a long grateful one. Light humor is fine if it actually makes sense in context.',
			default    => 'Friendly and helpful.',
		};

		$locale   = get_locale();
		$language = self::locale_to_language( $locale );

		$support_email = get_option( 'wairm_support_email', '' );
		if ( empty( $support_email ) ) {
			$support_email = get_option( 'admin_email' );
		}

		$prompt = 'Write a store owner\'s reply to this customer review.' . "\n\n"
			. 'Store: ' . $store_name . "\n"
			. 'Product: ' . $product_name . "\n"
			. 'Sentiment: ' . $sentiment . "\n"
			. 'Support email: ' . $support_email . "\n"
			. 'Review: "' . $review_text . '"';

		$system = implode( "\n", [
			'You are a store owner who personally reads every review. You are helpful, personable, and genuine.',
			'',
			"Tone: {$tone_guidance}",
			"Language: Always respond in {$language}.",
			'',
			'Rules:',
			'- 2-3 sentences. Shorter is better.',
			'- NEVER quote or repeat the customer\'s words back to them. Respond to their point, don\'t echo it.',
			'- NEVER start with "Thank you for your feedback", "Thanks for sharing", "We appreciate", or any canned opener. Jump straight into a real response.',
			'- NEVER use phrases like "I\'m sorry to hear that", "We\'re glad you enjoyed", or "that\'s not acceptable". These sound robotic or damage the brand.',
			'- NEVER amplify or agree with criticism. Don\'t say "that\'s not acceptable" or "you\'re right, that shouldn\'t happen". Apologize that their experience fell short without validating that the product is bad.',
			'- Keep complaints vague in your reply. Say "sorry it didn\'t meet your expectations" rather than repeating specific complaints like "the color was wrong and the quality was poor".',
			'- Sound like a real person writing a quick reply, not a PR department.',
			'- For negative reviews with a DEFECTIVE product (broken, torn, stitching undone, damaged): apologize and offer a replacement or refund via the support email.',
			'- For negative reviews with SUBJECTIVE complaints (sizing, color, expectations, appearance): be empathetic and invite them to reach out to support, but do NOT offer refunds or replacements unprompted.',
			'- For positive reviews: be brief and warm. One genuine sentence beats three grateful ones.',
			'- For suggestions or feature requests: respond casually and optimistically, e.g. "more colors might be coming soon" — not "I\'ll keep pushing on our side".',
			'- Any humor must make logical sense in context. Don\'t force jokes or make confusing quips.',
			'- Match the customer\'s register — casual review gets a casual reply, detailed review gets a more considered reply.',
			'- No exclamation mark overuse. One max per reply.',
			'- When directing a customer to contact support, use the support email provided above.',
		] );

		$builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system )
			->using_temperature( 0.7 )
			->using_max_tokens( 500 );

		$result = self::apply_model_preference( $builder )->generate_text();

		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( 'AI response generation failed: ' . esc_html( $result->get_error_message() ) );
		}

		return trim( $result );
	}

	/**
	 * Generate insights from a collection of reviews for a specific category.
	 *
	 * Returns structured JSON data that can be rendered as cards in the admin UI.
	 *
	 * @param array  $reviews      Array of review data.
	 * @param string $category     Insight category: product, trends, operational, strategic.
	 * @param string $period_label Human-readable period (e.g. "Last 30 days").
	 * @return array Parsed JSON insight data.
	 * @throws \RuntimeException On API failure.
	 */
	public function generate_insights( array $reviews, string $category, string $period_label = 'All time' ): array {
		$locale   = get_locale();
		$language = self::locale_to_language( $locale );

		// Build a compact review summary for the prompt.
		$review_lines = [];
		foreach ( $reviews as $i => $r ) {
			$review_lines[] = sprintf(
				'[%d] Product: %s | Sentiment: %s (%.2f) | Date: %s | Review: "%s"',
				$i + 1,
				$r['product'],
				$r['sentiment'],
				$r['score'],
				wp_date( 'Y-m-d', strtotime( $r['date'] ) ),
				mb_substr( $r['content'], 0, 300 )
			);
		}

		$review_count    = count( $reviews );
		$reviews_text    = implode( "\n", $review_lines );
		$category_prompt = $this->get_insight_prompt( $category );
		$schema          = $this->get_insight_schema( $category );

		$prompt = 'Analyze these ' . $review_count . ' customer reviews and provide structured insights.' . "\n\n"
			. 'Time period: ' . $period_label . "\n"
			. 'Category: ' . $category . "\n\n"
			. $category_prompt . "\n\n"
			. 'Reviews:' . "\n"
			. $reviews_text;

		$system = implode( "\n", [
			'You are a business intelligence analyst helping an e-commerce store owner understand their customer feedback.',
			"Language: All text fields must be in {$language}.",
			'',
			'Rules:',
			'- Be specific and actionable. Reference actual products and quotes from reviews.',
			'- Keep quotes short (max 10 words).',
			'- Present findings prioritized — most important first.',
			'- Use plain, direct language. No corporate jargon.',
			'- If there is not enough data for a field, use an empty array or "Insufficient data".',
			'- All string fields should be concise (1-2 sentences max).',
		] );

		// Extend HTTP timeout for insight generation.
		$extend_timeout = static function () {
			return 120;
		};
		add_filter( 'http_request_timeout', $extend_timeout );

		$builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system )
			->using_temperature( 0.5 )
			->using_max_tokens( 2000 )
			->as_json_response( $schema );

		$result = self::apply_model_preference( $builder )->generate_text();

		remove_filter( 'http_request_timeout', $extend_timeout );

		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( 'AI insight generation failed: ' . esc_html( $result->get_error_message() ) );
		}

		return $this->parse_insight_response( $result );
	}

	/**
	 * Get the JSON schema for each insight category.
	 */
	private function get_insight_schema( string $category ): array {
		$rating_field = [
			'type' => 'string',
			'enum' => [ 'positive', 'mixed', 'negative', 'no_data' ],
		];
		$string_array = [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ];

		$ops_section = [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'rating'   => $rating_field,
				'findings' => $string_array,
			],
			'required'             => [ 'rating', 'findings' ],
		];

		return match ( $category ) {
			'product' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'products' => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => [
								'name'            => [ 'type' => 'string' ],
								'review_count'    => [ 'type' => 'integer' ],
								'quality_score'   => [ 'type' => 'string', 'enum' => [ 'positive', 'mixed', 'negative' ] ],
								'strengths'       => $string_array,
								'complaints'      => $string_array,
								'sizing'          => [ 'type' => 'string' ],
								'priority_action' => [ 'type' => 'string' ],
							],
							'required'             => [ 'name', 'review_count', 'quality_score', 'strengths', 'complaints', 'sizing', 'priority_action' ],
						],
					],
					'summary' => [ 'type' => 'string' ],
				],
				'required'             => [ 'products', 'summary' ],
			],
			'trends' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'overall_direction' => [ 'type' => 'string', 'enum' => [ 'improving', 'stable', 'declining' ] ],
					'overall_summary'   => [ 'type' => 'string' ],
					'emerging_issues'   => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => [
								'issue'   => [ 'type' => 'string' ],
								'product' => [ 'type' => 'string' ],
								'detail'  => [ 'type' => 'string' ],
							],
							'required'             => [ 'issue', 'product', 'detail' ],
						],
					],
					'product_shifts' => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => [
								'product'   => [ 'type' => 'string' ],
								'direction' => [ 'type' => 'string', 'enum' => [ 'improving', 'declining', 'stable' ] ],
								'detail'    => [ 'type' => 'string' ],
							],
							'required'             => [ 'product', 'direction', 'detail' ],
						],
					],
					'patterns' => $string_array,
				],
				'required'             => [ 'overall_direction', 'overall_summary', 'emerging_issues', 'product_shifts', 'patterns' ],
			],
			'operational' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'shipping'         => $ops_section,
					'expectations'     => $ops_section,
					'price_value'      => $ops_section,
					'support'          => $ops_section,
					'priority_actions' => $string_array,
				],
				'required'             => [ 'shipping', 'expectations', 'price_value', 'support', 'priority_actions' ],
			],
			'strategic' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'feature_requests' => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => [
								'request'  => [ 'type' => 'string' ],
								'mentions' => [ 'type' => 'integer' ],
								'products' => $string_array,
							],
							'required'             => [ 'request', 'mentions', 'products' ],
						],
					],
					'competitive' => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => [
								'brand'   => [ 'type' => 'string' ],
								'context' => [ 'type' => 'string' ],
							],
							'required'             => [ 'brand', 'context' ],
						],
					],
					'repeat_signals'   => $string_array,
					'marketing_quotes' => $string_array,
					'summary'          => [ 'type' => 'string' ],
				],
				'required'             => [ 'feature_requests', 'competitive', 'repeat_signals', 'marketing_quotes', 'summary' ],
			],
			default => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [ 'insights' => $string_array ],
				'required'             => [ 'insights' ],
			],
		};
	}

	/**
	 * Get the prompt instructions for each insight category.
	 */
	private function get_insight_prompt( string $category ): string {
		return match ( $category ) {
			'product' => 'Analyze reviews at the product level. For each product with 2+ reviews, provide strengths, complaints, sizing info, and a priority action. Skip products with only 1 review unless notable.',
			'trends'  => 'Analyze sentiment trends over time. Is it improving, stable, or declining? Identify emerging issues and products whose perception is shifting.',
			'operational' => 'Extract operational insights: shipping/fulfillment quality, whether products match descriptions, price-to-value perception, and customer service mentions.',
			'strategic'   => 'Extract strategic insights: feature/product requests, competitive brand mentions, repeat purchase signals, and customer quotes usable in marketing.',
			default       => 'Provide general insights from the reviews.',
		};
	}

	/**
	 * Parse the JSON insight response.
	 *
	 * @throws \RuntimeException On parse failure.
	 */
	private function parse_insight_response( string $text ): array {
		$text = preg_replace( '/^```json\s*/', '', $text );
		$text = preg_replace( '/\s*```$/', '', $text );

		$data = json_decode( $text, true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Failed to parse insight response from AI.' );
		}

		return $data;
	}

	/**
	 * Convert a WordPress locale code to a human-readable language name.
	 */
	private static function locale_to_language( string $locale ): string {
		$map = [
			'en' => 'English',
			'de' => 'German',
			'es' => 'Spanish',
			'fr' => 'French',
			'it' => 'Italian',
			'pt' => 'Portuguese',
			'nl' => 'Dutch',
			'sv' => 'Swedish',
			'da' => 'Danish',
			'nb' => 'Norwegian',
			'nn' => 'Norwegian',
			'fi' => 'Finnish',
			'pl' => 'Polish',
			'cs' => 'Czech',
			'ja' => 'Japanese',
			'ko' => 'Korean',
			'zh' => 'Chinese',
			'ar' => 'Arabic',
			'he' => 'Hebrew',
			'ru' => 'Russian',
			'tr' => 'Turkish',
			'el' => 'Greek',
			'hi' => 'Hindi',
			'th' => 'Thai',
			'vi' => 'Vietnamese',
			'uk' => 'Ukrainian',
			'ro' => 'Romanian',
			'hu' => 'Hungarian',
		];

		$prefix = strtolower( substr( $locale, 0, 2 ) );

		if ( isset( $map[ $prefix ] ) ) {
			return $map[ $prefix ];
		}

		// Fall back to the native language name from WordPress translations.
		$translations = wp_get_available_translations();
		if ( isset( $translations[ $locale ]['english_name'] ) ) {
			return $translations[ $locale ]['english_name'];
		}

		// Last resort: use the locale code itself so the AI still gets a language hint.
		return $locale;
	}

	/**
	 * Parse the JSON sentiment response.
	 *
	 * @return array{sentiment: string, score: float, key_phrases: string[]}
	 * @throws \RuntimeException On parse failure.
	 */
	private function parse_sentiment_response( string $text ): array {
		// Strip markdown code fences if present.
		$text = preg_replace( '/^```json\s*/', '', $text );
		$text = preg_replace( '/\s*```$/', '', $text );

		$data = json_decode( $text, true );
		if ( ! is_array( $data ) || ! isset( $data['sentiment'], $data['score'] ) ) {
			throw new \RuntimeException( 'Failed to parse sentiment response from AI.' );
		}

		$sentiment = in_array( $data['sentiment'], self::VALID_SENTIMENTS, true )
			? $data['sentiment']
			: 'neutral';

		return [
			'sentiment'   => $sentiment,
			'score'       => max( 0.0, min( 1.0, (float) $data['score'] ) ),
			'key_phrases' => array_slice( array_map( 'sanitize_text_field', $data['key_phrases'] ?? [] ), 0, 5 ),
		];
	}
}
