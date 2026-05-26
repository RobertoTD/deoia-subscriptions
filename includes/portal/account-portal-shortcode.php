<?php
/**
 * Shortcode [deoia_account_portal] — router del Portal DEOIA.
 *
 * @package DeoiaSubscriptions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param string $status_label
 * @return string
 */
function deoia_subscriptions_portal_status_modifier( string $status_label ): string {
	$lower = strtolower( $status_label );
	if ( str_contains( $lower, 'activ' ) ) {
		return 'deoia-account-portal__badge--ok';
	}
	if ( str_contains( $lower, 'pend' ) ) {
		return 'deoia-account-portal__badge--warn';
	}
	return 'deoia-account-portal__badge--neutral';
}

/**
 * Shortcode: portal account / access UI.
 *
 * @return string
 */
function deoia_subscriptions_render_account_portal_shortcode(): string {
	wp_enqueue_style( 'deoia-account-portal' );

	if (
		function_exists( 'deoia_subscriptions_portal_is_verify_request' )
		&& deoia_subscriptions_portal_is_verify_request()
	) {
		return '';
	}

	if (
		function_exists( 'deoia_subscriptions_portal_is_access_page_view' )
		&& deoia_subscriptions_portal_is_access_page_view()
		&& function_exists( 'deoia_subscriptions_render_portal_access_request_view' )
	) {
		return deoia_subscriptions_render_portal_access_request_view();
	}

	if (
		function_exists( 'deoia_subscriptions_portal_is_account_page_view' )
		&& deoia_subscriptions_portal_is_account_page_view()
		&& function_exists( 'deoia_subscriptions_render_portal_account_dashboard_view' )
	) {
		return deoia_subscriptions_render_portal_account_dashboard_view();
	}

	return '';
}
