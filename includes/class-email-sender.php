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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public token-based URL, no nonce available.
		if ( empty( $_GET['wairm_token'] ) || sanitize_key( wp_unslash( $_GET['action'] ?? '' ) ) !== 'review' ) {
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
				/* Reset & base */
				*, *::before, *::after { box-sizing: border-box; }
				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, sans-serif;
					font-size: 15px;
					line-height: 1.6;
					color: #1a1a1a;
					margin: 0;
					padding: 20px;
					background: linear-gradient(135deg, #f5f7fa 0%, #e4e9f0 100%);
					min-height: 100vh;
				}

				/* Page container */
				.wairm-review-page {
					max-width: 640px;
					margin: 30px auto;
					background: #fff;
					padding: 40px;
					border-radius: 12px;
					box-shadow: 0 4px 24px rgba(0,0,0,0.08);
				}
				@media (max-width: 600px) {
					body { padding: 12px; }
					.wairm-review-page { padding: 24px 20px; margin: 12px auto; }
				}

				/* Store branding */
				.wairm-store-name {
					font-size: 13px;
					font-weight: 600;
					text-transform: uppercase;
					letter-spacing: 1px;
					color: #888;
					margin: 0 0 6px;
				}

				/* Headings */
				.wairm-review-page h2 {
					margin: 0 0 8px;
					font-size: 26px;
					font-weight: 700;
					color: #1a1a1a;
				}
				.wairm-review-page > .wairm-review-form > p:first-of-type {
					margin: 0 0 28px;
					font-size: 15px;
					color: #555;
				}

				/* Product card */
				.wairm-product-card {
					background: #fafbfc;
					border: 1px solid #e8ecf0;
					border-radius: 10px;
					padding: 24px;
					margin-bottom: 20px;
					transition: border-color 0.2s;
				}
				.wairm-product-card:hover { border-color: #c0c8d4; }
				.wairm-product-card:last-of-type { margin-bottom: 24px; }

				.wairm-product-header {
					display: flex;
					align-items: center;
					gap: 16px;
					margin-bottom: 20px;
				}
				.wairm-product-image {
					width: 64px;
					height: 64px;
					border-radius: 8px;
					object-fit: cover;
					border: 1px solid #e8ecf0;
					flex-shrink: 0;
				}
				.wairm-product-image-placeholder {
					width: 64px;
					height: 64px;
					border-radius: 8px;
					background: #e8ecf0;
					display: flex;
					align-items: center;
					justify-content: center;
					flex-shrink: 0;
					color: #aaa;
					font-size: 24px;
				}
				.wairm-product-name {
					font-size: 18px;
					font-weight: 600;
					color: #1a1a1a;
					margin: 0;
				}

				/* Star rating */
				.wairm-star-rating {
					display: flex;
					flex-direction: row-reverse;
					justify-content: flex-end;
					gap: 4px;
					margin: 4px 0 0;
				}
				.wairm-star-rating input { display: none; }
				.wairm-star-rating label {
					cursor: pointer;
					font-size: 32px;
					color: #d4d8de;
					transition: color 0.15s, transform 0.15s;
					line-height: 1;
					user-select: none;
				}
				.wairm-star-rating label:hover,
				.wairm-star-rating label:hover ~ label,
				.wairm-star-rating input:checked ~ label {
					color: #f5a623;
				}
				.wairm-star-rating label:hover { transform: scale(1.15); }
				.wairm-star-rating-label {
					font-size: 13px;
					font-weight: 600;
					color: #555;
					margin-bottom: 4px;
				}

				/* Form fields */
				.wairm-field { margin-top: 18px; }
				.wairm-field label {
					display: block;
					font-size: 13px;
					font-weight: 600;
					color: #555;
					margin-bottom: 6px;
				}
				.wairm-field textarea {
					width: 100%;
					padding: 12px 14px;
					border: 1px solid #d4d8de;
					border-radius: 8px;
					font-family: inherit;
					font-size: 14px;
					line-height: 1.6;
					color: #1a1a1a;
					resize: vertical;
					transition: border-color 0.2s, box-shadow 0.2s;
					background: #fff;
				}
				.wairm-field textarea:focus {
					outline: none;
					border-color: #3b82f6;
					box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
				}
				.wairm-field textarea::placeholder { color: #aaa; }

				/* Hidden select fallback (visually hidden but form-accessible) */
				.wairm-rating-select { display: none; }

				/* Submit */
				.wairm-submit-wrap { margin-top: 8px; }
				.wairm-submit-btn {
					display: inline-flex;
					align-items: center;
					gap: 8px;
					background: #3b82f6;
					color: #fff;
					border: none;
					padding: 14px 32px;
					border-radius: 8px;
					font-size: 15px;
					font-weight: 600;
					cursor: pointer;
					transition: background 0.2s, transform 0.1s, box-shadow 0.2s;
					letter-spacing: 0.01em;
				}
				.wairm-submit-btn:hover {
					background: #2563eb;
					box-shadow: 0 4px 12px rgba(37,99,235,0.3);
				}
				.wairm-submit-btn:active { transform: scale(0.98); }

				/* Footer */
				.wairm-form-footer {
					margin-top: 28px;
					padding-top: 20px;
					border-top: 1px solid #eee;
					text-align: center;
					font-size: 13px;
					color: #999;
				}
			</style>
		</head>
		<body>
			<div class="wairm-review-page">
				<?php echo $form_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped in shortcode. ?>
				<div class="wairm-form-footer">
					<?php echo esc_html( get_bloginfo( 'name' ) ); ?>
				</div>
			</div>
			<script>
			/* Star rating: sync clicks to hidden select */
			document.addEventListener('DOMContentLoaded', function() {
				document.querySelectorAll('.wairm-star-rating input').forEach(function(radio) {
					radio.addEventListener('change', function() {
						var pid = this.name.replace('star_rating[', '').replace(']', '');
						var sel = document.querySelector('select[name="rating[' + pid + ']"]');
						if (sel) sel.value = this.value;
					});
				});
			});
			</script>
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

	private const MAX_EMAIL_ATTEMPTS = 3;

	private static function send_email( object $email ): void {
		global $wpdb;

		// Skip emails that have exceeded retry limit.
		if ( (int) $email->attempts >= self::MAX_EMAIL_ATTEMPTS ) {
			$wpdb->update(
				$wpdb->prefix . 'wairm_email_queue',
				[ 'status' => 'failed', 'last_error' => 'Max retry attempts exceeded' ],
				[ 'id' => $email->id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
			return;
		}

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
				<h1><?php echo esc_html( self::resolve_subject_placeholders( $email ) ); ?></h1>

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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public token-based URL, no nonce available.
		$token = sanitize_text_field( wp_unslash( $_GET['wairm_token'] ?? '' ) );
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
			<p class="wairm-store-name"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
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
					$image_url = wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' );
					$pid       = absint( $product_id );
				?>
				<div class="wairm-product-card">
					<div class="wairm-product-header">
						<?php if ( $image_url ) : ?>
							<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>" class="wairm-product-image">
						<?php else : ?>
							<div class="wairm-product-image-placeholder">&#9733;</div>
						<?php endif; ?>
						<h3 class="wairm-product-name"><?php echo esc_html( $product->get_name() ); ?></h3>
					</div>

					<input type="hidden" name="product_ids[]" value="<?php echo $pid; ?>">

					<div class="wairm-star-rating-label"><?php esc_html_e( 'Your rating', 'woo-ai-review-manager' ); ?></div>
					<div class="wairm-star-rating">
						<?php for ( $i = 5; $i >= 1; $i-- ) : ?>
							<input type="radio" id="star-<?php echo $pid; ?>-<?php echo $i; ?>" name="star_rating[<?php echo $pid; ?>]" value="<?php echo $i; ?>">
							<label for="star-<?php echo $pid; ?>-<?php echo $i; ?>" title="<?php echo esc_attr( $i ); ?> <?php esc_attr_e( 'stars', 'woo-ai-review-manager' ); ?>">&#9733;</label>
						<?php endfor; ?>
					</div>

					<select name="rating[<?php echo $pid; ?>]" class="wairm-rating-select" required>
						<option value=""><?php esc_html_e( 'Select a rating', 'woo-ai-review-manager' ); ?></option>
						<?php for ( $i = 5; $i >= 1; $i-- ) : ?>
						<option value="<?php echo $i; ?>"><?php echo $i; ?></option>
						<?php endfor; ?>
					</select>

					<div class="wairm-field">
						<label for="review-<?php echo $pid; ?>"><?php esc_html_e( 'Your review', 'woo-ai-review-manager' ); ?></label>
						<textarea id="review-<?php echo $pid; ?>" name="review[<?php echo $pid; ?>]" rows="4" placeholder="<?php esc_attr_e( 'What did you like or dislike about this product?', 'woo-ai-review-manager' ); ?>" required></textarea>
					</div>
				</div>
				<?php endforeach; ?>

				<div class="wairm-submit-wrap">
					<button type="submit" class="wairm-submit-btn">
						<?php esc_html_e( 'Submit Reviews', 'woo-ai-review-manager' ); ?>
						<span>&#8594;</span>
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
			];

			$new_comment_id = wp_insert_comment( $comment_data );

			if ( $new_comment_id ) {
				update_comment_meta( $new_comment_id, 'rating', $rating );
			}
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
