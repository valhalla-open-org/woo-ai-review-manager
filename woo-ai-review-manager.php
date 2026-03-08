<?php
/**
 * Plugin Name: WooCommerce AI Review Manager
 * Plugin URI:  https://github.com/valhalla-open-org/woo-ai-review-manager
 * Description: Automated review collection, sentiment analysis, and AI-powered response suggestions for WooCommerce stores.
 * Version:     1.0.0
 * Author:      Valhalla Open
 * Author URI:  https://github.com/valhalla-open-org
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woo-ai-review-manager
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.6
 *
 * @package WooAIReviewManager
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'WAIRM_VERSION', '1.0.0' );
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
					esc_html__( 'WooCommerce AI Review Manager requires WooCommerce to be installed and active.', 'woo-ai-review-manager' )
				);
			}
		);
		return false;
	}
	return true;
}

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
