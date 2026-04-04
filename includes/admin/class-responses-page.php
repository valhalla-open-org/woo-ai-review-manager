<?php
/**
 * Admin page for managing AI response suggestions.
 *
 * Provides a workflow for store owners to review, approve, edit,
 * post as WooCommerce replies, or dismiss AI-generated responses.
 *
 * @package WooAIReviewManager
 */

declare(strict_types=1);

namespace WooAIReviewManager\Admin;

defined( 'ABSPATH' ) || exit;

final class Responses_Page {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_submenu_page' ] );
		add_action( 'wp_ajax_wairm_update_response', [ $this, 'ajax_update_response' ] );
		add_action( 'wp_ajax_wairm_post_response', [ $this, 'ajax_post_response' ] );
		add_action( 'wp_ajax_wairm_regenerate_response', [ $this, 'ajax_regenerate_response' ] );
		add_action( 'wp_ajax_wairm_bulk_response', [ $this, 'ajax_bulk_response' ] );
		add_action( 'wp_ajax_wairm_undo_dismiss', [ $this, 'ajax_undo_dismiss' ] );
		add_action( 'wp_ajax_wairm_edit_posted_reply', [ $this, 'ajax_edit_posted_reply' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function add_submenu_page(): void {
		$menu_label = __( 'AI Responses', 'woo-ai-review-manager' );
		if ( ! warc_fs()->is_paying() ) {
			$menu_label .= ' <span class="wairm-pro-badge" style="font-size:9px;padding:1px 5px;margin-left:4px;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;border-radius:3px;vertical-align:middle;">PRO</span>';
		}

		add_submenu_page(
			'wairm-dashboard',
			__( 'AI Responses', 'woo-ai-review-manager' ),
			$menu_label,
			'manage_woocommerce',
			'wairm-responses',
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'ai-reviews_page_wairm-responses' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wairm-admin',
			WAIRM_PLUGIN_URL . 'assets/css/admin.css',
			[],
			WAIRM_VERSION
		);

		wp_enqueue_script(
			'wairm-responses',
			WAIRM_PLUGIN_URL . 'assets/js/responses.js',
			[],
			WAIRM_VERSION,
			true
		);

		wp_localize_script(
			'wairm-responses',
			'wairmResponses',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wairm_responses' ),
				'i18n'     => [
					'confirm_dismiss'      => __( 'Dismiss this response suggestion?', 'woo-ai-review-manager' ),
					'confirm_post'         => __( 'Post this as a reply to the review? This will be visible to customers.', 'woo-ai-review-manager' ),
					'posted'               => __( 'Reply posted successfully.', 'woo-ai-review-manager' ),
					'updated'              => __( 'Response updated.', 'woo-ai-review-manager' ),
					'regenerating'         => __( 'Regenerating...', 'woo-ai-review-manager' ),
					'regenerated'          => __( 'New suggestion generated.', 'woo-ai-review-manager' ),
					'regenerate'           => __( 'Regenerate', 'woo-ai-review-manager' ),
					'error'                => __( 'Something went wrong. Please try again.', 'woo-ai-review-manager' ),
					'empty_response'       => __( 'Response text cannot be empty.', 'woo-ai-review-manager' ),
					'status_approved'      => __( 'Approved', 'woo-ai-review-manager' ),
					'status_dismissed'     => __( 'Dismissed', 'woo-ai-review-manager' ),
					'status_posted'        => __( 'Posted', 'woo-ai-review-manager' ),
					'status_new'           => __( 'New', 'woo-ai-review-manager' ),
					'bulk_confirm_approve' => __( 'Approve all selected responses?', 'woo-ai-review-manager' ),
					'bulk_confirm_dismiss' => __( 'Dismiss all selected responses?', 'woo-ai-review-manager' ),
					'bulk_confirm_post'    => __( 'Post all selected responses as replies? They will be visible to customers.', 'woo-ai-review-manager' ),
					'bulk_done'            => __( 'Bulk action completed.', 'woo-ai-review-manager' ),
					'no_selection'         => __( 'No responses selected.', 'woo-ai-review-manager' ),
					'undo_dismiss'         => __( 'Undo Dismiss', 'woo-ai-review-manager' ),
					'undo_success'         => __( 'Response restored.', 'woo-ai-review-manager' ),
					'reply_updated'        => __( 'Posted reply updated.', 'woo-ai-review-manager' ),
					'edit_reply'           => __( 'Update Reply', 'woo-ai-review-manager' ),
				],
			]
		);
	}

	/**
	 * AJAX: Update response status (approve or dismiss) and optionally edit text.
	 */
	public function ajax_update_response(): void {
		check_ajax_referer( 'wairm_responses', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-ai-review-manager' ) ], 403 );
		}

		if ( ! warc_fs()->is_paying() ) {
			wp_send_json_error( [ 'message' => __( 'This feature requires a Pro license.', 'woo-ai-review-manager' ) ], 403 );
		}

		global $wpdb;

		$id     = absint( $_POST['sentiment_id'] ?? 0 );
		$action = sanitize_key( $_POST['response_action'] ?? '' );
		$text   = sanitize_textarea_field( wp_unslash( $_POST['response_text'] ?? '' ) );

		$valid_actions = [ 'approved', 'dismissed' ];
		if ( ! $id || ! in_array( $action, $valid_actions, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'woo-ai-review-manager' ) ] );
		}

		// Validate state transition — only generated/approved responses can be updated.
		$current_status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ai_response_status FROM {$wpdb->prefix}wairm_review_sentiment WHERE id = %d",
				$id
			)
		);

		if ( ! in_array( $current_status, [ 'generated', 'approved' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'This response can no longer be modified.', 'woo-ai-review-manager' ) ] );
		}

		$update = [ 'ai_response_status' => $action ];
		$format = [ '%s' ];

		if ( '' !== $text && 'approved' === $action ) {
			$update['ai_response_suggestion'] = $text;
			$format[] = '%s';
		}

		$wpdb->update(
			$wpdb->prefix . 'wairm_review_sentiment',
			$update,
			[ 'id' => $id ],
			$format,
			[ '%d' ]
		);

		wp_send_json_success( [ 'status' => $action ] );
	}

	/**
	 * AJAX: Post the AI response as an actual WooCommerce reply comment.
	 */
	public function ajax_post_response(): void {
		check_ajax_referer( 'wairm_responses', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-ai-review-manager' ) ], 403 );
		}

		if ( ! warc_fs()->is_paying() ) {
			wp_send_json_error( [ 'message' => __( 'This feature requires a Pro license.', 'woo-ai-review-manager' ) ], 403 );
		}

		global $wpdb;

		$id   = absint( $_POST['sentiment_id'] ?? 0 );
		$text = sanitize_textarea_field( wp_unslash( $_POST['response_text'] ?? '' ) );

		if ( ! $id || empty( $text ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'woo-ai-review-manager' ) ] );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.comment_id, s.product_id, s.ai_response_status FROM {$wpdb->prefix}wairm_review_sentiment s WHERE s.id = %d",
				$id
			)
		);

		if ( ! $row ) {
			wp_send_json_error( [ 'message' => __( 'Sentiment record not found.', 'woo-ai-review-manager' ) ] );
		}

		// Only generated/approved responses can be posted.
		if ( ! in_array( $row->ai_response_status, [ 'generated', 'approved' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'This response has already been posted or dismissed.', 'woo-ai-review-manager' ) ] );
		}

		$current_user = wp_get_current_user();
		$reply_as     = get_option( 'wairm_reply_as', 'store' );

		if ( 'user' === $reply_as ) {
			$author_name  = $current_user->display_name;
			$author_email = $current_user->user_email;
		} else {
			$author_name = get_option( 'wairm_email_from_name', '' );
			if ( empty( $author_name ) ) {
				$author_name = get_bloginfo( 'name' );
			}
			$author_email = get_option( 'wairm_support_email', '' );
			if ( empty( $author_email ) ) {
				$author_email = get_option( 'admin_email' );
			}
		}

		$comment_id = wp_insert_comment( [
			'comment_post_ID'      => (int) $row->product_id,
			'comment_parent'       => (int) $row->comment_id,
			'comment_content'      => $text,
			'comment_type'         => 'comment',
			'comment_approved'     => 1,
			'user_id'              => $current_user->ID,
			'comment_author'       => $author_name,
			'comment_author_email' => $author_email,
		] );

		if ( ! $comment_id ) {
			wp_send_json_error( [ 'message' => __( 'Failed to post reply.', 'woo-ai-review-manager' ) ] );
		}

		$wpdb->update(
			$wpdb->prefix . 'wairm_review_sentiment',
			[
				'ai_response_suggestion' => $text,
				'ai_response_status'     => 'sent',
			],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		wp_send_json_success( [ 'comment_id' => $comment_id ] );
	}

	/**
	 * AJAX: Regenerate the AI response suggestion.
	 */
	public function ajax_regenerate_response(): void {
		check_ajax_referer( 'wairm_responses', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-ai-review-manager' ) ], 403 );
		}

		if ( ! warc_fs()->is_paying() ) {
			wp_send_json_error( [ 'message' => __( 'This feature requires a Pro license.', 'woo-ai-review-manager' ) ], 403 );
		}

		if ( ! \WooAIReviewManager\AI_Client::is_available() ) {
			wp_send_json_error( [ 'message' => __( 'AI Client is not available.', 'woo-ai-review-manager' ) ] );
		}

		global $wpdb;

		$id = absint( $_POST['sentiment_id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'woo-ai-review-manager' ) ] );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.*, c.comment_content
				 FROM {$wpdb->prefix}wairm_review_sentiment s
				 JOIN {$wpdb->comments} c ON c.comment_ID = s.comment_id
				 WHERE s.id = %d",
				$id
			)
		);

		if ( ! $row ) {
			wp_send_json_error( [ 'message' => __( 'Not found.', 'woo-ai-review-manager' ) ] );
		}

		$product      = wc_get_product( (int) $row->product_id );
		$product_name = $product ? $product->get_name() : '';
		$store_name   = get_bloginfo( 'name' );
		$review_text  = wp_strip_all_tags( $row->comment_content );

		$client = new \WooAIReviewManager\AI_Client();

		try {
			$suggestion = $client->generate_response( $review_text, $row->sentiment, $product_name, $store_name );
		} catch ( \RuntimeException $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}

		$suggestion = sanitize_textarea_field( $suggestion );

		$wpdb->update(
			$wpdb->prefix . 'wairm_review_sentiment',
			[
				'ai_response_suggestion' => $suggestion,
				'ai_response_status'     => 'generated',
			],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		wp_send_json_success( [ 'suggestion' => $suggestion ] );
	}

	/**
	 * AJAX: Bulk action on multiple responses.
	 */
	public function ajax_bulk_response(): void {
		check_ajax_referer( 'wairm_responses', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-ai-review-manager' ) ], 403 );
		}

		if ( ! warc_fs()->is_paying() ) {
			wp_send_json_error( [ 'message' => __( 'This feature requires a Pro license.', 'woo-ai-review-manager' ) ], 403 );
		}

		global $wpdb;

		$ids    = array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) );
		$ids    = array_filter( $ids );
		$action = sanitize_key( $_POST['bulk_action'] ?? '' );

		if ( empty( $ids ) || ! in_array( $action, [ 'approved', 'dismissed', 'post' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'woo-ai-review-manager' ) ] );
		}

		$table     = $wpdb->prefix . 'wairm_review_sentiment';
		$succeeded = 0;

		foreach ( $ids as $id ) {
			if ( 'post' === $action ) {
				// Simulate individual post — reuse post logic.
				$_POST['sentiment_id'] = $id;
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT s.*, s.ai_response_suggestion AS text FROM {$table} s WHERE s.id = %d",
					$id
				) );
				if ( $row && in_array( $row->ai_response_status, [ 'generated', 'approved' ], true ) && ! empty( $row->text ) ) {
					$_POST['response_text'] = $row->text;
					// Use internal post logic.
					$this->post_single_response( $id, $row->text );
					$succeeded++;
				}
			} else {
				$current = $wpdb->get_var( $wpdb->prepare(
					"SELECT ai_response_status FROM {$table} WHERE id = %d",
					$id
				) );
				if ( in_array( $current, [ 'generated', 'approved' ], true ) ) {
					$wpdb->update( $table, [ 'ai_response_status' => $action ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
					$succeeded++;
				}
			}
		}

		wp_send_json_success( [ 'updated' => $succeeded ] );
	}

	/**
	 * Post a single response as a WooCommerce reply (shared logic).
	 */
	private function post_single_response( int $id, string $text ): bool {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT comment_id, product_id, ai_response_status FROM {$wpdb->prefix}wairm_review_sentiment WHERE id = %d",
			$id
		) );

		if ( ! $row || ! in_array( $row->ai_response_status, [ 'generated', 'approved' ], true ) ) {
			return false;
		}

		$current_user = wp_get_current_user();
		$reply_as     = get_option( 'wairm_reply_as', 'store' );

		if ( 'user' === $reply_as ) {
			$author_name  = $current_user->display_name;
			$author_email = $current_user->user_email;
		} else {
			$author_name = get_option( 'wairm_email_from_name', '' );
			if ( empty( $author_name ) ) {
				$author_name = get_bloginfo( 'name' );
			}
			$author_email = get_option( 'wairm_support_email', '' );
			if ( empty( $author_email ) ) {
				$author_email = get_option( 'admin_email' );
			}
		}

		$comment_id = wp_insert_comment( [
			'comment_post_ID'      => (int) $row->product_id,
			'comment_parent'       => (int) $row->comment_id,
			'comment_content'      => $text,
			'comment_type'         => 'comment',
			'comment_approved'     => 1,
			'user_id'              => $current_user->ID,
			'comment_author'       => $author_name,
			'comment_author_email' => $author_email,
		] );

		if ( ! $comment_id ) {
			return false;
		}

		$wpdb->update(
			$wpdb->prefix . 'wairm_review_sentiment',
			[
				'ai_response_suggestion' => $text,
				'ai_response_status'     => 'sent',
			],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		return true;
	}

	/**
	 * AJAX: Undo a dismissed response (restore to generated).
	 */
	public function ajax_undo_dismiss(): void {
		check_ajax_referer( 'wairm_responses', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-ai-review-manager' ) ], 403 );
		}

		if ( ! warc_fs()->is_paying() ) {
			wp_send_json_error( [ 'message' => __( 'This feature requires a Pro license.', 'woo-ai-review-manager' ) ], 403 );
		}

		global $wpdb;
		$id = absint( $_POST['sentiment_id'] ?? 0 );

		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'woo-ai-review-manager' ) ] );
		}

		$current = $wpdb->get_var( $wpdb->prepare(
			"SELECT ai_response_status FROM {$wpdb->prefix}wairm_review_sentiment WHERE id = %d",
			$id
		) );

		if ( 'dismissed' !== $current ) {
			wp_send_json_error( [ 'message' => __( 'This response is not dismissed.', 'woo-ai-review-manager' ) ] );
		}

		$wpdb->update(
			$wpdb->prefix . 'wairm_review_sentiment',
			[ 'ai_response_status' => 'generated' ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);

		wp_send_json_success( [ 'status' => 'generated' ] );
	}

	/**
	 * AJAX: Edit an already-posted reply comment.
	 */
	public function ajax_edit_posted_reply(): void {
		check_ajax_referer( 'wairm_responses', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-ai-review-manager' ) ], 403 );
		}

		if ( ! warc_fs()->is_paying() ) {
			wp_send_json_error( [ 'message' => __( 'This feature requires a Pro license.', 'woo-ai-review-manager' ) ], 403 );
		}

		global $wpdb;

		$id   = absint( $_POST['sentiment_id'] ?? 0 );
		$text = sanitize_textarea_field( wp_unslash( $_POST['response_text'] ?? '' ) );

		if ( ! $id || empty( $text ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'woo-ai-review-manager' ) ] );
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT comment_id, ai_response_status FROM {$wpdb->prefix}wairm_review_sentiment WHERE id = %d",
			$id
		) );

		if ( ! $row || 'sent' !== $row->ai_response_status ) {
			wp_send_json_error( [ 'message' => __( 'This response has not been posted yet.', 'woo-ai-review-manager' ) ] );
		}

		// Find the reply comment (child of the review comment).
		$reply_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_parent = %d ORDER BY comment_ID DESC LIMIT 1",
			(int) $row->comment_id
		) );

		if ( ! $reply_id ) {
			wp_send_json_error( [ 'message' => __( 'Reply comment not found.', 'woo-ai-review-manager' ) ] );
		}

		wp_update_comment( [
			'comment_ID'      => (int) $reply_id,
			'comment_content' => $text,
		] );

		$wpdb->update(
			$wpdb->prefix . 'wairm_review_sentiment',
			[ 'ai_response_suggestion' => $text ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);

		wp_send_json_success();
	}

	public function render_page(): void {
		global $wpdb;

		$table  = $wpdb->prefix . 'wairm_review_sentiment';
		$filter = sanitize_key( $_GET['status'] ?? 'actionable' );

		// Count by status.
		$counts = $wpdb->get_results(
			"SELECT ai_response_status, COUNT(*) as cnt FROM {$table} WHERE ai_response_suggestion IS NOT NULL GROUP BY ai_response_status",
			OBJECT_K
		);

		$count_generated = (int) ( $counts['generated']->cnt ?? 0 );
		$count_approved  = (int) ( $counts['approved']->cnt ?? 0 );
		$count_sent      = (int) ( $counts['sent']->cnt ?? 0 );
		$count_dismissed = (int) ( $counts['dismissed']->cnt ?? 0 );
		$count_all       = $count_generated + $count_approved + $count_sent + $count_dismissed;

		// Build WHERE.
		$where = 's.ai_response_suggestion IS NOT NULL';
		switch ( $filter ) {
			case 'actionable':
				$where .= " AND s.ai_response_status IN ('generated', 'approved')";
				break;
			case 'generated':
			case 'approved':
			case 'sent':
			case 'dismissed':
				$where .= $wpdb->prepare( ' AND s.ai_response_status = %s', $filter );
				break;
			// 'all' — no additional filter.
		}

		$pagination = Admin_Helpers::parse_pagination();
		$page       = $pagination['page'];
		$per_page   = $pagination['per_page'];
		$offset     = $pagination['offset'];

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} s WHERE {$where}" );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where built with prepare() above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, c.comment_content, c.comment_author, c.comment_date,
				        p.post_title AS product_name
				 FROM {$table} s
				 JOIN {$wpdb->comments} c ON c.comment_ID = s.comment_id
				 JOIN {$wpdb->posts} p ON p.ID = s.product_id
				 WHERE {$where}
				 ORDER BY
				   CASE s.ai_response_status
				     WHEN 'generated' THEN 0
				     WHEN 'approved' THEN 1
				     WHEN 'sent' THEN 2
				     WHEN 'dismissed' THEN 3
				   END ASC,
				   s.analyzed_at DESC
				 LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		$total_pages = (int) ceil( $total / $per_page );
		$base_url    = admin_url( 'admin.php?page=wairm-responses' );
		?>
		<?php $pending_analysis = \WooAIReviewManager\Sentiment_Analyzer::count_pending(); ?>
		<div class="wrap wairm-responses">
			<div class="wairm-page-header">
				<h1><?php esc_html_e( 'AI Response Suggestions', 'woo-ai-review-manager' ); ?></h1>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wairm_export_csv&export_type=responses' ), 'wairm_export_csv' ) ); ?>" class="page-title-action">
					<?php esc_html_e( 'Export CSV', 'woo-ai-review-manager' ); ?>
				</a>
			</div>
			<hr class="wp-header-end">

			<?php if ( $pending_analysis > 0 ) : ?>
			<div class="wairm-notice-bar">
				<span class="dashicons dashicons-info"></span>
				<p>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: 1: count, 2: link open, 3: link close */
							_n(
								'%1$s review is awaiting AI analysis. %2$sRun analysis on the Dashboard%3$s to generate sentiment scores and response suggestions.',
								'%1$s reviews are awaiting AI analysis. %2$sRun analysis on the Dashboard%3$s to generate sentiment scores and response suggestions.',
								$pending_analysis,
								'woo-ai-review-manager'
							),
							'<strong>' . esc_html( (string) $pending_analysis ) . '</strong>',
							'<a href="' . esc_url( admin_url( 'admin.php?page=wairm-dashboard' ) ) . '">',
							'</a>'
						),
						[ 'strong' => [], 'a' => [ 'href' => [] ] ]
					);
					?>
				</p>
			</div>
			<?php endif; ?>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'actionable', $base_url ) ); ?>" class="<?php echo 'actionable' === $filter ? 'current' : ''; ?>">
					<?php esc_html_e( 'Needs Action', 'woo-ai-review-manager' ); ?>
					<span class="count">(<?php echo esc_html( $count_generated + $count_approved ); ?>)</span>
				</a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'generated', $base_url ) ); ?>" class="<?php echo 'generated' === $filter ? 'current' : ''; ?>">
					<?php esc_html_e( 'New', 'woo-ai-review-manager' ); ?>
					<span class="count">(<?php echo esc_html( $count_generated ); ?>)</span>
				</a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'approved', $base_url ) ); ?>" class="<?php echo 'approved' === $filter ? 'current' : ''; ?>">
					<?php esc_html_e( 'Approved', 'woo-ai-review-manager' ); ?>
					<span class="count">(<?php echo esc_html( $count_approved ); ?>)</span>
				</a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'sent', $base_url ) ); ?>" class="<?php echo 'sent' === $filter ? 'current' : ''; ?>">
					<?php esc_html_e( 'Posted', 'woo-ai-review-manager' ); ?>
					<span class="count">(<?php echo esc_html( $count_sent ); ?>)</span>
				</a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'dismissed', $base_url ) ); ?>" class="<?php echo 'dismissed' === $filter ? 'current' : ''; ?>">
					<?php esc_html_e( 'Dismissed', 'woo-ai-review-manager' ); ?>
					<span class="count">(<?php echo esc_html( $count_dismissed ); ?>)</span>
				</a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'all', $base_url ) ); ?>" class="<?php echo 'all' === $filter ? 'current' : ''; ?>">
					<?php esc_html_e( 'All', 'woo-ai-review-manager' ); ?>
					<span class="count">(<?php echo esc_html( $count_all ); ?>)</span>
				</a></li>
			</ul>

			<br class="clear">

			<?php if ( empty( $rows ) ) : ?>
				<div class="wairm-empty-state">
					<p>
						<?php
						if ( 'actionable' === $filter ) {
							esc_html_e( 'No responses need your attention right now.', 'woo-ai-review-manager' );
						} else {
							esc_html_e( 'No responses found for this filter.', 'woo-ai-review-manager' );
						}
						?>
					</p>
				</div>
			<?php else : ?>
				<?php if ( ! warc_fs()->is_paying() ) : ?>
					<div class="wairm-upgrade-banner" style="margin-bottom: 20px;">
						<p><?php esc_html_e( 'Upgrade to Pro to approve, edit, and post AI response suggestions as replies to your reviews.', 'woo-ai-review-manager' ); ?></p>
						<a href="<?php echo esc_url( warc_fs()->get_upgrade_url() ); ?>" class="button"><?php esc_html_e( 'Upgrade to Pro', 'woo-ai-review-manager' ); ?></a>
					</div>
				<?php endif; ?>
				<?php if ( warc_fs()->is_paying() ) : ?>
				<div class="wairm-bulk-bar" id="wairm-bulk-bar" style="display:none;">
					<label><input type="checkbox" id="wairm-select-all"> <?php esc_html_e( 'Select all', 'woo-ai-review-manager' ); ?></label>
					<span class="wairm-bulk-count"></span>
					<button type="button" class="button wairm-bulk-approve"><?php esc_html_e( 'Approve', 'woo-ai-review-manager' ); ?></button>
					<button type="button" class="button button-primary wairm-bulk-post"><?php esc_html_e( 'Post Reply', 'woo-ai-review-manager' ); ?></button>
					<button type="button" class="button wairm-bulk-dismiss"><?php esc_html_e( 'Dismiss', 'woo-ai-review-manager' ); ?></button>
				</div>
				<?php endif; ?>

				<div class="wairm-response-list">
					<?php foreach ( $rows as $row ) : ?>
					<div class="wairm-response-card" data-id="<?php echo absint( $row->id ); ?>" data-status="<?php echo esc_attr( $row->ai_response_status ); ?>">
						<?php if ( warc_fs()->is_paying() && in_array( $row->ai_response_status, [ 'generated', 'approved' ], true ) ) : ?>
						<label class="wairm-bulk-check"><input type="checkbox" class="wairm-card-checkbox" value="<?php echo absint( $row->id ); ?>"></label>
						<?php endif; ?>
						<div class="wairm-response-header">
							<span class="sentiment-badge sentiment-<?php echo esc_attr( $row->sentiment ); ?>"><?php echo esc_html( ucfirst( $row->sentiment ) ); ?></span>
							<span class="wairm-badge-base wairm-response-status status-<?php echo esc_attr( $row->ai_response_status ); ?>">
								<?php
								$status_labels = Admin_Helpers::response_status_labels();
								echo esc_html( $status_labels[ $row->ai_response_status ] ?? $row->ai_response_status );
								?>
							</span>
							<span class="wairm-response-meta">
								<a href="<?php echo esc_url( get_permalink( (int) $row->product_id ) ); ?>" target="_blank"><?php echo esc_html( $row->product_name ); ?></a>
								&middot;
								<?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $row->comment_date ) ) ); ?>
								&middot;
								<?php echo esc_html( $row->comment_author ); ?>
							</span>
						</div>

						<div class="wairm-response-review">
							<strong><?php esc_html_e( 'Customer Review:', 'woo-ai-review-manager' ); ?></strong>
							<p><?php echo esc_html( $row->comment_content ); ?></p>
							<span class="review-score"><?php esc_html_e( 'Score:', 'woo-ai-review-manager' ); ?> <?php echo esc_html( number_format( (float) $row->score, 2 ) ); ?></span>
						</div>

						<?php if ( warc_fs()->is_paying() ) : ?>
						<div class="wairm-response-suggestion">
							<strong><?php esc_html_e( 'AI Suggested Response:', 'woo-ai-review-manager' ); ?></strong>
							<textarea class="wairm-response-text large-text" rows="3"><?php echo esc_textarea( $row->ai_response_suggestion ); ?></textarea>
						</div>

						<div class="wairm-response-actions">
							<?php if ( in_array( $row->ai_response_status, [ 'generated', 'approved' ], true ) ) : ?>
								<button type="button" class="button button-primary wairm-action-post" title="<?php esc_attr_e( 'Post as a visible reply to this review', 'woo-ai-review-manager' ); ?>">
									<?php esc_html_e( 'Post Reply', 'woo-ai-review-manager' ); ?>
								</button>
								<button type="button" class="button wairm-action-approve" title="<?php esc_attr_e( 'Approve and save edits for later', 'woo-ai-review-manager' ); ?>">
									<?php esc_html_e( 'Approve', 'woo-ai-review-manager' ); ?>
								</button>
								<button type="button" class="button wairm-action-dismiss" title="<?php esc_attr_e( 'Dismiss this suggestion', 'woo-ai-review-manager' ); ?>">
									<?php esc_html_e( 'Dismiss', 'woo-ai-review-manager' ); ?>
								</button>
							<?php endif; ?>
							<?php if ( \WooAIReviewManager\AI_Client::is_available() && 'sent' !== $row->ai_response_status ) : ?>
								<button type="button" class="button wairm-action-regenerate" title="<?php esc_attr_e( 'Generate a new AI suggestion', 'woo-ai-review-manager' ); ?>">
									<?php esc_html_e( 'Regenerate', 'woo-ai-review-manager' ); ?>
								</button>
							<?php endif; ?>
							<?php if ( 'dismissed' === $row->ai_response_status ) : ?>
								<button type="button" class="button wairm-action-undo-dismiss" title="<?php esc_attr_e( 'Restore this suggestion', 'woo-ai-review-manager' ); ?>">
									<?php esc_html_e( 'Undo Dismiss', 'woo-ai-review-manager' ); ?>
								</button>
							<?php endif; ?>
							<?php if ( 'sent' === $row->ai_response_status ) : ?>
								<button type="button" class="button wairm-action-edit-reply" title="<?php esc_attr_e( 'Edit the posted reply', 'woo-ai-review-manager' ); ?>">
									<?php esc_html_e( 'Edit Reply', 'woo-ai-review-manager' ); ?>
								</button>
								<span class="wairm-posted-label dashicons-before dashicons-yes-alt">
									<?php esc_html_e( 'Reply posted', 'woo-ai-review-manager' ); ?>
								</span>
							<?php endif; ?>
						</div>
						<?php else : ?>
						<div class="wairm-response-actions">
							<button type="button" class="button button-primary" disabled><?php esc_html_e( 'Post Reply', 'woo-ai-review-manager' ); ?></button>
							<button type="button" class="button" disabled><?php esc_html_e( 'Approve', 'woo-ai-review-manager' ); ?></button>
							<button type="button" class="button" disabled><?php esc_html_e( 'Dismiss', 'woo-ai-review-manager' ); ?></button>
							<a href="<?php echo esc_url( warc_fs()->get_upgrade_url() ); ?>" class="button" style="background:#7c3aed;border-color:#6d28d9;color:#fff;text-shadow:none;white-space:nowrap;">
								<?php esc_html_e( 'Upgrade to Pro — AI responses, auto-replies & more', 'woo-ai-review-manager' ); ?>
							</a>
						</div>
						<?php endif; ?>
					</div>
					<?php endforeach; ?>
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
		</div>
		<?php
	}
}
