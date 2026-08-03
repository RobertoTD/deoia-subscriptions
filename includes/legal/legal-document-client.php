<?php
/**
 * Server-to-server fetch of public legal documents from the DEOIA backend.
 *
 * @package DeoiaSubscriptions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DEOIA_LEGAL_LOCALE = 'es-MX';

const DEOIA_LEGAL_DOCUMENT_TYPES = array( 'terms', 'privacy' );

const DEOIA_LEGAL_HTTP_TIMEOUT = 15;

const DEOIA_LEGAL_CACHE_TTL = 900; // 15 minutes.

const DEOIA_LEGAL_CACHE_PREFIX = 'deoia_legal_';

/**
 * Derive backend origin from DEOIA_SUBSCRIPTIONS_BACKEND_START_URL.
 *
 * @return string|null Origin without trailing slash, or null if misconfigured.
 */
function deoia_subscriptions_backend_origin_from_start_url(): ?string {
	if ( ! function_exists( 'deoia_subscriptions_backend_start_url_is_configured' ) ) {
		return null;
	}

	if ( ! deoia_subscriptions_backend_start_url_is_configured() ) {
		return null;
	}

	$start_url     = rtrim( (string) constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_START_URL' ), '/' );
	$suffix        = '/subscriptions/start';
	$suffix_length = strlen( $suffix );

	if ( strlen( $start_url ) <= $suffix_length || substr( $start_url, -$suffix_length ) !== $suffix ) {
		return null;
	}

	return substr( $start_url, 0, -$suffix_length );
}

/**
 * Absolute URL for a fixed legal document endpoint.
 *
 * @param string $document_type terms|privacy
 * @return string|null
 */
function deoia_subscriptions_legal_document_url( string $document_type ): ?string {
	if ( ! in_array( $document_type, DEOIA_LEGAL_DOCUMENT_TYPES, true ) ) {
		return null;
	}

	$origin = deoia_subscriptions_backend_origin_from_start_url();
	if ( $origin === null || $origin === '' ) {
		return null;
	}

	return $origin . '/legal/' . DEOIA_LEGAL_LOCALE . '/' . $document_type;
}

/**
 * @param string $document_type
 * @return string
 */
function deoia_subscriptions_legal_cache_key( string $document_type ): string {
	return DEOIA_LEGAL_CACHE_PREFIX . DEOIA_LEGAL_LOCALE . '_' . $document_type;
}

/**
 * Whether version matches YYYY-MM-DD.N (positive N, no leading zeros, real calendar date).
 *
 * @param string $version
 * @return bool
 */
function deoia_subscriptions_legal_version_is_valid( string $version ): bool {
	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})\.([1-9]\d*)$/', $version, $m ) ) {
		return false;
	}

	$year  = (int) $m[1];
	$month = (int) $m[2];
	$day   = (int) $m[3];

	return checkdate( $month, $day, $year );
}

/**
 * Validate a decoded backend payload for the expected document type.
 *
 * @param mixed  $data
 * @param string $expected_document_type
 * @return array{locale: string, documentType: string, version: string, content: string}|null
 */
function deoia_subscriptions_legal_validate_payload( $data, string $expected_document_type ): ?array {
	if ( ! is_array( $data ) ) {
		return null;
	}

	if ( ! isset( $data['ok'] ) || $data['ok'] !== true ) {
		return null;
	}

	if ( ! isset( $data['locale'] ) || ! is_string( $data['locale'] ) || $data['locale'] !== DEOIA_LEGAL_LOCALE ) {
		return null;
	}

	if (
		! isset( $data['documentType'] )
		|| ! is_string( $data['documentType'] )
		|| $data['documentType'] !== $expected_document_type
	) {
		return null;
	}

	if (
		! isset( $data['version'] )
		|| ! is_string( $data['version'] )
		|| ! deoia_subscriptions_legal_version_is_valid( $data['version'] )
	) {
		return null;
	}

	if ( ! isset( $data['content'] ) || ! is_string( $data['content'] ) || trim( $data['content'] ) === '' ) {
		return null;
	}

	return array(
		'locale'       => DEOIA_LEGAL_LOCALE,
		'documentType' => $expected_document_type,
		'version'      => $data['version'],
		'content'      => $data['content'],
	);
}

/**
 * Fetch and validate a legal document (with success-only transient cache).
 *
 * @param string $document_type terms|privacy
 * @return array{locale: string, documentType: string, version: string, content: string}|null
 */
function deoia_subscriptions_fetch_legal_document( string $document_type ): ?array {
	if ( ! in_array( $document_type, DEOIA_LEGAL_DOCUMENT_TYPES, true ) ) {
		return null;
	}

	$cache_key = deoia_subscriptions_legal_cache_key( $document_type );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		$validated_cache = deoia_subscriptions_legal_validate_payload(
			array(
				'ok'           => true,
				'locale'       => $cached['locale'] ?? null,
				'documentType' => $cached['documentType'] ?? null,
				'version'      => $cached['version'] ?? null,
				'content'      => $cached['content'] ?? null,
			),
			$document_type
		);
		if ( $validated_cache !== null ) {
			return $validated_cache;
		}
	}

	$url = deoia_subscriptions_legal_document_url( $document_type );
	if ( $url === null ) {
		return null;
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => DEOIA_LEGAL_HTTP_TIMEOUT,
			'headers' => array(
				'Accept' => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return null;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		return null;
	}

	$raw  = (string) wp_remote_retrieve_body( $response );
	$data = json_decode( $raw, true );
	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
		return null;
	}

	$validated = deoia_subscriptions_legal_validate_payload( $data, $document_type );
	if ( $validated === null ) {
		return null;
	}

	set_transient( $cache_key, $validated, DEOIA_LEGAL_CACHE_TTL );

	return $validated;
}
