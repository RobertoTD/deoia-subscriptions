<?php
/**
 * Subscription-form privacy consent helpers (LFPDPPP first cycle).
 *
 * @package DeoiaSubscriptions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exact checkbox copy (must stay in sync with backend privacyConsentConstants).
 */
const DEOIA_SUBSCRIPTION_PRIVACY_CONSENT_TEXT = 'Manifiesto que he leído el Aviso de Privacidad Integral y consiento el tratamiento de mis datos personales para gestionar mi solicitud, crear y administrar mi cuenta y agenda, autenticar mi acceso, prestar y proteger el servicio y cumplir las demás finalidades necesarias descritas en el Aviso.';

/**
 * Whether the request affirms privacy consent.
 *
 * @param mixed $value Raw privacy_consent param.
 * @return bool
 */
function deoia_subscriptions_privacy_consent_is_true( $value ): bool {
	return true === $value || 1 === $value || '1' === $value || 'true' === $value;
}

/**
 * @param WP_REST_Request $request Request.
 * @return bool
 */
function deoia_subscriptions_request_has_privacy_consent( WP_REST_Request $request ): bool {
	return deoia_subscriptions_privacy_consent_is_true( $request->get_param( 'privacy_consent' ) );
}

/**
 * @param WP_REST_Request $request Request.
 * @return string
 */
function deoia_subscriptions_request_privacy_notice_version( WP_REST_Request $request ): string {
	return sanitize_text_field( trim( (string) $request->get_param( 'privacy_notice_version' ) ) );
}

/**
 * Public privacy notice metadata from the existing legal resolver.
 *
 * @param bool $refresh When true, bypass success cache and re-fetch.
 * @return array{locale: string, documentType: string, version: string, url: string}|null
 */
function deoia_subscriptions_resolve_privacy_notice_meta( bool $refresh = false ): ?array {
	if ( $refresh ) {
		delete_transient( deoia_subscriptions_legal_cache_key( 'privacy' ) );
	}

	$document = deoia_subscriptions_fetch_legal_document( 'privacy' );
	$url      = deoia_subscriptions_legal_document_url( 'privacy' );

	if ( $document === null || $url === null || $url === '' ) {
		return null;
	}

	return array(
		'locale'       => $document['locale'],
		'documentType' => $document['documentType'],
		'version'      => $document['version'],
		'url'          => $url,
	);
}

/**
 * REST args fragment for privacy consent fields.
 *
 * @return array<string, array<string, mixed>>
 */
function deoia_subscriptions_rest_privacy_consent_args(): array {
	return array(
		'privacy_consent'        => array(
			'required' => false,
		),
		'privacy_notice_version' => array(
			'type'              => 'string',
			'required'          => false,
			'sanitize_callback' => 'sanitize_text_field',
		),
	);
}

/**
 * Validate privacy consent before proxying to the backend.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|null Error response or null when valid.
 */
function deoia_subscriptions_rest_validate_privacy_consent( WP_REST_Request $request ): ?WP_REST_Response {
	if ( ! deoia_subscriptions_request_has_privacy_consent( $request ) ) {
		return new WP_REST_Response(
			array(
				'error' => 'privacy_consent_required',
			),
			400
		);
	}

	$version = deoia_subscriptions_request_privacy_notice_version( $request );
	if ( $version === '' || ! deoia_subscriptions_legal_version_is_valid( $version ) ) {
		return new WP_REST_Response(
			array(
				'error' => 'privacy_notice_version_invalid',
			),
			400
		);
	}

	return null;
}
