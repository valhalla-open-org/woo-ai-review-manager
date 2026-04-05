<?php
/**
 * Shared helper methods for admin pages.
 *
 * @package WooAIReviewManager
 */

declare(strict_types=1);

namespace WooAIReviewManager\Admin;

defined( 'ABSPATH' ) || exit;

final class Admin_Helpers {

	/**
	 * Parse pagination parameters from the current request.
	 *
	 * @param int $per_page Items per page.
	 * @return array{page: int, per_page: int, offset: int}
	 */
	public static function parse_pagination( int $per_page = 20 ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination parameter.
		$page   = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$offset = ( $page - 1 ) * $per_page;

		return [
			'page'     => $page,
			'per_page' => $per_page,
			'offset'   => $offset,
		];
	}

	/**
	 * Get translated invitation status labels.
	 *
	 * @return array<string, string>
	 */
	public static function invitation_status_labels(): array {
		return [
			'pending'  => __( 'Pending', 'ai-review-manager-for-woocommerce' ),
			'sent'     => __( 'Sent', 'ai-review-manager-for-woocommerce' ),
			'clicked'  => __( 'Clicked', 'ai-review-manager-for-woocommerce' ),
			'reviewed' => __( 'Reviewed', 'ai-review-manager-for-woocommerce' ),
			'expired'  => __( 'Expired', 'ai-review-manager-for-woocommerce' ),
		];
	}

	/**
	 * Get translated response status labels.
	 *
	 * @return array<string, string>
	 */
	public static function response_status_labels(): array {
		return [
			'generated' => __( 'New', 'ai-review-manager-for-woocommerce' ),
			'approved'  => __( 'Approved', 'ai-review-manager-for-woocommerce' ),
			'sent'      => __( 'Posted', 'ai-review-manager-for-woocommerce' ),
			'dismissed' => __( 'Dismissed', 'ai-review-manager-for-woocommerce' ),
		];
	}
}
