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
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function add_submenu_page(): void {
		add_submenu_page(
			'wairm-dashboard',
			__( 'AI Responses', 'woo-ai-review-manager' ),
			__( 'AI Responses', 'woo-ai-review-manager' ),
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
					'confirm_dismiss'  => __( 'Dismiss this response suggestion?', 'woo-ai-review-manager' ),
					'confirm_post'     => __( 'Post this as a reply to the review? This will be visible to customers.', 'woo-ai-review-manager' ),
					'posted'           => __( 'Reply posted successfully.', 'woo-ai-review-manager' ),
					'updated'          => __( 'Response updated.', 'woo-ai-review-manager' ),
					'regenerating'     => __( 'Regenerating...', 'woo-ai-review-manager' ),
					'regenerated'      => __( 'New suggestion generated.', 'woo-ai-review-manager' ),
					'error'            => __( 'Something went wrong. Please try again.', 'woo-ai-review-manager' ),
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
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}

		global $wpdb;

		$id     = absint( $_POST['sentiment_id'] ?? 0 );
		$action = sanitize_key( $_POST['response_action'] ?? '' );
		$text   = sanitize_textarea_field( wp_unslash( $_POST['response_text'] ?? '' ) );

		$valid_actions = [ 'approved', 'dismissed' ];
		if ( ! $id || ! in_array( $action, $valid_actions, true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid request.' ] );
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
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}

		global $wpdb;

		$id   = absint( $_POST['sentiment_id'] ?? 0 );
		$text = sanitize_textarea_field( wp_unslash( $_POST['response_text'] ?? '' ) );

		if ( ! $id || empty( $text ) ) {
			wp_send_json_error( [ 'message' => 'Invalid request.' ] );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.comment_id, s.product_id FROM {$wpdb->prefix}wairm_review_sentiment s WHERE s.id = %d",
				$id
			)
		);

		if ( ! $row ) {
			wp_send_json_error( [ 'message' => 'Sentiment record not found.' ] );
		}

		$current_user = wp_get_current_user();

		$comment_id = wp_insert_comment( [
			'comment_post_ID' => (int) $row->product_id,
			'comment_parent'  => (int) $row->comment_id,
			'comment_content' => $text,
			'comment_type'    => 'comment',
			'comment_approved' => 1,
			'user_id'         => $current_user->ID,
			'comment_author'  => $current_user->display_name,
			'comment_author_email' => $current_user->user_email,
		] );

		if ( ! $comment_id ) {
			wp_send_json_error( [ 'message' => 'Failed to post reply.' ] );
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
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}

		if ( ! \WooAIReviewManager\AI_Client::is_available() ) {
			wp_send_json_error( [ 'message' => 'AI Client is not available.' ] );
		}

		global $wpdb;

		$id = absint( $_POST['sentiment_id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => 'Invalid request.' ] );
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
			wp_send_json_error( [ 'message' => 'Not found.' ] );
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

		$page     = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = 20;
		$offset   = ( $page - 1 ) * $per_page;

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
		<div class="wrap wairm-responses">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'AI Response Suggestions', 'woo-ai-review-manager' ); ?></h1>

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
				<div class="wairm-empty-state" style="text-align: center; padding: 60px 20px; background: #fff; border: 1px solid #ccd0d4; margin-top: 20px;">
					<p style="font-size: 16px; color: #666;">
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
				<div class="wairm-response-list">
					<?php foreach ( $rows as $row ) : ?>
					<div class="wairm-response-card" data-id="<?php echo absint( $row->id ); ?>" data-status="<?php echo esc_attr( $row->ai_response_status ); ?>">
						<div class="wairm-response-header">
							<span class="sentiment-badge sentiment-<?php echo esc_attr( $row->sentiment ); ?>"><?php echo esc_html( ucfirst( $row->sentiment ) ); ?></span>
							<span class="wairm-response-status status-<?php echo esc_attr( $row->ai_response_status ); ?>">
								<?php
								$status_labels = [
									'generated' => __( 'New', 'woo-ai-review-manager' ),
									'approved'  => __( 'Approved', 'woo-ai-review-manager' ),
									'sent'      => __( 'Posted', 'woo-ai-review-manager' ),
									'dismissed' => __( 'Dismissed', 'woo-ai-review-manager' ),
								];
								echo esc_html( $status_labels[ $row->ai_response_status ] ?? $row->ai_response_status );
								?>
							</span>
							<span class="wairm-response-meta">
								<?php echo esc_html( $row->product_name ); ?>
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
							<?php if ( 'sent' === $row->ai_response_status ) : ?>
								<span class="wairm-posted-label dashicons-before dashicons-yes-alt" style="color: #2ecc71;">
									<?php esc_html_e( 'Reply posted', 'woo-ai-review-manager' ); ?>
								</span>
							<?php endif; ?>
						</div>
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
