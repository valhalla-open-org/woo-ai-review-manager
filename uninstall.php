<?php
/**
 * Clean up plugin data on uninstall (deletion).
 *
 * @package WooAIReviewManager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Count orphaned reply comments that were posted by this plugin.
// These are WooCommerce review replies (comment_parent != 0) linked to our sentiment records.
$orphaned_replies = (int) $wpdb->get_var(
	"SELECT COUNT(DISTINCT c.comment_ID)
	 FROM {$wpdb->comments} c
	 JOIN {$wpdb->prefix}wairm_review_sentiment s
	   ON c.comment_parent = s.comment_id
	 WHERE s.ai_response_status = 'sent'"
);

if ( $orphaned_replies > 0 ) {
	/* translators: %d: number of reply comments that remain after uninstall */
	$message = sprintf(
		_n(
			'WooAI Review Manager: %d AI-generated reply comment remains on your product pages. You may want to review these manually.',
			'WooAI Review Manager: %d AI-generated reply comments remain on your product pages. You may want to review these manually.',
			$orphaned_replies,
			'woo-ai-review-manager'
		),
		$orphaned_replies
	);
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( '[WAIRM Uninstall] ' . $message );

	// Store a transient so the next admin page load can show a notice.
	set_transient( 'wairm_orphaned_replies_notice', $message, 60 * 60 * 24 );
}

// Drop custom tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wairm_email_queue" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wairm_review_invitations" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wairm_review_sentiment" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wairm_insights" );

// Delete plugin options.
$options = [
	'wairm_version',
	'wairm_invitation_delay_days',
	'wairm_reminder_enabled',
	'wairm_reminder_delay_days',
	'wairm_invitation_expiry_days',
	'wairm_email_from_name',
	'wairm_email_subject',
	'wairm_auto_analyze',
	'wairm_auto_respond_positive',
	'wairm_negative_threshold',
	'wairm_model_preference',
	'wairm_support_email',
	'wairm_reply_as',
];

foreach ( $options as $option ) {
	delete_option( $option );
}

// Clear any scheduled cron events.
wp_clear_scheduled_hook( 'wairm_send_review_invitations' );
wp_clear_scheduled_hook( 'wairm_expire_invitations' );
