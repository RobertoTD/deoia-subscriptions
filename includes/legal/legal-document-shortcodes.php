<?php
/**
 * Public shortcodes for legal documents.
 *
 * [deoia_legal_terms]
 * [deoia_legal_privacy]
 *
 * @package DeoiaSubscriptions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared shortcode renderer for a fixed document type.
 *
 * @param string $document_type terms|privacy
 * @return string
 */
function deoia_subscriptions_render_legal_document_shortcode( string $document_type ): string {
	$document = deoia_subscriptions_fetch_legal_document( $document_type );
	if ( $document === null ) {
		return deoia_subscriptions_legal_document_fallback_html();
	}

	return deoia_subscriptions_render_legal_document_html( $document );
}

/**
 * @return string
 */
function deoia_subscriptions_render_legal_terms_shortcode(): string {
	return deoia_subscriptions_render_legal_document_shortcode( 'terms' );
}

/**
 * @return string
 */
function deoia_subscriptions_render_legal_privacy_shortcode(): string {
	return deoia_subscriptions_render_legal_document_shortcode( 'privacy' );
}

add_shortcode( 'deoia_legal_terms', 'deoia_subscriptions_render_legal_terms_shortcode' );
add_shortcode( 'deoia_legal_privacy', 'deoia_subscriptions_render_legal_privacy_shortcode' );
