<?php
/**
 * PSR-4 style autoloader for the plugin namespace.
 *
 * @package WooAIReviewManager
 */

declare(strict_types=1);

namespace WooAIReviewManager;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		spl_autoload_register( [ self::class, 'load' ] );
		self::$registered = true;
	}

	public static function load( string $class ): void {
		$prefix = 'WooAIReviewManager\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$filename = 'class-' . strtolower( str_replace( '_', '-', array_pop( $parts ) ) ) . '.php';

		$subdir = '';
		if ( $parts ) {
			$subdir = implode( '/', array_map( 'strtolower', $parts ) ) . '/';
		}

		$file = WAIRM_PLUGIN_DIR . 'includes/' . $subdir . $filename;
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}
