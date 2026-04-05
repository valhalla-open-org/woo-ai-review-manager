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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_submitted = ! empty( $_GET['wairm_submitted'] );

		if ( $is_submitted ) {
			$this->render_thank_you_page();
			exit;
		}

		$form_html = $this->review_form_shortcode();

		// Render a minimal standalone page with the review form.
		$site_name = esc_html( get_bloginfo( 'name' ) );
		$charset   = esc_attr( get_bloginfo( 'charset' ) );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php echo esc_attr( $charset ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html__( 'Leave a Review', 'ai-review-manager-for-woocommerce' ) . ' — ' . esc_html( $site_name ); ?></title>
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

		// Allow invitations in 'pending' (initial) or 'sent'/'clicked' (reminder) statuses.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pending = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT eq.*, ri.customer_email, ri.customer_name, ri.order_id, ri.token, ri.product_ids
				 FROM {$wpdb->prefix}wairm_email_queue eq
				 JOIN {$wpdb->prefix}wairm_review_invitations ri ON ri.id = eq.invitation_id
				 WHERE eq.status = 'queued'
				   AND eq.scheduled_at <= %s
				   AND ri.status IN ('pending', 'sent', 'clicked')
				   AND ri.status != 'reviewed'
				 LIMIT %d",
				$now,
				self::QUEUE_BATCH_SIZE
			)
		);

		foreach ( $pending as $email ) {
			self::send_email( $email );
		}
	}

	private const MAX_EMAIL_ATTEMPTS = 3;

	/** @var int Maximum emails to process per cron run. */
	private const QUEUE_BATCH_SIZE = 20;

	private static function send_email( object $email ): void {
		global $wpdb;

		// Skip emails that have exceeded retry limit.
		if ( (int) $email->attempts >= self::MAX_EMAIL_ATTEMPTS ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'wairm_email_queue',
				[ 'status' => 'sent', 'sent_at' => current_time( 'mysql', true ) ],
				[ 'id' => $email->id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);

			// Update invitation status to 'sent' only if still 'pending'.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[WAIRM] Email send failed for invitation %d to %s.', $email->invitation_id, $email->customer_email ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		$is_reminder = isset( $email->email_type ) && 'reminder' === $email->email_type;

		if ( $is_reminder ) {
			$subject = get_option( 'wairm_reminder_subject', '' );
			if ( empty( $subject ) ) {
				$subject = __( 'We\'d still love to hear from you!', 'ai-review-manager-for-woocommerce' );
			}
		} else {
			$subject = get_option( 'wairm_email_subject', __( 'How was your recent purchase?', 'ai-review-manager-for-woocommerce' ) );
		}

		return str_replace(
			[ '{customer_name}', '{store_name}' ],
			[ sanitize_text_field( $email->customer_name ), sanitize_text_field( get_bloginfo( 'name' ) ) ],
			$subject
		);
	}

	private static function build_email_body( object $email ): string {
		$product_ids = json_decode( $email->product_ids, true );
		if ( ! is_array( $product_ids ) || empty( $product_ids ) ) {
			return '';
		}
		$product_ids = array_map( 'absint', $product_ids );
		$product_ids = array_filter( $product_ids );
		$products    = [];

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
		$is_reminder = isset( $email->email_type ) && 'reminder' === $email->email_type;

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

				<?php
				if ( $is_reminder ) {
					$greeting = get_option( 'wairm_reminder_greeting', '' );
					if ( empty( $greeting ) ) {
						$greeting = __( 'Hi {customer_name}, just a friendly reminder!', 'ai-review-manager-for-woocommerce' );
					}
				} else {
					$greeting = get_option( 'wairm_email_greeting', '' );
					if ( empty( $greeting ) ) {
						$greeting = __( 'Hi {customer_name}, thank you for your recent order!', 'ai-review-manager-for-woocommerce' );
					}
				}
				$greeting = str_replace(
					[ '{customer_name}', '{store_name}' ],
					[ sanitize_text_field( $email->customer_name ), sanitize_text_field( get_bloginfo( 'name' ) ) ],
					$greeting
				);
				?>
				<p><?php echo esc_html( $greeting ); ?></p>

				<?php
				if ( $is_reminder ) {
					$body_text = get_option( 'wairm_reminder_body_text', '' );
					if ( empty( $body_text ) ) {
						$body_text = __( 'We noticed you haven\'t had a chance to review your recent purchase yet. Your feedback helps other shoppers and helps us improve:', 'ai-review-manager-for-woocommerce' );
					}
				} else {
					$body_text = get_option( 'wairm_email_body_text', '' );
					if ( empty( $body_text ) ) {
						$body_text = __( 'We would love to hear what you think about the products you purchased:', 'ai-review-manager-for-woocommerce' );
					}
				}
				?>
				<p><?php echo esc_html( $body_text ); ?></p>

				<?php foreach ( $products as $product ) : ?>
				<div class="product">
					<?php if ( $product['image'] ) : ?>
						<img src="<?php echo esc_url( $product['image'] ); ?>" alt="<?php echo esc_attr( $product['name'] ); ?>" class="product-image">
					<?php endif; ?>
					<div class="product-info">
						<div class="product-name"><?php echo esc_html( $product['name'] ); ?></div>
						<a href="<?php echo esc_url( $product['url'] ); ?>" target="_blank"><?php esc_html_e( 'View product', 'ai-review-manager-for-woocommerce' ); ?></a>
					</div>
				</div>
				<?php endforeach; ?>

				<p><?php esc_html_e( 'Please take a moment to share your experience by clicking the button below:', 'ai-review-manager-for-woocommerce' ); ?></p>

				<?php
				if ( $is_reminder ) {
					$button_text = get_option( 'wairm_reminder_button_text', '' );
					if ( empty( $button_text ) ) {
						$button_text = __( 'Write a Review', 'ai-review-manager-for-woocommerce' );
					}
				} else {
					$button_text = get_option( 'wairm_email_button_text', '' );
					if ( empty( $button_text ) ) {
						$button_text = __( 'Leave a Review', 'ai-review-manager-for-woocommerce' );
					}
				}
				?>
				<p>
					<a href="<?php echo esc_url( $review_link ); ?>" class="cta-button">
						<?php echo esc_html( $button_text ); ?>
					</a>
				</p>

				<p style="font-size: 14px; color: #666;">
					<?php
					printf(
						/* translators: %d: number of days */
						esc_html__( 'This review link will expire in %d days.', 'ai-review-manager-for-woocommerce' ),
						absint( $expiry_days )
					);
					?>
				</p>

				<div class="footer">
					<p><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
					<p style="font-size: 13px;">
						<?php esc_html_e( 'You are receiving this email because you recently made a purchase. If you do not wish to receive review invitations, please let us know.', 'ai-review-manager-for-woocommerce' ); ?>
					</p>
				</div>
			</div>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Build a test/preview email body using the same HTML template as real invitations.
	 *
	 * Uses sample WooCommerce products if available, otherwise placeholder data.
	 */
	public static function build_test_email_body(): string {
		// Find up to 2 published products for realistic preview.
		$sample_ids = wc_get_products( [
			'status' => 'publish',
			'limit'  => 2,
			'return' => 'ids',
		] );

		$fake_email = (object) [
			'customer_name' => __( 'Test Customer', 'ai-review-manager-for-woocommerce' ),
			'product_ids'   => wp_json_encode( ! empty( $sample_ids ) ? $sample_ids : [ 0 ] ),
			'token'         => 'test-preview',
		];

		return self::build_email_body( $fake_email );
	}

	/**
	 * Shortcode to render a review form for token-based access.
	 */
	public function review_form_shortcode(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public token-based URL, no nonce available.
		$token = sanitize_text_field( wp_unslash( $_GET['wairm_token'] ?? '' ) );
		if ( empty( $token ) ) {
			return '<p>' . esc_html__( 'Invalid or missing review invitation.', 'ai-review-manager-for-woocommerce' ) . '</p>';
		}

		global $wpdb;

		// Accept invitations that are sent or clicked (allow page refreshes).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$invitation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wairm_review_invitations
				 WHERE token = %s AND status IN ('sent', 'clicked') AND expires_at > %s",
				$token,
				current_time( 'mysql', true )
			)
		);

		if ( ! $invitation ) {
			return '<p>' . esc_html__( 'This review invitation is invalid or has expired.', 'ai-review-manager-for-woocommerce' ) . '</p>';
		}

		// Mark as clicked if still 'sent'.
		if ( 'sent' === $invitation->status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'wairm_review_invitations',
				[ 'status' => 'clicked', 'clicked_at' => current_time( 'mysql', true ) ],
				[ 'id' => $invitation->id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
		}

		$product_ids = json_decode( $invitation->product_ids, true );
		if ( ! is_array( $product_ids ) || empty( $product_ids ) ) {
			return '<p>' . esc_html__( 'Invalid invitation data.', 'ai-review-manager-for-woocommerce' ) . '</p>';
		}
		$product_ids = array_map( 'absint', $product_ids );
		$product_ids = array_filter( $product_ids );

		ob_start();
		?>
		<div class="wairm-review-form">
			<p class="wairm-store-name"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
			<h2><?php esc_html_e( 'Leave a Review', 'ai-review-manager-for-woocommerce' ); ?></h2>
			<p><?php esc_html_e( 'Please share your thoughts on the products you purchased:', 'ai-review-manager-for-woocommerce' ); ?></p>

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

					<input type="hidden" name="product_ids[]" value="<?php echo esc_attr( $pid ); ?>">

					<div class="wairm-star-rating-label"><?php esc_html_e( 'Your rating', 'ai-review-manager-for-woocommerce' ); ?></div>
					<div class="wairm-star-rating">
						<?php for ( $i = 5; $i >= 1; $i-- ) : ?>
							<input type="radio" id="star-<?php echo esc_attr( $pid ); ?>-<?php echo esc_attr( $i ); ?>" name="star_rating[<?php echo esc_attr( $pid ); ?>]" value="<?php echo esc_attr( $i ); ?>">
							<label for="star-<?php echo esc_attr( $pid ); ?>-<?php echo esc_attr( $i ); ?>" title="<?php echo esc_attr( $i ); ?> <?php esc_attr_e( 'stars', 'ai-review-manager-for-woocommerce' ); ?>">&#9733;</label>
						<?php endfor; ?>
					</div>

					<select name="rating[<?php echo esc_attr( $pid ); ?>]" class="wairm-rating-select" required>
						<option value=""><?php esc_html_e( 'Select a rating', 'ai-review-manager-for-woocommerce' ); ?></option>
						<?php for ( $i = 5; $i >= 1; $i-- ) : ?>
						<option value="<?php echo absint( $i ); ?>"><?php echo absint( $i ); ?></option>
						<?php endfor; ?>
					</select>

					<div class="wairm-field">
						<label for="review-<?php echo esc_attr( $pid ); ?>"><?php esc_html_e( 'Your review', 'ai-review-manager-for-woocommerce' ); ?></label>
						<textarea id="review-<?php echo esc_attr( $pid ); ?>" name="review[<?php echo esc_attr( $pid ); ?>]" rows="4" placeholder="<?php esc_attr_e( 'What did you like or dislike about this product?', 'ai-review-manager-for-woocommerce' ); ?>" required></textarea>
					</div>
				</div>
				<?php endforeach; ?>

				<div class="wairm-submit-wrap">
					<button type="submit" class="wairm-submit-btn">
						<?php esc_html_e( 'Submit Reviews', 'ai-review-manager-for-woocommerce' ); ?>
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
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'wairm_review' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'ai-review-manager-for-woocommerce' ) );
		}

		global $wpdb;

		$invitation_id = absint( $_POST['invitation_id'] ?? 0 );
		$token         = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );

		// Verify invitation exists, matches token, and hasn't already been reviewed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$invitation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wairm_review_invitations
				 WHERE id = %d AND token = %s AND status IN ('sent', 'clicked')",
				$invitation_id,
				$token
			)
		);

		if ( ! $invitation ) {
			wp_die( esc_html__( 'Invalid invitation or reviews already submitted.', 'ai-review-manager-for-woocommerce' ) );
		}

		// Process each product review.
		$product_ids = array_map( 'absint', (array) wp_unslash( $_POST['product_ids'] ?? [] ) );
		$ratings     = array_map( 'absint', (array) wp_unslash( $_POST['rating'] ?? [] ) );
		$reviews     = array_map( 'sanitize_textarea_field', (array) wp_unslash( $_POST['review'] ?? [] ) );


		foreach ( (array) $product_ids as $product_id ) {
			$product_id = absint( $product_id );
			$rating     = absint( $ratings[ $product_id ] ?? 0 );
			$content    = sanitize_textarea_field( $reviews[ $product_id ] ?? '' );

			if ( $rating < 1 || $rating > 5 || empty( $content ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf(
					'[WAIRM] Review skipped for product %d (invitation %d): invalid rating (%d) or empty content.',
					$product_id,
					$invitation_id,
					$rating
				) );
				continue;
			}

			// Check for duplicate review from this customer for this product.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf(
					'[WAIRM] Review skipped for product %d (invitation %d): duplicate review exists (comment %s) for %s.',
					$product_id,
					$invitation_id,
					$existing,
					$invitation->customer_email
				) );
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
			} else {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf(
					'[WAIRM] Review insert failed for product %d (invitation %d): wp_insert_comment returned false.',
					$product_id,
					$invitation_id
				) );
			}
		}

		// Mark invitation as reviewed to prevent resubmission.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'wairm_review_invitations',
			[ 'status' => 'reviewed' ],
			[ 'id' => $invitation_id ],
			[ '%s' ],
			[ '%d' ]
		);

		// Cancel any pending reminder emails for this invitation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'wairm_email_queue',
			[ 'status' => 'cancelled' ],
			[ 'invitation_id' => $invitation_id, 'status' => 'queued' ],
			[ '%s' ],
			[ '%d', '%s' ]
		);

		$redirect_url = add_query_arg(
			[
				'wairm_token'     => $token,
				'action'          => 'review',
				'wairm_submitted' => '1',
			],
			get_home_url()
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Render a standalone thank-you page after review submission.
	 */
	private function render_thank_you_page(): void {
		$site_name = esc_html( get_bloginfo( 'name' ) );
		$charset   = esc_attr( get_bloginfo( 'charset' ) );
		$shop_url  = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : get_home_url();
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php echo esc_attr( $charset ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html__( 'Thank You', 'ai-review-manager-for-woocommerce' ) . ' — ' . esc_html( $site_name ); ?></title>
			<?php wp_head(); ?>
			<style>
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
					display: flex;
					align-items: center;
					justify-content: center;
				}
				.wairm-thankyou {
					max-width: 520px;
					width: 100%;
					background: #fff;
					padding: 48px 40px;
					border-radius: 16px;
					box-shadow: 0 4px 24px rgba(0,0,0,0.08);
					text-align: center;
					animation: wairm-ty-fadein 0.6s ease;
				}
				@keyframes wairm-ty-fadein {
					from { opacity: 0; transform: translateY(16px); }
					to   { opacity: 1; transform: translateY(0); }
				}
				@media (max-width: 600px) {
					body { padding: 16px; }
					.wairm-thankyou { padding: 36px 24px; }
				}

				/* Checkmark animation */
				.wairm-ty-icon {
					width: 80px;
					height: 80px;
					margin: 0 auto 24px;
					background: linear-gradient(135deg, #34d399 0%, #10b981 100%);
					border-radius: 50%;
					display: flex;
					align-items: center;
					justify-content: center;
					animation: wairm-ty-pop 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) 0.2s both;
				}
				@keyframes wairm-ty-pop {
					from { transform: scale(0); }
					to   { transform: scale(1); }
				}
				.wairm-ty-icon svg {
					width: 40px;
					height: 40px;
					stroke: #fff;
					stroke-width: 3;
					fill: none;
					stroke-linecap: round;
					stroke-linejoin: round;
				}
				.wairm-ty-icon svg .checkmark-path {
					stroke-dasharray: 50;
					stroke-dashoffset: 50;
					animation: wairm-ty-draw 0.4s ease 0.6s forwards;
				}
				@keyframes wairm-ty-draw {
					to { stroke-dashoffset: 0; }
				}

				/* Typography */
				.wairm-ty-heading {
					font-size: 24px;
					font-weight: 700;
					color: #1a1a1a;
					margin: 0 0 12px;
				}
				.wairm-ty-message {
					font-size: 16px;
					color: #555;
					margin: 0 0 8px;
					line-height: 1.7;
				}
				.wairm-ty-submessage {
					font-size: 14px;
					color: #888;
					margin: 0 0 32px;
				}

				/* Stars decoration */
				.wairm-ty-stars {
					display: flex;
					justify-content: center;
					gap: 6px;
					margin-bottom: 28px;
				}
				.wairm-ty-stars span {
					font-size: 28px;
					color: #f5a623;
					animation: wairm-ty-star 0.3s ease both;
				}
				.wairm-ty-stars span:nth-child(1) { animation-delay: 0.8s; }
				.wairm-ty-stars span:nth-child(2) { animation-delay: 0.9s; }
				.wairm-ty-stars span:nth-child(3) { animation-delay: 1.0s; }
				.wairm-ty-stars span:nth-child(4) { animation-delay: 1.1s; }
				.wairm-ty-stars span:nth-child(5) { animation-delay: 1.2s; }
				@keyframes wairm-ty-star {
					from { opacity: 0; transform: scale(0) rotate(-30deg); }
					to   { opacity: 1; transform: scale(1) rotate(0); }
				}

				/* CTA button */
				.wairm-ty-btn {
					display: inline-block;
					background: #3b82f6;
					color: #fff;
					text-decoration: none;
					padding: 12px 28px;
					border-radius: 8px;
					font-size: 15px;
					font-weight: 600;
					transition: background 0.2s, box-shadow 0.2s, transform 0.1s;
				}
				.wairm-ty-btn:hover {
					background: #2563eb;
					box-shadow: 0 4px 12px rgba(37,99,235,0.3);
					color: #fff;
				}
				.wairm-ty-btn:active { transform: scale(0.98); }

				/* Footer */
				.wairm-ty-footer {
					margin-top: 32px;
					padding-top: 20px;
					border-top: 1px solid #eee;
					font-size: 13px;
					color: #aaa;
				}
			</style>
		</head>
		<body>
			<div class="wairm-thankyou">
				<div class="wairm-ty-icon">
					<svg viewBox="0 0 40 40">
						<polyline class="checkmark-path" points="12,21 18,27 28,14" />
					</svg>
				</div>

				<h1 class="wairm-ty-heading"><?php esc_html_e( 'Thank you!', 'ai-review-manager-for-woocommerce' ); ?></h1>
				<p class="wairm-ty-message"><?php esc_html_e( 'Your reviews have been submitted successfully.', 'ai-review-manager-for-woocommerce' ); ?></p>
				<p class="wairm-ty-submessage"><?php esc_html_e( 'Your feedback helps us improve and helps other customers make informed decisions.', 'ai-review-manager-for-woocommerce' ); ?></p>

				<div class="wairm-ty-stars">
					<span>&#9733;</span>
					<span>&#9733;</span>
					<span>&#9733;</span>
					<span>&#9733;</span>
					<span>&#9733;</span>
				</div>

				<a href="<?php echo esc_url( $shop_url ); ?>" class="wairm-ty-btn">
					<?php esc_html_e( 'Continue Shopping', 'ai-review-manager-for-woocommerce' ); ?>
				</a>

				<div class="wairm-ty-footer">
					<?php echo esc_html( get_bloginfo( 'name' ) ); ?>
				</div>
			</div>
			<?php wp_footer(); ?>
		</body>
		</html>
		<?php
	}
}
