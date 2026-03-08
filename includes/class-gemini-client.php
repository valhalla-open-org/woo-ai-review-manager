<?php
/**
 * Gemini API client for sentiment analysis and response generation.
 *
 * @package WooAIReviewManager
 */

declare(strict_types=1);

namespace WooAIReviewManager;

defined( 'ABSPATH' ) || exit;

final class Gemini_Client {

	private const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';

	private string $api_key;

	public function __construct() {
		$this->api_key = self::get_api_key();
	}

	/**
	 * Retrieve the API key from options (encrypted at rest via WP options API).
	 */
	public static function get_api_key(): string {
		$key = get_option( 'wairm_gemini_api_key', '' );
		return is_string( $key ) ? trim( $key ) : '';
	}

	public function is_configured(): bool {
		return '' !== $this->api_key;
	}

	/**
	 * Analyze sentiment of a review text.
	 *
	 * @return array{sentiment: string, score: float, key_phrases: string[]}
	 * @throws \RuntimeException On API failure.
	 */
	public function analyze_sentiment( string $review_text, string $product_name = '' ): array {
		$prompt = $this->build_sentiment_prompt( $review_text, $product_name );
		$result = $this->call_api( $prompt );

		return $this->parse_sentiment_response( $result );
	}

	/**
	 * Generate a response suggestion for a review.
	 *
	 * @return string The suggested response text.
	 * @throws \RuntimeException On API failure.
	 */
	public function generate_response( string $review_text, string $sentiment, string $product_name, string $store_name ): string {
		$prompt = $this->build_response_prompt( $review_text, $sentiment, $product_name, $store_name );
		$result = $this->call_api( $prompt );

		return $this->extract_text( $result );
	}

	private function build_sentiment_prompt( string $review_text, string $product_name ): string {
		$context = $product_name ? "for the product \"{$product_name}\"" : '';

		return <<<PROMPT
Analyze the sentiment of this customer review {$context}.

Review: "{$review_text}"

Respond in valid JSON only, no markdown, with this exact structure:
{
  "sentiment": "positive" | "neutral" | "negative",
  "score": <float between 0.0 (most negative) and 1.0 (most positive)>,
  "key_phrases": ["phrase1", "phrase2", "phrase3"]
}

Rules:
- score >= 0.65 = positive
- score 0.35-0.64 = neutral
- score < 0.35 = negative
- key_phrases: extract 1-5 notable phrases from the review
PROMPT;
	}

	private function build_response_prompt( string $review_text, string $sentiment, string $product_name, string $store_name ): string {
		$tone_guidance = match ( $sentiment ) {
			'negative' => 'Empathetic, apologetic, and solution-oriented. Offer to make it right without being defensive.',
			'neutral'  => 'Warm and appreciative. Invite them to share more feedback or reach out.',
			'positive' => 'Grateful and enthusiastic. Mention specific things they liked. Keep it genuine, not corporate.',
			default    => 'Professional and friendly.',
		};

		return <<<PROMPT
Write a store owner's response to this customer review.

Store: {$store_name}
Product: {$product_name}
Review sentiment: {$sentiment}
Review: "{$review_text}"

Tone: {$tone_guidance}

Rules:
- 2-4 sentences max
- Sound human, not like a template
- Reference specific details from the review
- For negative reviews: acknowledge the issue and offer resolution
- Do not use exclamation marks excessively
- Do not start with "Thank you for your review"
PROMPT;
	}

	/**
	 * Make a request to the Gemini API.
	 *
	 * @throws \RuntimeException On failure.
	 */
	private function call_api( string $prompt ): array {
		if ( ! $this->is_configured() ) {
			throw new \RuntimeException( 'Gemini API key not configured.' );
		}

		$url = self::API_URL . '?key=' . $this->api_key;

		$body = [
			'contents' => [
				[
					'parts' => [
						[ 'text' => $prompt ],
					],
				],
			],
			'generationConfig' => [
				'temperature'     => 0.3,
				'maxOutputTokens' => 500,
			],
		];

		$response = wp_remote_post(
			$url,
			[
				'timeout' => 30,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Gemini API request failed: ' . $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status ) {
			$error = $data['error']['message'] ?? "HTTP {$status}";
			throw new \RuntimeException( "Gemini API error: {$error}" );
		}

		return $data;
	}

	private function extract_text( array $response ): string {
		return trim( $response['candidates'][0]['content']['parts'][0]['text'] ?? '' );
	}

	private function parse_sentiment_response( array $response ): array {
		$text = $this->extract_text( $response );

		// Strip markdown code fences if present.
		$text = preg_replace( '/^```json\s*/', '', $text );
		$text = preg_replace( '/\s*```$/', '', $text );

		$data = json_decode( $text, true );
		if ( ! is_array( $data ) || ! isset( $data['sentiment'], $data['score'] ) ) {
			throw new \RuntimeException( 'Failed to parse sentiment response from Gemini.' );
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
