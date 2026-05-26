<?php
/**
 * Magic-link verify flow: consume token server-side, set session cookie, redirect (M3.5).
 *
 * @package DeoiaSubscriptions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DEOIA_PORTAL_SESSION_COOKIE_NAME = 'deoia_portal_session';

/**
 * Resolves backend POST /portal/access/consume URL.
 */
function deoia_subscriptions_backend_portal_consume_url(): ?string {
	if (
		defined( 'DEOIA_SUBSCRIPTIONS_BACKEND_PORTAL_CONSUME_URL' )
		&& is_string( constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_PORTAL_CONSUME_URL' ) )
		&& constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_PORTAL_CONSUME_URL' ) !== ''
	) {
		return (string) constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_PORTAL_CONSUME_URL' );
	}

	if ( ! function_exists( 'deoia_subscriptions_backend_start_url_is_configured' ) ) {
		return null;
	}

	if ( ! deoia_subscriptions_backend_start_url_is_configured() ) {
		return null;
	}

	$start_url            = rtrim( (string) constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_START_URL' ), '/' );
	$suffix               = '/subscriptions/start';
	$suffix_length        = strlen( $suffix );

	if ( strlen( $start_url ) <= $suffix_length || substr( $start_url, -$suffix_length ) !== $suffix ) {
		return null;
	}

	return substr( $start_url, 0, -$suffix_length ) . '/portal/access/consume';
}

/**
 * Whether the current front-end request targets the portal verify route.
 */
function deoia_subscriptions_portal_is_verify_request(): bool {
	if ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ) {
		$uri = wp_unslash( $_SERVER['REQUEST_URI'] );
		if ( is_string( $uri ) && strpos( $uri, '/account/access/verify' ) !== false ) {
			return true;
		}
	}

	if ( ! is_page() ) {
		return false;
	}

	$page = get_queried_object();
	if ( ! $page instanceof WP_Post || $page->post_name !== 'verify' || ! $page->post_parent ) {
		return false;
	}

	$parent = get_post( (int) $page->post_parent );
	return $parent instanceof WP_Post && $parent->post_name === 'access';
}

/**
 * Reads magic-link token from query string (verify route only).
 */
function deoia_subscriptions_portal_get_magic_link_token_from_request(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- One-time token from email link.
	if ( ! isset( $_GET['token'] ) ) {
		return '';
	}

	$raw = wp_unslash( $_GET['token'] );
	if ( ! is_string( $raw ) ) {
		return '';
	}

	return sanitize_text_field( trim( $raw ) );
}

/**
 * Reads portal_error query flag for generic UI messages (no token in value).
 */
function deoia_subscriptions_portal_get_portal_error_from_request(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only error code.
	if ( ! isset( $_GET['portal_error'] ) ) {
		return '';
	}

	$raw = wp_unslash( $_GET['portal_error'] );
	if ( ! is_string( $raw ) ) {
		return '';
	}

	return sanitize_key( $raw );
}

/**
 * Consumes magic-link token via backend (server-side only).
 *
 * @param string $token Plain access token from email link.
 * @return array{success: bool, session_token?: string, expires_at?: string}
 */
function deoia_subscriptions_portal_consume_magic_link( string $token ): array {
	$backend_url = deoia_subscriptions_backend_portal_consume_url();
	if ( $backend_url === null || $backend_url === '' ) {
		return array( 'success' => false );
	}

	$response = wp_remote_post(
		$backend_url,
		array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'token' => $token,
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return array( 'success' => false );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$raw  = (string) wp_remote_retrieve_body( $response );
	$data = json_decode( $raw, true );

	if ( $code !== 200 || ! is_array( $data ) || empty( $data['ok'] ) ) {
		return array( 'success' => false );
	}

	if (
		! isset( $data['session'] )
		|| ! is_array( $data['session'] )
		|| empty( $data['session']['token'] )
		|| ! is_string( $data['session']['token'] )
		|| empty( $data['session']['expires_at'] )
		|| ! is_string( $data['session']['expires_at'] )
	) {
		return array( 'success' => false );
	}

	return array(
		'success'       => true,
		'session_token' => $data['session']['token'],
		'expires_at'    => $data['session']['expires_at'],
	);
}

/**
 * Sets httpOnly portal session cookie from backend session payload.
 */
function deoia_subscriptions_portal_set_session_cookie( string $session_token, string $expires_at_iso ): bool {
	if ( $session_token === '' || $expires_at_iso === '' ) {
		return false;
	}

	$expires_ts = strtotime( $expires_at_iso );
	if ( $expires_ts === false || $expires_ts <= time() ) {
		return false;
	}

	$cookie_options = array(
		'expires'  => $expires_ts,
		'path'     => COOKIEPATH,
		'domain'   => COOKIE_DOMAIN,
		'secure'   => is_ssl(),
		'httponly' => true,
		'samesite' => 'Lax',
	);

	return setcookie( DEOIA_PORTAL_SESSION_COOKIE_NAME, $session_token, $cookie_options );
}

/**
 * Resolves a portal page URL via WordPress permalinks (preserves index.php when required).
 *
 * @param string $page_path     Path for get_page_by_path (e.g. account or account/access).
 * @param string $fallback_path Path passed to home_url when the page is not found.
 */
function deoia_subscriptions_portal_resolve_page_url( string $page_path, string $fallback_path ): string {
	$page = get_page_by_path( $page_path );
	if ( $page instanceof WP_Post ) {
		$permalink = get_permalink( $page );
		if ( is_string( $permalink ) && $permalink !== '' ) {
			return $permalink;
		}
	}

	return home_url( $fallback_path );
}

/**
 * Canonical /account/ URL for the portal.
 */
function deoia_subscriptions_portal_account_url(): string {
	return deoia_subscriptions_portal_resolve_page_url( 'account', '/account/' );
}

/**
 * Canonical /account/access/ URL for the portal.
 */
function deoia_subscriptions_portal_access_url(): string {
	return deoia_subscriptions_portal_resolve_page_url( 'account/access', '/account/access/' );
}

/**
 * Access URL with generic invalid-link error flag (no token in query).
 */
function deoia_subscriptions_portal_access_invalid_link_url(): string {
	return add_query_arg( 'portal_error', 'invalid_link', deoia_subscriptions_portal_access_url() );
}

/**
 * Redirects to access page with generic invalid-link error (no token in URL).
 */
function deoia_subscriptions_portal_redirect_invalid_link(): void {
	wp_safe_redirect( deoia_subscriptions_portal_access_invalid_link_url() );
	exit;
}

/**
 * template_redirect: consume magic link, set cookie, redirect before any output.
 */
function deoia_subscriptions_portal_handle_magic_link_verify(): void {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}

	if ( ! deoia_subscriptions_portal_is_verify_request() ) {
		return;
	}

	$token = deoia_subscriptions_portal_get_magic_link_token_from_request();
	if ( $token === '' ) {
		deoia_subscriptions_portal_redirect_invalid_link();
	}

	$result = deoia_subscriptions_portal_consume_magic_link( $token );
	if ( ! $result['success'] ) {
		deoia_subscriptions_portal_redirect_invalid_link();
	}

	$session_token = $result['session_token'] ?? '';
	$expires_at    = $result['expires_at'] ?? '';

	if ( ! deoia_subscriptions_portal_set_session_cookie( $session_token, $expires_at ) ) {
		deoia_subscriptions_portal_redirect_invalid_link();
	}

	wp_safe_redirect( deoia_subscriptions_portal_account_url() );
	exit;
}
