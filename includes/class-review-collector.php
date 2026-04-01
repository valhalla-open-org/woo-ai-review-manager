<?php
/**
 * Hooks into WooCommerce order events to trigger review invitations.
 *
 * @package WooAIReviewManager
 */

declare(strict_types=1);

namespace WooAIReviewManager;

defined( 'ABSPATH' ) || exit;

final class Review_Collector {

	public function __construct() {
		// Hook into order status transitions.
		add_action( 'woocommerce_order_status_completed', [ $this, 'schedule_invitation' ], 10, 1 );

		// Hook into new WooCommerce reviews.
		// comment_post fires from wp_new_comment() (front-end comment forms).
		add_action( 'comment_post', [ $this, 'on_review_submitted' ], 10, 3 );
		// wp_insert_comment fires from wp_insert_comment() (programmatic inserts like our form handler).
		add_action( 'wp_insert_comment', [ $this, 'on_review_inserted' ], 10, 2 );
	}

	/**
	 * Schedule a review invitation when an order is completed.
	 */
	public function schedule_invitation( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Skip if already invited for this order.
		if ( $order->get_meta( '_wairm_invitation_created' ) ) {
			return;
		}

		$email = $order->get_billing_email();
		if ( empty( $email ) || ! is_email( $email ) ) {
			return;
		}

		// Collect product IDs from the order.
		$product_ids = [];
		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			if ( $product_id > 0 ) {
				$product_ids[] = $product_id;
			}
		}

		if ( empty( $product_ids ) ) {
			return;
		}

		$delay_days = absint( get_option( 'wairm_invitation_delay_days', 7 ) );
		$expiry_days = absint( get_option( 'wairm_invitation_expiry_days', 30 ) );

		global $wpdb;

		$token = wp_generate_password( 48, false );
		$now   = current_time( 'mysql', true );

		$wpdb->insert(
			$wpdb->prefix . 'wairm_review_invitations',
			[
				'order_id'       => $order_id,
				'customer_email' => sanitize_email( $email ),
				'customer_name'  => sanitize_text_field( $order->get_billing_first_name() ),
				'product_ids'    => wp_json_encode( $product_ids ),
				'token'          => $token,
				'expires_at'     => gmdate( 'Y-m-d H:i:s', strtotime( "+{$expiry_days} days", strtotime( $now ) ) ),
				'created_at'     => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		$invitation_id = $wpdb->insert_id;
		if ( ! $invitation_id ) {
			return;
		}

		// Queue the initial email.
		$send_at = gmdate( 'Y-m-d H:i:s', strtotime( "+{$delay_days} days", strtotime( $now ) ) );

		$wpdb->insert(
			$wpdb->prefix . 'wairm_email_queue',
			[
				'invitation_id' => $invitation_id,
				'email_type'    => 'initial',
				'scheduled_at'  => $send_at,
			],
			[ '%d', '%s', '%s' ]
		);

		// Queue reminder if enabled.
		$reminder_enabled = get_option( 'wairm_reminder_enabled', 'yes' );
		if ( 'yes' === $reminder_enabled ) {
			$reminder_days = absint( get_option( 'wairm_reminder_delay_days', 14 ) );
			$reminder_at   = gmdate( 'Y-m-d H:i:s', strtotime( "+{$reminder_days} days", strtotime( $now ) ) );

			$wpdb->insert(
				$wpdb->prefix . 'wairm_email_queue',
				[
					'invitation_id' => $invitation_id,
					'email_type'    => 'reminder',
					'scheduled_at'  => $reminder_at,
				],
				[ '%d', '%s', '%s' ]
			);
		}

		$order->update_meta_data( '_wairm_invitation_created', $invitation_id );
		$order->save();
	}

	/**
	 * Handle reviews inserted via wp_insert_comment() (e.g. our invitation form).
	 *
	 * wp_insert_comment() fires the 'wp_insert_comment' action but NOT 'comment_post'.
	 *
	 * @param int         $comment_id The comment ID.
	 * @param \WP_Comment $comment    The comment object.
	 */
	public function on_review_inserted( int $comment_id, \WP_Comment $comment ): void {
		$this->maybe_queue_analysis( $comment_id, $comment );
	}

	/**
	 * When a WooCommerce review is submitted, queue it for sentiment analysis.
	 */
	public function on_review_submitted( int $comment_id, int|string $comment_approved, array $comment_data ): void {
		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return;
		}
		$this->maybe_queue_analysis( $comment_id, $comment );
	}

	/**
	 * Check if a comment is a product review and queue it for analysis.
	 */
	private function maybe_queue_analysis( int $comment_id, \WP_Comment $comment ): void {
		if ( 'review' !== $comment->comment_type ) {
			return;
		}

		// Only process product reviews.
		if ( 'product' !== get_post_type( (int) $comment->comment_post_ID ) ) {
			return;
		}

		$auto_analyze = get_option( 'wairm_auto_analyze', 'yes' );
		if ( 'yes' !== $auto_analyze ) {
			return;
		}

		// Prevent duplicate scheduling — check if already analyzed.
		global $wpdb;
		$already = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}wairm_review_sentiment WHERE comment_id = %d",
				$comment_id
			)
		);
		if ( $already ) {
			return;
		}

		// Queue for async sentiment analysis via Action Scheduler (bundled with WooCommerce).
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time(),
				'wairm_analyze_single_review',
				[ 'comment_id' => $comment_id ],
				'wairm'
			);
		} else {
			// Fallback: analyze synchronously if Action Scheduler is unavailable.
			do_action( 'wairm_analyze_single_review', $comment_id );
		}
	}
}
