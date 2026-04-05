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
		add_action( 'wp_ajax_wairm_email_log', [ $this, 'ajax_email_log' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function add_submenu_page(): void {
		add_submenu_page(
			'wairm-dashboard',
			__( 'Invitations', 'ai-review-manager-for-woocommerce' ),
			__( 'Invitations', 'ai-review-manager-for-woocommerce' ),
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
					'confirm_send'   => __( 'Send this invitation email now?', 'ai-review-manager-for-woocommerce' ),
					'confirm_resend' => __( 'Resend this invitation email?', 'ai-review-manager-for-woocommerce' ),
					'sending'        => __( 'Sending...', 'ai-review-manager-for-woocommerce' ),
					'sent'           => __( 'Email sent successfully.', 'ai-review-manager-for-woocommerce' ),
					'error'          => __( 'Something went wrong. Please try again.', 'ai-review-manager-for-woocommerce' ),
					'send_now'       => __( 'Send Now', 'ai-review-manager-for-woocommerce' ),
					'resend'         => __( 'Resend', 'ai-review-manager-for-woocommerce' ),
					'status_pending'  => __( 'Pending', 'ai-review-manager-for-woocommerce' ),
					'status_sent'     => __( 'Sent', 'ai-review-manager-for-woocommerce' ),
					'status_clicked'  => __( 'Clicked', 'ai-review-manager-for-woocommerce' ),
					'status_reviewed' => __( 'Reviewed', 'ai-review-manager-for-woocommerce' ),
					'status_expired'  => __( 'Expired', 'ai-review-manager-for-woocommerce' ),
					'email_log'       => __( 'Email Log', 'ai-review-manager-for-woocommerce' ),
					'close'           => __( 'Close', 'ai-review-manager-for-woocommerce' ),
					'loading'         => __( 'Loading...', 'ai-review-manager-for-woocommerce' ),
					'no_emails'       => __( 'No emails found for this invitation.', 'ai-review-manager-for-woocommerce' ),
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
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-review-manager-for-woocommerce' ) ], 403 );
		}

		global $wpdb;

		$invitation_id = absint( $_POST['invitation_id'] ?? 0 );
		if ( ! $invitation_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'ai-review-manager-for-woocommerce' ) ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$invitation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wairm_review_invitations WHERE id = %d",
				$invitation_id
			)
		);

		if ( ! $invitation ) {
			wp_send_json_error( [ 'message' => __( 'Invitation not found.', 'ai-review-manager-for-woocommerce' ) ] );
		}

		if ( ! in_array( $invitation->status, [ 'pending' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'This invitation has already been sent.', 'ai-review-manager-for-woocommerce' ) ] );
		}

		// Find the queued initial email and send it now by setting scheduled_at to now.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$email = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wairm_email_queue
				 WHERE invitation_id = %d AND email_type = 'initial' AND status = 'queued'
				 LIMIT 1",
				$invitation_id
			)
		);

		if ( ! $email ) {
			wp_send_json_error( [ 'message' => __( 'No queued email found for this invitation.', 'ai-review-manager-for-woocommerce' ) ] );
		}

		// Update scheduled_at to now so it's immediately eligible.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated_email = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}wairm_email_queue WHERE id = %d",
				$email->id
			)
		);

		if ( 'sent' === $updated_email->status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$new_status = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT status FROM {$wpdb->prefix}wairm_review_invitations WHERE id = %d",
					$invitation_id
				)
			);
			wp_send_json_success( [ 'status' => $new_status ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Email sending failed. Check your mail configuration.', 'ai-review-manager-for-woocommerce' ) ] );
		}
	}

	/**
	 * AJAX: Resend an invitation that was already sent or failed.
	 */
	public function ajax_resend_invitation(): void {
		check_ajax_referer( 'wairm_invitations', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-review-manager-for-woocommerce' ) ], 403 );
		}

		global $wpdb;

		$invitation_id = absint( $_POST['invitation_id'] ?? 0 );
		if ( ! $invitation_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'ai-review-manager-for-woocommerce' ) ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$invitation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wairm_review_invitations WHERE id = %d",
				$invitation_id
			)
		);

		if ( ! $invitation ) {
			wp_send_json_error( [ 'message' => __( 'Invitation not found.', 'ai-review-manager-for-woocommerce' ) ] );
		}

		// Allow resending for sent, clicked, or expired invitations, but not reviewed ones.
		if ( 'reviewed' === $invitation->status ) {
			wp_send_json_error( [ 'message' => __( 'This customer has already submitted a review.', 'ai-review-manager-for-woocommerce' ) ] );
		}

		// Check for expired invitation — extend expiry.
		if ( 'expired' === $invitation->status ) {
			$expiry_days = absint( get_option( 'wairm_invitation_expiry_days', 30 ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
			wp_send_json_error( [ 'message' => __( 'Failed to queue email.', 'ai-review-manager-for-woocommerce' ) ] );
		}

		// Process immediately.
		\WooAIReviewManager\Email_Sender::process_queue();

		// Check result.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated_email = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}wairm_email_queue WHERE id = %d",
				$new_email_id
			)
		);

		if ( 'sent' === $updated_email->status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$new_status = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT status FROM {$wpdb->prefix}wairm_review_invitations WHERE id = %d",
					$invitation_id
				)
			);
			wp_send_json_success( [ 'status' => $new_status ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Email sending failed. Check your mail configuration.', 'ai-review-manager-for-woocommerce' ) ] );
		}
	}

	/**
	 * AJAX: Fetch detailed email log for an invitation.
	 */
	public function ajax_email_log(): void {
		check_ajax_referer( 'wairm_invitations', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-review-manager-for-woocommerce' ) ], 403 );
		}

		global $wpdb;
		$invitation_id = absint( $_POST['invitation_id'] ?? 0 );

		if ( ! $invitation_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'ai-review-manager-for-woocommerce' ) ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$emails = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, email_type, status, scheduled_at, sent_at
			 FROM {$wpdb->prefix}wairm_email_queue
			 WHERE invitation_id = %d
			 ORDER BY scheduled_at DESC",
			$invitation_id
		) );

		$rows = [];
		foreach ( $emails as $email ) {
			$rows[] = [
				'type'         => $email->email_type,
				'status'       => $email->status,
				'scheduled_at' => $email->scheduled_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $email->scheduled_at ) ) : '',
				'sent_at'      => $email->sent_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $email->sent_at ) ) : '—',
			];
		}

		wp_send_json_success( [ 'emails' => $rows ] );
	}

	public function render_page(): void {
		global $wpdb;

		$table  = $wpdb->prefix . 'wairm_review_invitations';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter parameter.
		$filter = sanitize_key( $_GET['status'] ?? 'all' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search/filter parameters.
		$search   = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_from = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_to   = sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) );

		// Count by status.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$counts = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
			$where .= $wpdb->prepare( ' AND i.status = %s', $filter );
		}

		if ( ! empty( $search ) ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= $wpdb->prepare( ' AND (i.customer_name LIKE %s OR i.customer_email LIKE %s)', $like, $like );
		}

		if ( ! empty( $date_from ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$where .= $wpdb->prepare( ' AND i.created_at >= %s', $date_from . ' 00:00:00' );
		}

		if ( ! empty( $date_to ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$where .= $wpdb->prepare( ' AND i.created_at <= %s', $date_to . ' 23:59:59' );
		}

		$pagination = Admin_Helpers::parse_pagination();
		$page       = $pagination['page'];
		$per_page   = $pagination['per_page'];
		$offset     = $pagination['offset'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where built via prepare() or literal.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} i WHERE {$where}" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
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
			<div class="wairm-page-header">
				<h1><?php esc_html_e( 'Review Invitations', 'ai-review-manager-for-woocommerce' ); ?></h1>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wairm_export_csv&export_type=invitations' ), 'wairm_export_csv' ) ); ?>" class="page-title-action">
					<?php esc_html_e( 'Export CSV', 'ai-review-manager-for-woocommerce' ); ?>
				</a>
			</div>
			<hr class="wp-header-end">

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'all', $base_url ) ); ?>" class="<?php echo 'all' === $filter ? 'current' : ''; ?>">
					<?php esc_html_e( 'All', 'ai-review-manager-for-woocommerce' ); ?>
					<span class="count">(<?php echo esc_html( (string) $count_all ); ?>)</span>
				</a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'pending', $base_url ) ); ?>" class="<?php echo 'pending' === $filter ? 'current' : ''; ?>">
					<?php esc_html_e( 'Pending', 'ai-review-manager-for-woocommerce' ); ?>
					<span class="count">(<?php echo esc_html( (string) $count_pending ); ?>)</span>
				</a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'sent', $base_url ) ); ?>" class="<?php echo 'sent' === $filter ? 'current' : ''; ?>">
					<?php esc_html_e( 'Sent', 'ai-review-manager-for-woocommerce' ); ?>
					<span class="count">(<?php echo esc_html( (string) $count_sent ); ?>)</span>
				</a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'clicked', $base_url ) ); ?>" class="<?php echo 'clicked' === $filter ? 'current' : ''; ?>">
					<?php esc_html_e( 'Clicked', 'ai-review-manager-for-woocommerce' ); ?>
					<span class="count">(<?php echo esc_html( (string) $count_clicked ); ?>)</span>
				</a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'reviewed', $base_url ) ); ?>" class="<?php echo 'reviewed' === $filter ? 'current' : ''; ?>">
					<?php esc_html_e( 'Reviewed', 'ai-review-manager-for-woocommerce' ); ?>
					<span class="count">(<?php echo esc_html( (string) $count_reviewed ); ?>)</span>
				</a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'expired', $base_url ) ); ?>" class="<?php echo 'expired' === $filter ? 'current' : ''; ?>">
					<?php esc_html_e( 'Expired', 'ai-review-manager-for-woocommerce' ); ?>
					<span class="count">(<?php echo esc_html( (string) $count_expired ); ?>)</span>
				</a></li>
			</ul>

			<br class="clear">

			<div class="wairm-invitation-filters">
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="wairm-invitations">
					<input type="hidden" name="status" value="<?php echo esc_attr( $filter ); ?>">
					<input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search customer...', 'ai-review-manager-for-woocommerce' ); ?>" class="wairm-search-input">
					<label><?php esc_html_e( 'From:', 'ai-review-manager-for-woocommerce' ); ?>
						<input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
					</label>
					<label><?php esc_html_e( 'To:', 'ai-review-manager-for-woocommerce' ); ?>
						<input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>">
					</label>
					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'ai-review-manager-for-woocommerce' ); ?></button>
					<?php if ( ! empty( $search ) || ! empty( $date_from ) || ! empty( $date_to ) ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'status', $filter, $base_url ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'ai-review-manager-for-woocommerce' ); ?></a>
					<?php endif; ?>
				</form>
			</div>

			<?php if ( empty( $rows ) ) : ?>
				<div class="wairm-empty-state">
					<p>
						<?php esc_html_e( 'No invitations found.', 'ai-review-manager-for-woocommerce' ); ?>
					</p>
				</div>
			<?php else : ?>
				<div class="wairm-table-scroll">
				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th scope="col" class="column-id"><?php esc_html_e( 'ID', 'ai-review-manager-for-woocommerce' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Customer', 'ai-review-manager-for-woocommerce' ); ?></th>
							<th scope="col" class="column-order"><?php esc_html_e( 'Order', 'ai-review-manager-for-woocommerce' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Products', 'ai-review-manager-for-woocommerce' ); ?></th>
							<th scope="col" class="column-status"><?php esc_html_e( 'Status', 'ai-review-manager-for-woocommerce' ); ?></th>
							<th scope="col" class="column-emails"><?php esc_html_e( 'Emails', 'ai-review-manager-for-woocommerce' ); ?></th>
							<th scope="col" class="column-date"><?php esc_html_e( 'Created', 'ai-review-manager-for-woocommerce' ); ?></th>
							<th scope="col" class="column-date"><?php esc_html_e( 'Expires', 'ai-review-manager-for-woocommerce' ); ?></th>
							<th scope="col" class="column-date"><?php esc_html_e( 'Actions', 'ai-review-manager-for-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
						<tr data-id="<?php echo absint( $row->id ); ?>">
							<td><?php echo absint( $row->id ); ?></td>
							<td>
								<strong><?php echo esc_html( $row->customer_name ); ?></strong><br>
								<span class="wairm-customer-email"><?php echo esc_html( $row->customer_email ); ?></span>
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
									$product_ids = array_map( 'absint', $product_ids );
									$product_ids = array_filter( $product_ids );
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
								<span class="wairm-badge-base wairm-invitation-status status-<?php echo esc_attr( $row->status ); ?>">
									<?php
									$status_labels = Admin_Helpers::invitation_status_labels();
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
										esc_html( _n( '%d sent', '%d sent', $sent_count, 'ai-review-manager-for-woocommerce' ) ),
										absint( $sent_count )
									);
								}
								if ( $failed_count > 0 ) {
									if ( $sent_count > 0 ) {
										echo ', ';
									}
									echo '<span class="wairm-email-failed">';
									printf(
										/* translators: %d: number of emails failed */
										esc_html( _n( '%d failed', '%d failed', $failed_count, 'ai-review-manager-for-woocommerce' ) ),
										absint( $failed_count )
									);
									echo '</span>';
								}
								if ( 0 === $sent_count && 0 === $failed_count ) {
									echo '<span class="wairm-email-queued">' . esc_html__( 'Queued', 'ai-review-manager-for-woocommerce' ) . '</span>';
								}
								?>
								<br>
								<button type="button" class="button-link wairm-view-email-log" data-id="<?php echo absint( $row->id ); ?>">
									<?php esc_html_e( 'View log', 'ai-review-manager-for-woocommerce' ); ?>
								</button>
							</td>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $row->created_at ) ) ); ?></td>
							<td>
								<?php
								$is_expired = strtotime( $row->expires_at ) < time();
								$expires_formatted = wp_date( get_option( 'date_format' ), strtotime( $row->expires_at ) );
								if ( $is_expired && 'reviewed' !== $row->status ) :
								?>
									<span class="wairm-date-expired"><?php echo esc_html( $expires_formatted ); ?></span>
								<?php else : ?>
									<?php echo esc_html( $expires_formatted ); ?>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( 'pending' === $row->status ) : ?>
									<button type="button" class="button button-small wairm-send-now" data-id="<?php echo absint( $row->id ); ?>">
										<?php esc_html_e( 'Send Now', 'ai-review-manager-for-woocommerce' ); ?>
									</button>
								<?php elseif ( in_array( $row->status, [ 'sent', 'clicked', 'expired' ], true ) ) : ?>
									<button type="button" class="button button-small wairm-resend" data-id="<?php echo absint( $row->id ); ?>">
										<?php esc_html_e( 'Resend', 'ai-review-manager-for-woocommerce' ); ?>
									</button>
								<?php elseif ( 'reviewed' === $row->status ) : ?>
									<span class="dashicons dashicons-yes-alt" title="<?php esc_attr_e( 'Review submitted', 'ai-review-manager-for-woocommerce' ); ?>"></span>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>

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

			<div id="wairm-email-log-modal" class="wairm-modal" style="display:none;">
				<div class="wairm-modal-content">
					<div class="wairm-modal-header">
						<h3><?php esc_html_e( 'Email Log', 'ai-review-manager-for-woocommerce' ); ?></h3>
						<button type="button" class="wairm-modal-close">&times;</button>
					</div>
					<div class="wairm-modal-body" id="wairm-email-log-body"></div>
				</div>
			</div>
		</div>
		<?php
	}
}
