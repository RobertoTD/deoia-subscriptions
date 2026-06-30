<?php
/**
 * Anti-abuse guards for the Freemium subscription flow (honeypot + daily rate limit).
 *
 * @package DeoiaSubscriptions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DEOIA_FREEMIUM_RL_DAILY_LIMIT        = 2;
const DEOIA_FREEMIUM_RL_TRANSIENT_PREFIX = 'deoia_freemium_rl_';

/**
 * Client IP for rate limiting (MVP: REMOTE_ADDR only).
 */
function deoia_subscriptions_get_client_ip(): string {
	if ( ! isset( $_SERVER['REMOTE_ADDR'] ) || ! is_string( $_SERVER['REMOTE_ADDR'] ) ) {
		return 'unknown';
	}

	$ip = trim( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	if ( $ip !== '' && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return $ip;
	}

	return 'unknown';
}

/**
 * Calendar day key in the site timezone (Ymd).
 */
function deoia_subscriptions_freemium_rl_day_key(): string {
	return wp_date( 'Ymd' );
}

/**
 * Seconds until local midnight (transient TTL for daily counters).
 */
function deoia_subscriptions_freemium_rl_seconds_until_midnight(): int {
	$tz       = wp_timezone();
	$now      = new DateTimeImmutable( 'now', $tz );
	$midnight = $now->modify( 'tomorrow' )->setTime( 0, 0, 0 );
	$seconds  = $midnight->getTimestamp() - $now->getTimestamp();

	return max( 1, (int) $seconds );
}

/**
 * Builds a daily transient key for IP or email scope.
 *
 * @param string $scope      "ip" or "email".
 * @param string $identifier Raw IP or normalized email.
 */
function deoia_subscriptions_freemium_rl_transient_key( string $scope, string $identifier ): string {
	return DEOIA_FREEMIUM_RL_TRANSIENT_PREFIX
		. $scope . '_'
		. md5( $identifier ) . '_'
		. deoia_subscriptions_freemium_rl_day_key();
}

/**
 * Normalizes email for rate-limit keys.
 */
function deoia_subscriptions_freemium_normalize_email( string $email ): string {
	return strtolower( trim( $email ) );
}

/**
 * Current successful-provisioning count for a scope/identifier today.
 */
function deoia_subscriptions_freemium_rl_get_count( string $scope, string $identifier ): int {
	$key   = deoia_subscriptions_freemium_rl_transient_key( $scope, $identifier );
	$count = get_transient( $key );

	return is_numeric( $count ) ? (int) $count : 0;
}

/**
 * Whether IP or email has reached the daily Freemium limit.
 */
function deoia_subscriptions_freemium_rl_is_limited( string $ip, string $email ): bool {
	$limit = DEOIA_FREEMIUM_RL_DAILY_LIMIT;

	if ( deoia_subscriptions_freemium_rl_get_count( 'ip', $ip ) >= $limit ) {
		return true;
	}

	$normalized_email = deoia_subscriptions_freemium_normalize_email( $email );
	if ( $normalized_email !== '' && deoia_subscriptions_freemium_rl_get_count( 'email', $normalized_email ) >= $limit ) {
		return true;
	}

	return false;
}

/**
 * Increments daily counters after a successful Freemium provisioning.
 */
function deoia_subscriptions_freemium_rl_record_success( string $ip, string $email ): void {
	$ttl = deoia_subscriptions_freemium_rl_seconds_until_midnight();

	$scopes = array(
		'ip'    => $ip,
		'email' => deoia_subscriptions_freemium_normalize_email( $email ),
	);

	foreach ( $scopes as $scope => $identifier ) {
		if ( $identifier === '' ) {
			continue;
		}

		$key   = deoia_subscriptions_freemium_rl_transient_key( $scope, $identifier );
		$count = deoia_subscriptions_freemium_rl_get_count( $scope, $identifier );
		set_transient( $key, $count + 1, $ttl );
	}
}

/**
 * User-facing message when the daily Freemium limit is exceeded.
 */
function deoia_subscriptions_freemium_rl_rate_limit_message(): string {
	return __( 'Por seguridad, alcanzaste el límite de agendas gratuitas por hoy. Intenta más tarde o elige PRO.', 'deoia-subscriptions' );
}

/**
 * Whether the honeypot field was filled (bot signal).
 */
function deoia_subscriptions_freemium_honeypot_is_triggered( WP_REST_Request $request ): bool {
	$honeypot = trim( (string) $request->get_param( 'website' ) );

	return $honeypot !== '';
}

/**
 * Fake success response for honeypot triggers (no backend call, no provisioning).
 */
function deoia_subscriptions_freemium_fake_success_response(): WP_REST_Response {
	return new WP_REST_Response(
		array(
			'ok'           => true,
			'status'       => 'provisioning_started',
			'redirect_url' => deoia_subscriptions_resolve_thank_you_page_url(),
		),
		200
	);
}
