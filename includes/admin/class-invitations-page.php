<?php
/**
 * Admin page for tracking and managing review invitations.
 *
 * Shows invitation status, allows manual sending ahead of schedule,
 * and resending failed or already-sent invitations.
 *
 * @package WooAIReviewManager
 */

declare(strict_types=1);

namespace WooAIReviewManager\Admin;

defined( 'ABSPATH' ) || exit;

final class Invitations_Page {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_submenu_page' ] );
		add_action( 'wp_ajax_wairm_send_invitation', [ $this, 'ajax_send_invitation' ] );
		add_action( 'wp_ajax_wairm_resend_invitation', [ $this, 'ajax_resend_invitation' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function add_submenu_page(): void {
		add_submenu_page(
			'wairm-dashboard',
			__( 'Invitations', 'woo-ai-review-manager' ),
			__( 'Invitations', 'woo-ai-review-manager' ),
			'manage_woocommerce',
			'wairm-invitations',
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'ai-reviews_page_wairm-invitations' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wairm-admin',
			WAIRM_PLUGIN_URL . 'assets/css/admin.css',
			[],
			WAIRM_VERSION
		);

		wp_enqueue_script(
			'wairm-invitations',
			WAIRM_PLUGIN_URL . 'assets/js/invitations.js',
			[],
			WAIRM_VERSION,
			true
		);

		wp_localize_script(
			'wairm-invitations',
			'wairmInvitations',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wairm_invitations' ),
				'i18n'     => [
					'confirm_send'   => __( 'Send this invitation email now?', 'woo-ai-review-manager' ),
					'confirm_resend' => __( 'Resend this invitation email?', 'woo-ai-review-manager' ),
					'sending'        => __( 'Sending...', 'woo-ai-review-manager' ),
					'sent'           => __( 'Email sent successfully.', 'woo-ai-review-manager' ),
					'error'          => __( 'Something went wrong. Please try again.', 'woo-ai-review-manager' ),
					'send_now'       => __( 'Send Now', 'woo-ai-review-manager' ),
					'resend'         => __( 'Resend', 'woo-ai-review-manager' ),
					'status_pending'  => __( 'Pending', 'woo-ai-review-manager' ),
					'status_sent'     => __( 'Sent', 'woo-ai-review-manager' ),
					'status_clicked'  => __( 'Clicked', 'woo-ai-review-manager' ),
					'status_reviewed' => __( 'Reviewed', 'woo-ai-review-manager' ),
					'status_expired'  => __( 'Expired', 'woo-ai-review-manager' ),
				],
			]
		);
	}

	/**
	 * AJAX: Send a pending invitation immediately (ahead of schedule).
	 */
	public function ajax_send_invitation(): void {
		check_ajax_referer( 'wairm_invitations', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-ai-review-manager' ) ], 403 );
		}

		global $wpdb;

		$invitation_id = absint( $_POST['invitation_id'] ?? 0 );
		if ( ! $invitation_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'woo-ai-review-manager' ) ] );
		}

		$invitation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wairm_review_invitations WHERE id = %d",
				$invitation_id
			)
		);

		if ( ! $invitation ) {
			wp_send_json_error( [ 'message' => __( 'Invitation not found.', 'woo-ai-review-manager' ) ] );
		}

		if ( ! in_array( $invitation->status, [ 'pending' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'This invitation has already been sent.', 'woo-ai-review-manager' ) ] );
		}

		// Find the queued initial email and send it now by setting scheduled_at to now.
		$email = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wairm_email_queue
				 WHERE invitation_id = %d AND email_type = 'initial' AND status = 'queued'
				 LIMIT 1",
				$invitation_id
			)
		);

		if ( ! $email ) {
			wp_send_json_error( [ 'message' => __( 'No queued email found for this invitation.', 'woo-ai-review-manager' ) ] );
		}

		// Update scheduled_at to now so it's immediately eligible.
		$wpdb->update(
			$wpdb->prefix . 'wairm_email_queue',
			[ 'scheduled_at' => current_time( 'mysql', true ) ],
			[ 'id' => $email->id ],
			[ '%s' ],
			[ '%d' ]
		);

		// Process it immediately.
		\WooAIReviewManager\Email_Sender::process_queue();

		// Check if it was sent.
		$updated_email = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}wairm_email_queue WHERE id = %d",
				$email->id
			)
		);

		if ( 'sent' === $updated_email->status ) {
			$new_status = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT status FROM {$wpdb->prefix}wairm_review_invitations WHERE id = %d",
					$invitation_id
				)
			);
			wp_send_json_success( [ 'status' => $new_status ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Email sending failed. Check your mail configuration.', 'woo-ai-review-manager' ) ] );
		}
	}

	/**
	 * AJAX: Resend an invitation that was already sent or failed.
	 */
	public function ajax_resend_invitation(): void {
		check_ajax_referer( 'wairm_invitations', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-ai-review-manager' ) ], 403 );
		}

		global $wpdb;

		$invitation_id = absint( $_POST['invitation_id'] ?? 0 );
		if ( ! $invitation_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'woo-ai-review-manager' ) ] );
		}

		$invitation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wairm_review_invitations WHERE id = %d",
				$invitation_id
			)
		);

		if ( ! $invitation ) {
			wp_send_json_error( [ 'message' => __( 'Invitation not found.', 'woo-ai-review-manager' ) ] );
		}

		// Allow resending for sent, clicked, or expired invitations, but not reviewed ones.
		if ( 'reviewed' === $invitation->status ) {
			wp_send_json_error( [ 'message' => __( 'This customer has already submitted a review.', 'woo-ai-review-manager' ) ] );
		}

		// Check for expired invitation — extend expiry.
		if ( 'expired' === $invitation->status ) {
			$expiry_days = absint( get_option( 'wairm_invitation_expiry_days', 30 ) );
			$wpdb->update(
				$wpdb->prefix . 'wairm_review_invitations',
				[
					'status'     => 'pending',
					'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( "+{$expiry_days} days" ) ),
				],
				[ 'id' => $invitation_id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
		}

		// Queue a new email.
		$wpdb->insert(
			$wpdb->prefix . 'wairm_email_queue',
			[
				'invitation_id' => $invitation_id,
				'email_type'    => 'initial',
				'scheduled_at'  => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s' ]
		);

		$new_email_id = $wpdb->insert_id;
		if ( ! $new_email_id ) {
			wp_send_json_error( [ 'message' => __( 'Failed to queue email.', 'woo-ai-review-manager' ) ] );
		}

		// Process immediately.
		\WooAIReviewManager\Email_Sender::process_queue();

		// Check result.
		$updated_email = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}wairm_email_queue WHERE id = %d",
				$new_email_id
			)
		);

		if ( 'sent' === $updated_email->status ) {
			$new_status = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT status FROM {$wpdb->prefix}wairm_review_invitations WHERE id = %d",
					$invitation_id
				)
			);
			wp_send_json_success( [ 'status' => $new_status ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Email sending failed. Check your mail configuration.', 'woo-ai-review-manager' ) ] );
		}
	}

	public function render_page(): void {
		global $wpdb;

		$table  = $wpdb->prefix . 'wairm_review_invitations';
		$filter = sanitize_key( $_GET['status'] ?? 'all' );

		// Count by status.
		$counts = $wpdb->get_results(
			"SELECT status, COUNT(*) as cnt FROM {$table} GROUP BY status",
			OBJECT_K
		);

		$count_pending  = (int) ( $counts['pending']->cnt ?? 0 );
		$count_sent     = (int) ( $counts['sent']->cnt ?? 0 );
		$count_clicked  = (int) ( $counts['clicked']->cnt ?? 0 );
		$count_reviewed = (int) ( $counts['reviewed']->cnt ?? 0 );
		$count_expired  = (int) ( $counts['expired']->cnt ?? 0 );
		$count_all      = $count_pending + $count_sent + $count_clicked + $count_reviewed + $count_expired;

		// Build WHERE.
		$where = '1=1';
		$valid_statuses = [ 'pending', 'sent', 'clicked', 'reviewed', 'expired' ];
		if ( in_array( $filter, $valid_statuses, true ) ) {
			$where = $wpdb->prepare( 'i.status = %s', $filter );
		}

		$page     = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = 20;
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where built via prepare() or literal.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} i WHERE {$where}" );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where built via prepare() or literal.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.*,
				        (SELECT COUNT(*) FROM {$wpdb->prefix}wairm_email_queue eq WHERE eq.invitation_id = i.id AND eq.status = 'sent') AS emails_sent,
				        (SELECT COUNT(*) FROM {$wpdb->prefix}wairm_email_queue eq WHERE eq.invitation_id = i.id AND eq.status = 'failed') AS emails_failed
				 FROM {$table} i
				 WHERE {$where}
				 ORDER BY i.created_at DESC
				 LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		$total_pages = (int) ceil( $total / $per_page );
		$base_url    = admin_url( 'admin.php?page=wairm-invitations' );
		?>
		<div class="wrap wairm-invitations">
			<h1><?php esc_html_e( 'Review Invitations', 'woo-ai-review-manager' ); ?></h1>
			<hr class="wp-header-end">

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'all', $base_url ) ); ?>" class="<?php echo 'all' === $filter ? 'current' : ''; ?>">
					<?php esc_html_e( 'All', 'woo-ai-review-manager' ); ?>
					<span class="count">(<?php echo esc_html( (string) $count_all ); ?>)</span>
				</a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'pending', $base_url ) ); ?>" class="<?php echo 'pending' === $filter ? 'current' : ''; ?>">
					<?php esc_html_e( 'Pending', 'woo-ai-review-manager' ); ?>
					<span class="count">(<?php echo esc_html( (string) $count_pending ); ?>)</span>
				</a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'sent', $base_url ) ); ?>" class="<?php echo 'sent' === $filter ? 'current' : ''; ?>">
					<?php esc_html_e( 'Sent', 'woo-ai-review-manager' ); ?>
					<span class="count">(<?php echo esc_html( (string) $count_sent ); ?>)</span>
				</a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'clicked', $base_url ) ); ?>" class="<?php echo 'clicked' === $filter ? 'current' : ''; ?>">
					<?php esc_html_e( 'Clicked', 'woo-ai-review-manager' ); ?>
					<span class="count">(<?php echo esc_html( (string) $count_clicked ); ?>)</span>
				</a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'reviewed', $base_url ) ); ?>" class="<?php echo 'reviewed' === $filter ? 'current' : ''; ?>">
					<?php esc_html_e( 'Reviewed', 'woo-ai-review-manager' ); ?>
					<span class="count">(<?php echo esc_html( (string) $count_reviewed ); ?>)</span>
				</a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'expired', $base_url ) ); ?>" class="<?php echo 'expired' === $filter ? 'current' : ''; ?>">
					<?php esc_html_e( 'Expired', 'woo-ai-review-manager' ); ?>
					<span class="count">(<?php echo esc_html( (string) $count_expired ); ?>)</span>
				</a></li>
			</ul>

			<br class="clear">

			<?php if ( empty( $rows ) ) : ?>
				<div class="wairm-empty-state">
					<p style="font-size: 16px; color: #666;">
						<?php esc_html_e( 'No invitations found.', 'woo-ai-review-manager' ); ?>
					</p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped" style="margin-top: 16px;">
					<thead>
						<tr>
							<th scope="col" style="width: 60px;"><?php esc_html_e( 'ID', 'woo-ai-review-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Customer', 'woo-ai-review-manager' ); ?></th>
							<th scope="col" style="width: 100px;"><?php esc_html_e( 'Order', 'woo-ai-review-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Products', 'woo-ai-review-manager' ); ?></th>
							<th scope="col" style="width: 110px;"><?php esc_html_e( 'Status', 'woo-ai-review-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Emails', 'woo-ai-review-manager' ); ?></th>
							<th scope="col" style="width: 140px;"><?php esc_html_e( 'Created', 'woo-ai-review-manager' ); ?></th>
							<th scope="col" style="width: 140px;"><?php esc_html_e( 'Expires', 'woo-ai-review-manager' ); ?></th>
							<th scope="col" style="width: 140px;"><?php esc_html_e( 'Actions', 'woo-ai-review-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
						<tr data-id="<?php echo absint( $row->id ); ?>">
							<td><?php echo absint( $row->id ); ?></td>
							<td>
								<strong><?php echo esc_html( $row->customer_name ); ?></strong><br>
								<span style="color: #888; font-size: 12px;"><?php echo esc_html( $row->customer_email ); ?></span>
							</td>
							<td>
								<?php
								$order = wc_get_order( (int) $row->order_id );
								if ( $order ) :
								?>
									<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">
										#<?php echo absint( $row->order_id ); ?>
									</a>
								<?php else : ?>
									#<?php echo absint( $row->order_id ); ?>
								<?php endif; ?>
							</td>
							<td>
								<?php
								$product_ids = json_decode( $row->product_ids, true );
								if ( is_array( $product_ids ) ) {
									$names = [];
									foreach ( $product_ids as $pid ) {
										$product = wc_get_product( $pid );
										$names[] = $product ? $product->get_name() : '#' . $pid;
									}
									echo esc_html( implode( ', ', $names ) );
								}
								?>
							</td>
							<td>
								<span class="wairm-invitation-status status-<?php echo esc_attr( $row->status ); ?>">
									<?php
									$status_labels = [
										'pending'  => __( 'Pending', 'woo-ai-review-manager' ),
										'sent'     => __( 'Sent', 'woo-ai-review-manager' ),
										'clicked'  => __( 'Clicked', 'woo-ai-review-manager' ),
										'reviewed' => __( 'Reviewed', 'woo-ai-review-manager' ),
										'expired'  => __( 'Expired', 'woo-ai-review-manager' ),
									];
									echo esc_html( $status_labels[ $row->status ] ?? $row->status );
									?>
								</span>
							</td>
							<td>
								<?php
								$sent_count   = (int) $row->emails_sent;
								$failed_count = (int) $row->emails_failed;
								if ( $sent_count > 0 ) {
									printf(
										/* translators: %d: number of emails sent */
										esc_html( _n( '%d sent', '%d sent', $sent_count, 'woo-ai-review-manager' ) ),
										$sent_count
									);
								}
								if ( $failed_count > 0 ) {
									if ( $sent_count > 0 ) {
										echo ', ';
									}
									echo '<span style="color: #e74c3c;">';
									printf(
										/* translators: %d: number of emails failed */
										esc_html( _n( '%d failed', '%d failed', $failed_count, 'woo-ai-review-manager' ) ),
										$failed_count
									);
									echo '</span>';
								}
								if ( 0 === $sent_count && 0 === $failed_count ) {
									echo '<span style="color: #888;">' . esc_html__( 'Queued', 'woo-ai-review-manager' ) . '</span>';
								}
								?>
							</td>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $row->created_at ) ) ); ?></td>
							<td>
								<?php
								$is_expired = strtotime( $row->expires_at ) < time();
								$expires_formatted = wp_date( get_option( 'date_format' ), strtotime( $row->expires_at ) );
								if ( $is_expired && 'reviewed' !== $row->status ) :
								?>
									<span style="color: #e74c3c;"><?php echo esc_html( $expires_formatted ); ?></span>
								<?php else : ?>
									<?php echo esc_html( $expires_formatted ); ?>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( 'pending' === $row->status ) : ?>
									<button type="button" class="button button-small wairm-send-now" data-id="<?php echo absint( $row->id ); ?>">
										<?php esc_html_e( 'Send Now', 'woo-ai-review-manager' ); ?>
									</button>
								<?php elseif ( in_array( $row->status, [ 'sent', 'clicked', 'expired' ], true ) ) : ?>
									<button type="button" class="button button-small wairm-resend" data-id="<?php echo absint( $row->id ); ?>">
										<?php esc_html_e( 'Resend', 'woo-ai-review-manager' ); ?>
									</button>
								<?php elseif ( 'reviewed' === $row->status ) : ?>
									<span class="dashicons dashicons-yes-alt" style="color: #2ecc71; line-height: 1.8;" title="<?php esc_attr_e( 'Review submitted', 'woo-ai-review-manager' ); ?>"></span>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post( paginate_links( [
							'base'    => add_query_arg( 'paged', '%#%', add_query_arg( 'status', $filter, $base_url ) ),
							'format'  => '',
							'current' => $page,
							'total'   => $total_pages,
							'type'    => 'plain',
						] ) );
						?>
					</div>
				</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
