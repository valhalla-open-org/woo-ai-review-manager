<?php
/**
 * Handles sending review invitation emails and the public review form.
 *
 * @package WooAIReviewManager
 */

declare(strict_types=1);

namespace WooAIReviewManager;

defined( 'ABSPATH' ) || exit;

final class Email_Sender {

	public function __construct() {
		add_shortcode( 'wairm_review_form', [ $this, 'review_form_shortcode' ] );
		add_action( 'admin_post_nopriv_wairm_submit_review', [ $this, 'handle_form_submission' ] );
		add_action( 'admin_post_wairm_submit_review', [ $this, 'handle_form_submission' ] );
		add_action( 'template_redirect', [ $this, 'intercept_review_token' ] );
		add_action( 'wp', [ $this, 'maybe_show_thank_you' ] );
	}

	/**
	 * Intercept review token URLs and render the review form on the front page.
	 *
	 * When a customer clicks the email link (home_url?wairm_token=xxx&action=review),
	 * this renders the review form directly via a template_redirect so no shortcode
	 * page setup is needed.
	 */
	public function intercept_review_token(): void {
		if ( empty( $_GET['wairm_token'] ) || ( $_GET['action'] ?? '' ) !== 'review' ) {
			return;
		}

		$form_html = $this->review_form_shortcode();

		// Render a minimal standalone page with the review form.
		$site_name = esc_html( get_bloginfo( 'name' ) );
		$charset   = esc_attr( get_bloginfo( 'charset' ) );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php echo $charset; ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html__( 'Leave a Review', 'woo-ai-review-manager' ) . ' — ' . $site_name; ?></title>
			<?php wp_head(); ?>
			<style>
				body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px; background: #f9f9f9; }
				.wairm-review-page { max-width: 700px; margin: 40px auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
				.wairm-review-page h2 { margin-top: 0; }
			</style>
		</head>
		<body>
			<div class="wairm-review-page">
				<?php echo $form_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped in shortcode. ?>
			</div>
			<?php wp_footer(); ?>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Process the email sending queue (called via cron).
	 */
	public static function process_queue(): void {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// Get up to 20 queued emails ready to send.
		// Allow invitations in 'pending' (initial) or 'sent'/'clicked' (reminder) statuses.
		$pending = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT eq.*, ri.customer_email, ri.customer_name, ri.order_id, ri.token, ri.product_ids
				 FROM {$wpdb->prefix}wairm_email_queue eq
				 JOIN {$wpdb->prefix}wairm_review_invitations ri ON ri.id = eq.invitation_id
				 WHERE eq.status = 'queued'
				   AND eq.scheduled_at <= %s
				   AND ri.status IN ('pending', 'sent', 'clicked')
				   AND ri.status != 'reviewed'
				 LIMIT 20",
				$now
			)
		);

		foreach ( $pending as $email ) {
			self::send_email( $email );
		}
	}

	private static function send_email( object $email ): void {
		global $wpdb;

		// Increment attempts before trying.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}wairm_email_queue SET attempts = attempts + 1 WHERE id = %d",
				$email->id
			)
		);

		// Build email content.
		$subject = self::resolve_subject_placeholders( $email );
		$body    = self::build_email_body( $email );

		if ( '' === $body ) {
			$wpdb->update(
				$wpdb->prefix . 'wairm_email_queue',
				[ 'status' => 'failed', 'last_error' => 'Empty email body (invalid product data)' ],
				[ 'id' => $email->id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
			return;
		}

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . get_option( 'wairm_email_from_name', get_bloginfo( 'name' ) ) . ' <' . get_option( 'admin_email' ) . '>',
		];

		$sent = wp_mail( $email->customer_email, $subject, $body, $headers );

		if ( $sent ) {
			// Mark email as sent.
			$wpdb->update(
				$wpdb->prefix . 'wairm_email_queue',
				[ 'status' => 'sent', 'sent_at' => current_time( 'mysql', true ) ],
				[ 'id' => $email->id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);

			// Update invitation status to 'sent' only if still 'pending'.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}wairm_review_invitations
					 SET status = 'sent', sent_at = %s
					 WHERE id = %d AND status = 'pending'",
					current_time( 'mysql', true ),
					$email->invitation_id
				)
			);
		} else {
			$wpdb->update(
				$wpdb->prefix . 'wairm_email_queue',
				[ 'status' => 'failed', 'last_error' => 'wp_mail returned false' ],
				[ 'id' => $email->id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
		}
	}

	/**
	 * Replace {customer_name} and {store_name} placeholders in the email subject.
	 */
	private static function resolve_subject_placeholders( object $email ): string {
		$subject = get_option( 'wairm_email_subject', __( 'How was your recent purchase?', 'woo-ai-review-manager' ) );

		return str_replace(
			[ '{customer_name}', '{store_name}' ],
			[ $email->customer_name, get_bloginfo( 'name' ) ],
			$subject
		);
	}

	private static function build_email_body( object $email ): string {
		$product_ids = json_decode( $email->product_ids, true );
		if ( ! is_array( $product_ids ) ) {
			return '';
		}
		$products = [];

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$products[] = [
					'name'  => $product->get_name(),
					'url'   => get_permalink( $product_id ),
					'image' => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ),
				];
			}
		}

		$review_link = add_query_arg(
			[
				'wairm_token' => $email->token,
				'action'      => 'review',
			],
			get_home_url()
		);

		$expiry_days = absint( get_option( 'wairm_invitation_expiry_days', 30 ) );

		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
			<title><?php echo esc_html( self::resolve_subject_placeholders( $email ) ); ?></title>
			<style>
				body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px; background-color: #f9f9f9; }
				.container { max-width: 600px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
				h1 { color: #2c3e50; margin-top: 0; }
				.product { display: flex; margin: 20px 0; padding: 15px; border: 1px solid #e0e0e0; border-radius: 6px; }
				.product-image { width: 80px; height: 80px; object-fit: cover; border-radius: 4px; margin-right: 15px; }
				.product-info { flex: 1; }
				.product-name { font-weight: bold; margin-bottom: 5px; }
				.cta-button { display: inline-block; background: #3498db; color: white; padding: 12px 25px; text-decoration: none; border-radius: 6px; font-weight: bold; margin-top: 20px; }
				.cta-button:hover { background: #2980b9; }
				.footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 14px; color: #777; }
			</style>
		</head>
		<body>
			<div class="container">
				<h1><?php esc_html_e( 'How was your recent purchase?', 'woo-ai-review-manager' ); ?></h1>

				<p><?php
					printf(
						/* translators: %s: customer name */
						esc_html__( 'Hi %s, thank you for your recent order!', 'woo-ai-review-manager' ),
						esc_html( $email->customer_name )
					);
				?></p>

				<p><?php esc_html_e( 'We would love to hear what you think about the products you purchased:', 'woo-ai-review-manager' ); ?></p>

				<?php foreach ( $products as $product ) : ?>
				<div class="product">
					<?php if ( $product['image'] ) : ?>
						<img src="<?php echo esc_url( $product['image'] ); ?>" alt="<?php echo esc_attr( $product['name'] ); ?>" class="product-image">
					<?php endif; ?>
					<div class="product-info">
						<div class="product-name"><?php echo esc_html( $product['name'] ); ?></div>
						<a href="<?php echo esc_url( $product['url'] ); ?>" target="_blank"><?php esc_html_e( 'View product', 'woo-ai-review-manager' ); ?></a>
					</div>
				</div>
				<?php endforeach; ?>

				<p><?php esc_html_e( 'Please take a moment to share your experience by clicking the button below:', 'woo-ai-review-manager' ); ?></p>

				<p style="text-align: center;">
					<a href="<?php echo esc_url( $review_link ); ?>" class="cta-button">
						<?php esc_html_e( 'Leave a Review', 'woo-ai-review-manager' ); ?>
					</a>
				</p>

				<p style="font-size: 14px; color: #666;">
					<?php
					printf(
						/* translators: %d: number of days */
						esc_html__( 'This review link will expire in %d days.', 'woo-ai-review-manager' ),
						$expiry_days
					);
					?>
				</p>

				<div class="footer">
					<p><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
					<p style="font-size: 13px;">
						<?php esc_html_e( 'You are receiving this email because you recently made a purchase. If you do not wish to receive review invitations, please let us know.', 'woo-ai-review-manager' ); ?>
					</p>
				</div>
			</div>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Shortcode to render a review form for token-based access.
	 */
	public function review_form_shortcode(): string {
		$token = sanitize_text_field( $_GET['wairm_token'] ?? '' );
		if ( empty( $token ) ) {
			return '<p>' . esc_html__( 'Invalid or missing review invitation.', 'woo-ai-review-manager' ) . '</p>';
		}

		global $wpdb;

		// Accept invitations that are sent or clicked (allow page refreshes).
		$invitation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wairm_review_invitations
				 WHERE token = %s AND status IN ('sent', 'clicked') AND expires_at > %s",
				$token,
				current_time( 'mysql', true )
			)
		);

		if ( ! $invitation ) {
			return '<p>' . esc_html__( 'This review invitation is invalid or has expired.', 'woo-ai-review-manager' ) . '</p>';
		}

		// Mark as clicked if still 'sent'.
		if ( 'sent' === $invitation->status ) {
			$wpdb->update(
				$wpdb->prefix . 'wairm_review_invitations',
				[ 'status' => 'clicked', 'clicked_at' => current_time( 'mysql', true ) ],
				[ 'id' => $invitation->id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
		}

		$product_ids = json_decode( $invitation->product_ids, true );
		if ( ! is_array( $product_ids ) ) {
			return '<p>' . esc_html__( 'Invalid invitation data.', 'woo-ai-review-manager' ) . '</p>';
		}

		ob_start();
		?>
		<div class="wairm-review-form">
			<h2><?php esc_html_e( 'Leave a Review', 'woo-ai-review-manager' ); ?></h2>
			<p><?php esc_html_e( 'Please share your thoughts on the products you purchased:', 'woo-ai-review-manager' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wairm_submit_review">
				<input type="hidden" name="invitation_id" value="<?php echo absint( $invitation->id ); ?>">
				<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
				<?php wp_nonce_field( 'wairm_review' ); ?>

				<?php foreach ( $product_ids as $product_id ) :
					$product = wc_get_product( $product_id );
					if ( ! $product ) {
						continue;
					}
				?>
				<div style="margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid #eee;">
					<h3><?php echo esc_html( $product->get_name() ); ?></h3>
					<input type="hidden" name="product_ids[]" value="<?php echo absint( $product_id ); ?>">

					<div style="margin: 15px 0;">
						<label>
							<strong><?php esc_html_e( 'Rating:', 'woo-ai-review-manager' ); ?></strong><br>
							<select name="rating[<?php echo absint( $product_id ); ?>]" required>
								<option value=""><?php esc_html_e( 'Select a rating', 'woo-ai-review-manager' ); ?></option>
								<?php for ( $i = 5; $i >= 1; $i-- ) : ?>
								<option value="<?php echo absint( $i ); ?>"><?php echo absint( $i ); ?> <?php esc_html_e( 'stars', 'woo-ai-review-manager' ); ?></option>
								<?php endfor; ?>
							</select>
						</label>
					</div>

					<div style="margin: 15px 0;">
						<label>
							<strong><?php esc_html_e( 'Your Review:', 'woo-ai-review-manager' ); ?></strong><br>
							<textarea name="review[<?php echo absint( $product_id ); ?>]" rows="4" style="width: 100%; max-width: 500px;" placeholder="<?php esc_attr_e( 'What did you like or dislike about this product?', 'woo-ai-review-manager' ); ?>" required></textarea>
						</label>
					</div>
				</div>
				<?php endforeach; ?>

				<div style="margin-top: 30px;">
					<button type="submit" style="background: #3498db; color: white; border: none; padding: 12px 25px; border-radius: 6px; font-weight: bold; cursor: pointer;">
						<?php esc_html_e( 'Submit Reviews', 'woo-ai-review-manager' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Handle review form submission.
	 */
	public function handle_form_submission(): void {
		if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'wairm_review' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'woo-ai-review-manager' ) );
		}

		global $wpdb;

		$invitation_id = absint( $_POST['invitation_id'] ?? 0 );
		$token         = sanitize_text_field( $_POST['token'] ?? '' );

		// Verify invitation exists, matches token, and hasn't already been reviewed.
		$invitation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wairm_review_invitations
				 WHERE id = %d AND token = %s AND status IN ('sent', 'clicked')",
				$invitation_id,
				$token
			)
		);

		if ( ! $invitation ) {
			wp_die( esc_html__( 'Invalid invitation or reviews already submitted.', 'woo-ai-review-manager' ) );
		}

		// Process each product review.
		$product_ids = $_POST['product_ids'] ?? [];
		$ratings     = $_POST['rating'] ?? [];
		$reviews     = $_POST['review'] ?? [];

		foreach ( (array) $product_ids as $product_id ) {
			$product_id = absint( $product_id );
			$rating     = absint( $ratings[ $product_id ] ?? 0 );
			$content    = sanitize_textarea_field( $reviews[ $product_id ] ?? '' );

			if ( $rating < 1 || $rating > 5 || empty( $content ) ) {
				continue;
			}

			// Check for duplicate review from this customer for this product.
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT comment_ID FROM {$wpdb->comments}
					 WHERE comment_post_ID = %d
					   AND comment_author_email = %s
					   AND comment_type = %s
					 LIMIT 1",
					$product_id,
					$invitation->customer_email,
					'review'
				)
			);

			if ( $existing ) {
				continue;
			}

			$comment_data = [
				'comment_post_ID'      => $product_id,
				'comment_author'       => $invitation->customer_name,
				'comment_author_email' => $invitation->customer_email,
				'comment_content'      => $content,
				'comment_type'         => 'review',
				'comment_approved'     => 1,
				'comment_meta'         => [ 'rating' => $rating ],
			];

			wp_insert_comment( $comment_data );
		}

		// Mark invitation as reviewed to prevent resubmission.
		$wpdb->update(
			$wpdb->prefix . 'wairm_review_invitations',
			[ 'status' => 'reviewed' ],
			[ 'id' => $invitation_id ],
			[ '%s' ],
			[ '%d' ]
		);

		// Cancel any pending reminder emails for this invitation.
		$wpdb->update(
			$wpdb->prefix . 'wairm_email_queue',
			[ 'status' => 'cancelled' ],
			[ 'invitation_id' => $invitation_id, 'status' => 'queued' ],
			[ '%s' ],
			[ '%d', '%s' ]
		);

		$redirect_url = add_query_arg(
			[
				'wairm_thank_you' => '1',
				'_wpnonce'        => wp_create_nonce( 'wairm_thank_you' ),
			],
			get_home_url()
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Show thank-you notice on the front page after review submission.
	 */
	public function maybe_show_thank_you(): void {
		if ( ! isset( $_GET['wairm_thank_you'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'wairm_thank_you' ) ) {
			return;
		}
		add_filter( 'the_content', static function ( string $content ): string {
			$notice = '<div class="wairm-thank-you" style="padding: 20px; margin: 20px 0; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 6px; text-align: center;">'
				. '<p style="font-size: 18px; margin: 0;">' . esc_html__( 'Thank you for your reviews! Your feedback helps us improve.', 'woo-ai-review-manager' ) . '</p>'
				. '</div>';
			return $notice . $content;
		} );
	}
}
