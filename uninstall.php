<?php
/**
 * Clean up plugin data on uninstall (deletion).
 *
 * @package WooAIReviewManager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Drop custom tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wairm_email_queue" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wairm_review_invitations" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wairm_review_sentiment" );

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
];

foreach ( $options as $option ) {
	delete_option( $option );
}

// Clear any scheduled cron events.
wp_clear_scheduled_hook( 'wairm_process_pending_reviews' );
wp_clear_scheduled_hook( 'wairm_send_review_invitations' );
