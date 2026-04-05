<?php
/**
 * Plugin Name: AI Review Manager for WooCommerce
 * Plugin URI:  https://github.com/valhalla-open-org/ai-review-manager-for-woocommerce
 * Description: Automated review collection, sentiment analysis, and AI-powered response suggestions for WooCommerce stores.
 * Version:     2.1.0
 * Author:      Valhalla Open
 * Author URI:  https://github.com/valhalla-open-org
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-review-manager-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 7.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.6
 *
 * @package WooAIReviewManager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( function_exists( 'warc_fs' ) ) {
	warc_fs()->set_basename( true, __FILE__ );
} else {
	/**
	 * DO NOT REMOVE THIS IF, IT IS ESSENTIAL FOR THE
	 * `function_exists` CALL ABOVE TO PROPERLY WORK.
	 */
	if ( ! function_exists( 'warc_fs' ) ) {
		function warc_fs() {
			global $warc_fs;

			if ( ! isset( $warc_fs ) ) {
				require_once dirname( __FILE__ ) . '/freemius/start.php';

				$warc_fs = fs_dynamic_init( array(
					'id'                  => '27153',
					'slug'                => 'wc-ai-reviews-companion',
					'type'                => 'plugin',
					'public_key'          => 'pk_e627e97942b080aad0e632b029d0d',
					'is_premium'          => true,
					'premium_suffix'      => 'Professional',
					'has_premium_version' => true,
					'has_addons'          => false,
					'has_paid_plans'      => true,
					'is_org_compliant'    => true,
					'menu'                => array(
						'slug'    => 'wairm-dashboard',
						'contact' => false,
						'support' => false,
					),
				) );
			}

			return $warc_fs;
		}

		warc_fs();
		do_action( 'warc_fs_loaded' );
	}

	// Plugin constants.
	define( 'WAIRM_VERSION', '2.1.0' );
	define( 'WAIRM_PLUGIN_FILE', __FILE__ );
	define( 'WAIRM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
	define( 'WAIRM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
	define( 'WAIRM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

	/**
	 * Declare HPOS compatibility.
	 */
	add_action(
		'before_woocommerce_init',
		static function (): void {
			if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			}
		}
	);

	/**
	 * Check dependencies before loading.
	 */
	function wairm_check_dependencies(): bool {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'AI Review Manager for WooCommerce requires WooCommerce to be installed and active.', 'ai-review-manager-for-woocommerce' )
					);
				}
			);
			return false;
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					printf(
						'<div class="notice notice-warning"><p>%s</p></div>',
						esc_html__( 'AI Review Manager for WooCommerce requires WordPress 7.0+ with the AI Client. Sentiment analysis and AI responses are disabled until the AI Client is available.', 'ai-review-manager-for-woocommerce' )
					);
				}
			);
		}

		return true;
	}

	/**
	 * Load plugin textdomain for translations.
	 */
	function wairm_load_textdomain(): void {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Required for non-wp.org distribution
		load_plugin_textdomain(
			'ai-review-manager-for-woocommerce',
			false,
			dirname( WAIRM_PLUGIN_BASENAME ) . '/languages'
		);
	}
	add_action( 'plugins_loaded', 'wairm_load_textdomain', 10 );

	/**
	 * Bootstrap the plugin.
	 */
	function wairm_init(): void {
		if ( ! wairm_check_dependencies() ) {
			return;
		}

		// Autoloader.
		require_once WAIRM_PLUGIN_DIR . 'includes/class-autoloader.php';
		\WooAIReviewManager\Autoloader::register();

		// Core.
		\WooAIReviewManager\Plugin::instance();
	}
	add_action( 'plugins_loaded', 'wairm_init', 20 );

	/**
	 * Activation hook.
	 */
	register_activation_hook( __FILE__, static function (): void {
		require_once WAIRM_PLUGIN_DIR . 'includes/class-autoloader.php';
		\WooAIReviewManager\Autoloader::register();
		\WooAIReviewManager\Installer::activate();
	} );

	/**
	 * Deactivation hook.
	 */
	register_deactivation_hook( __FILE__, static function (): void {
		require_once WAIRM_PLUGIN_DIR . 'includes/class-autoloader.php';
		\WooAIReviewManager\Autoloader::register();
		\WooAIReviewManager\Installer::deactivate();
	} );

	/**
	 * Uninstall cleanup — hooked to Freemius after_uninstall so uninstall
	 * feedback is reported before plugin data is removed.
	 */
	function warc_fs_uninstall_cleanup(): void {
		global $wpdb;

		// Log warning about orphaned AI reply comments.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$orphaned_replies = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT c.comment_ID)
			 FROM {$wpdb->comments} c
			 JOIN {$wpdb->prefix}wairm_review_sentiment s
			   ON c.comment_parent = s.comment_id
			 WHERE s.ai_response_status = 'sent'"
		);

		if ( $orphaned_replies > 0 ) {
			$message = sprintf(
				/* translators: %d: number of reply comments that remain after uninstall */
				_n(
					'AI Review Manager: %d AI-generated reply comment remains on your product pages. You may want to review these manually.',
					'AI Review Manager: %d AI-generated reply comments remain on your product pages. You may want to review these manually.',
					$orphaned_replies,
					'ai-review-manager-for-woocommerce'
				),
				$orphaned_replies
			);
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[WAIRM Uninstall] ' . $message );
			set_transient( 'wairm_orphaned_replies_notice', $message, DAY_IN_SECONDS );
		}

		// Drop custom tables.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wairm_email_queue" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wairm_review_invitations" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wairm_review_sentiment" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
			'wairm_email_greeting',
			'wairm_email_body_text',
			'wairm_email_button_text',
			'wairm_reminder_subject',
			'wairm_reminder_greeting',
			'wairm_reminder_body_text',
			'wairm_reminder_button_text',
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

		// Clear scheduled cron events.
		wp_clear_scheduled_hook( 'wairm_send_review_invitations' );
		wp_clear_scheduled_hook( 'wairm_expire_invitations' );
	}

	warc_fs()->add_action( 'after_uninstall', 'warc_fs_uninstall_cleanup' );
}
