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

	/**
	 * Check whether the WordPress AI Client is available and configured.
	 */
	public static function is_available(): bool {
		return function_exists( 'wp_ai_client_prompt' );
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

		$schema = [
			'type'       => 'object',
			'properties' => [
				'sentiment'   => [
					'type' => 'string',
					'enum' => [ 'positive', 'neutral', 'negative' ],
				],
				'score'       => [
					'type'        => 'number',
					'minimum'     => 0.0,
					'maximum'     => 1.0,
					'description' => 'Sentiment score: 0.0 (most negative) to 1.0 (most positive). >= 0.65 = positive, 0.35-0.64 = neutral, < 0.35 = negative.',
				],
				'key_phrases' => [
					'type'     => 'array',
					'items'    => [ 'type' => 'string' ],
					'minItems' => 1,
					'maxItems' => 5,
				],
			],
			'required'   => [ 'sentiment', 'score', 'key_phrases' ],
		];

		$system = implode( "\n", [
			'You are a sentiment analysis engine for product reviews.',
			'Rules:',
			'- score >= 0.65 = positive',
			'- score 0.35-0.64 = neutral',
			'- score < 0.35 = negative',
			'- key_phrases: extract 1-5 notable phrases from the review in the review\'s original language',
		] );

		$result = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system )
			->using_temperature( 0.3 )
			->using_max_tokens( 500 )
			->as_json_response( $schema )
			->generate_text();

		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( 'AI sentiment analysis failed: ' . $result->get_error_message() );
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
			'negative' => 'Empathetic, apologetic, and solution-oriented. Offer to make it right without being defensive.',
			'neutral'  => 'Warm and appreciative. Invite them to share more feedback or reach out.',
			'positive' => 'Grateful and enthusiastic. Mention specific things they liked. Keep it genuine, not corporate.',
			default    => 'Professional and friendly.',
		};

		$locale   = get_locale();
		$language = self::locale_to_language( $locale );

		$prompt = <<<PROMPT
Write a store owner's response to this customer review.

Store: {$store_name}
Product: {$product_name}
Review sentiment: {$sentiment}
Review: "{$review_text}"
PROMPT;

		$system = implode( "\n", [
			"Tone: {$tone_guidance}",
			"Language: Always respond in {$language}.",
			'Rules:',
			'- 2-4 sentences max',
			'- Sound human, not like a template',
			'- Reference specific details from the review',
			'- For negative reviews: acknowledge the issue and offer resolution',
			'- Do not use exclamation marks excessively',
			'- Do not start with a generic thank-you opener',
		] );

		$result = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system )
			->using_temperature( 0.7 )
			->using_max_tokens( 500 )
			->generate_text();

		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( 'AI response generation failed: ' . $result->get_error_message() );
		}

		return trim( $result );
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

		$valid_sentiments = [ 'positive', 'neutral', 'negative' ];
		$sentiment        = in_array( $data['sentiment'], $valid_sentiments, true )
			? $data['sentiment']
			: 'neutral';

		return [
			'sentiment'   => $sentiment,
			'score'       => max( 0.0, min( 1.0, (float) $data['score'] ) ),
			'key_phrases' => array_slice( array_map( 'sanitize_text_field', $data['key_phrases'] ?? [] ), 0, 5 ),
		];
	}
}
