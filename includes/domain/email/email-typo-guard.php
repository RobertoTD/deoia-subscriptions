<?php
/**
 * Detects likely consumer-email typos for the public subscription form.
 *
 * @package DeoiaSubscriptions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return array{common_providers: string[], typo_blocklist: array<string, string>}
 */
function deoia_subscriptions_email_typo_guard_data(): array {
	static $cached = null;

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$path = DEOIA_SUBSCRIPTIONS_DIR . 'includes/domain/email/common-email-domains.json';
	if ( ! is_readable( $path ) ) {
		$cached = array(
			'common_providers' => array(),
			'typo_blocklist'   => array(),
		);
		return $cached;
	}

	$raw  = file_get_contents( $path );
	$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
	if ( ! is_array( $data ) ) {
		$cached = array(
			'common_providers' => array(),
			'typo_blocklist'   => array(),
		);
		return $cached;
	}

	$cached = array(
		'common_providers' => array_values(
			array_filter(
				array_map( 'strval', $data['common_providers'] ?? array() ),
				static fn( string $domain ): bool => $domain !== ''
			)
		),
		'typo_blocklist'   => is_array( $data['typo_blocklist'] ?? null ) ? $data['typo_blocklist'] : array(),
	);

	return $cached;
}

/**
 * @param string $left
 * @param string $right
 */
function deoia_subscriptions_email_levenshtein_distance( string $left, string $right ): int {
	if ( function_exists( 'levenshtein' ) ) {
		return levenshtein( $left, $right );
	}

	$rows = strlen( $left ) + 1;
	$cols = strlen( $right ) + 1;
	$matrix = array();

	for ( $i = 0; $i < $rows; $i++ ) {
		$matrix[ $i ] = array_fill( 0, $cols, 0 );
		$matrix[ $i ][0] = $i;
	}

	for ( $j = 0; $j < $cols; $j++ ) {
		$matrix[0][ $j ] = $j;
	}

	for ( $i = 1; $i < $rows; $i++ ) {
		for ( $j = 1; $j < $cols; $j++ ) {
			$cost = $left[ $i - 1 ] === $right[ $j - 1 ] ? 0 : 1;
			$matrix[ $i ][ $j ] = min(
				$matrix[ $i - 1 ][ $j ] + 1,
				$matrix[ $i ][ $j - 1 ] + 1,
				$matrix[ $i - 1 ][ $j - 1 ] + $cost
			);
		}
	}

	return (int) $matrix[ $rows - 1 ][ $cols - 1 ];
}

/**
 * @param string $raw
 * @return array{email: string, local: string, domain: string}|null
 */
function deoia_subscriptions_parse_subscription_email( string $raw ): ?array {
	$email = strtolower( trim( $raw ) );
	$at    = strrpos( $email, '@' );
	if ( $at === false || $at <= 0 || $at >= strlen( $email ) - 1 ) {
		return null;
	}

	$local  = substr( $email, 0, $at );
	$domain = substr( $email, $at + 1 );
	if ( $local === '' || $domain === '' || strpos( $domain, '.' ) === false ) {
		return null;
	}

	return array(
		'email'  => $email,
		'local'  => $local,
		'domain' => $domain,
	);
}

/**
 * @param string $local
 * @param string $domain
 * @param string $suggested_domain
 * @return array{ok: false, error: string, detected_domain: string, suggested_domain: string, suggested_email: string}
 */
function deoia_subscriptions_build_email_typo_failure( string $local, string $domain, string $suggested_domain ): array {
	return array(
		'ok'               => false,
		'error'            => 'email_typo_suspected',
		'detected_domain'  => $domain,
		'suggested_domain' => $suggested_domain,
		'suggested_email'  => $local . '@' . $suggested_domain,
	);
}

/**
 * @param string $email
 * @return array{ok: true}|array{ok: false, error: string, detected_domain: string, suggested_domain: string, suggested_email: string}
 */
function deoia_subscriptions_validate_subscription_email( string $email ): array {
	$parsed = deoia_subscriptions_parse_subscription_email( $email );
	if ( $parsed === null ) {
		return array( 'ok' => true );
	}

	$data               = deoia_subscriptions_email_typo_guard_data();
	$common_providers   = $data['common_providers'];
	$typo_blocklist     = $data['typo_blocklist'];
	$local              = $parsed['local'];
	$domain             = $parsed['domain'];

	if ( in_array( $domain, $common_providers, true ) ) {
		return array( 'ok' => true );
	}

	if ( isset( $typo_blocklist[ $domain ] ) && is_string( $typo_blocklist[ $domain ] ) ) {
		return deoia_subscriptions_build_email_typo_failure( $local, $domain, $typo_blocklist[ $domain ] );
	}

	foreach ( $common_providers as $provider ) {
		$distance = deoia_subscriptions_email_levenshtein_distance( $domain, $provider );
		if ( $distance <= 1 && abs( strlen( $domain ) - strlen( $provider ) ) <= 1 ) {
			return deoia_subscriptions_build_email_typo_failure( $local, $domain, $provider );
		}
	}

	return array( 'ok' => true );
}

/**
 * @param array{suggested_domain?: string|null} $result
 */
function deoia_subscriptions_email_typo_user_message( array $result ): string {
	$suggested_domain = isset( $result['suggested_domain'] ) ? (string) $result['suggested_domain'] : '';
	if ( $suggested_domain !== '' ) {
		return sprintf(
			/* translators: %s: suggested email domain such as gmail.com */
			__( 'Revisa tu correo electrónico. Parece que escribiste un dominio incorrecto. ¿Quisiste decir @%s? Corrige tu correo para continuar.', 'deoia-subscriptions' ),
			$suggested_domain
		);
	}

	return __( 'Revisa tu correo electrónico. El dominio parece un error de escritura. Verifica que sea el correo correcto antes de continuar.', 'deoia-subscriptions' );
}

/**
 * @param array{ok: false, error: string, detected_domain?: string, suggested_domain?: string, suggested_email?: string} $result
 * @param int                                                                                                           $status
 * @return WP_REST_Response
 */
function deoia_subscriptions_rest_email_typo_error( array $result, int $status = 400 ): WP_REST_Response {
	return new WP_REST_Response(
		array(
			'error'            => 'email_typo_suspected',
			'message'          => deoia_subscriptions_email_typo_user_message( $result ),
			'suggested_email'  => $result['suggested_email'] ?? null,
			'suggested_domain' => $result['suggested_domain'] ?? null,
			'detected_domain'  => $result['detected_domain'] ?? null,
		),
		$status
	);
}
