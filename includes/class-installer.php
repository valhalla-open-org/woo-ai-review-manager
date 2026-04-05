<?php
/**
 * Handles plugin activation, deactivation, and DB schema.
 *
 * @package WooAIReviewManager
 */

declare(strict_types=1);

namespace WooAIReviewManager;

defined( 'ABSPATH' ) || exit;

final class Installer {

	public static function activate(): void {
		self::create_tables();
		self::schedule_cron();
		self::set_default_options();

		// Clean up legacy cron that auto-analyzed all unanalyzed reviews.
		wp_clear_scheduled_hook( 'wairm_process_pending_reviews' );

		update_option( 'wairm_version', WAIRM_VERSION );
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'wairm_send_review_invitations' );
		wp_clear_scheduled_hook( 'wairm_expire_invitations' );
	}

	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "
		CREATE TABLE {$wpdb->prefix}wairm_review_invitations (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			customer_email VARCHAR(200) NOT NULL,
			customer_name VARCHAR(200) NOT NULL DEFAULT '',
			product_ids TEXT NOT NULL,
			status ENUM('pending','sent','clicked','reviewed','expired') NOT NULL DEFAULT 'pending',
			token VARCHAR(64) NOT NULL,
			sent_at DATETIME NULL,
			clicked_at DATETIME NULL,
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY token (token),
			KEY order_id (order_id),
			KEY customer_email (customer_email),
			KEY status (status)
		) {$charset_collate};

		CREATE TABLE {$wpdb->prefix}wairm_review_sentiment (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			comment_id BIGINT UNSIGNED NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL,
			sentiment ENUM('positive','neutral','negative') NOT NULL,
			score DECIMAL(3,2) NOT NULL DEFAULT 0.00,
			key_phrases TEXT NULL,
			ai_response_suggestion TEXT NULL,
			ai_response_status ENUM('pending','generated','approved','sent','dismissed') NOT NULL DEFAULT 'pending',
			analyzed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY comment_id (comment_id),
			KEY product_id (product_id),
			KEY sentiment (sentiment)
		) {$charset_collate};

		CREATE TABLE {$wpdb->prefix}wairm_insights (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			category VARCHAR(20) NOT NULL,
			period VARCHAR(10) NOT NULL DEFAULT 'all',
			content LONGTEXT NOT NULL,
			review_count INT UNSIGNED NOT NULL DEFAULT 0,
			generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY category_generated (category, generated_at)
		) {$charset_collate};

		CREATE TABLE {$wpdb->prefix}wairm_email_queue (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invitation_id BIGINT UNSIGNED NOT NULL,
			email_type ENUM('initial','reminder') NOT NULL DEFAULT 'initial',
			scheduled_at DATETIME NOT NULL,
			sent_at DATETIME NULL,
			status ENUM('queued','sent','failed','cancelled') NOT NULL DEFAULT 'queued',
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			last_error TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY invitation_id (invitation_id),
			KEY status_scheduled (status, scheduled_at)
		) {$charset_collate};
		";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	private static function schedule_cron(): void {
		// Register custom cron interval first so it's available for scheduling.
		add_filter(
			'cron_schedules',
			static function ( array $schedules ): array {
				$schedules['every_five_minutes'] = [
					'interval' => 300,
					'display'  => esc_html__( 'Every 5 Minutes', 'ai-review-manager-for-woocommerce' ),
				];
				return $schedules;
			}
		);

		if ( ! wp_next_scheduled( 'wairm_send_review_invitations' ) ) {
			wp_schedule_event( time(), 'every_five_minutes', 'wairm_send_review_invitations' );
		}
		if ( ! wp_next_scheduled( 'wairm_expire_invitations' ) ) {
			wp_schedule_event( time(), 'daily', 'wairm_expire_invitations' );
		}
	}

	private static function set_default_options(): void {
		$defaults = [
			'wairm_invitation_delay_days' => 7,
			'wairm_reminder_enabled'      => 'yes',
			'wairm_reminder_delay_days'   => 14,
			'wairm_invitation_expiry_days' => 30,
			'wairm_email_from_name'       => get_bloginfo( 'name' ),
			'wairm_email_subject'         => __( 'How was your recent purchase?', 'ai-review-manager-for-woocommerce' ),
			'wairm_auto_analyze'          => 'yes',
			'wairm_auto_respond_positive' => 'no',
			'wairm_negative_threshold'    => '0.30',
		];

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				update_option( $key, $value );
			}
		}
	}
}
