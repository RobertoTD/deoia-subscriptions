<?php
/**
 * Plain-prose legal document → semantic HTML (not a Markdown parser).
 *
 * @package DeoiaSubscriptions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DEOIA_LEGAL_FALLBACK_MESSAGE = 'No pudimos cargar este documento en este momento. Inténtalo de nuevo más tarde.';

/**
 * Public fallback HTML when the document cannot be loaded.
 *
 * @return string
 */
function deoia_subscriptions_legal_document_fallback_html(): string {
	return '<p class="deoia-legal-document__unavailable">' . esc_html( DEOIA_LEGAL_FALLBACK_MESSAGE ) . '</p>';
}

/**
 * Whether a line is a numbered section heading (not an ordered-list item to invent).
 *
 * @param string $line
 * @return bool
 */
function deoia_subscriptions_legal_line_is_section_heading( string $line ): bool {
	return (bool) preg_match( '/^\d+\.\s+\S.*$/u', $line );
}

/**
 * Escape a line and turn bare http(s) URLs into anchors.
 *
 * @param string $line Raw line.
 * @return string Safe HTML fragment (text + optional <a>).
 */
function deoia_subscriptions_legal_format_line_html( string $line ): string {
	$escaped = esc_html( $line );
	if ( function_exists( 'make_clickable' ) ) {
		return (string) make_clickable( $escaped );
	}
	return $escaped;
}

/**
 * Convert validated legal prose into semantic HTML inside the article wrapper.
 *
 * @param array{locale: string, documentType: string, version: string, content: string} $document
 * @return string
 */
function deoia_subscriptions_render_legal_document_html( array $document ): string {
	$locale        = isset( $document['locale'] ) && is_string( $document['locale'] ) ? $document['locale'] : '';
	$document_type = isset( $document['documentType'] ) && is_string( $document['documentType'] ) ? $document['documentType'] : '';
	$version       = isset( $document['version'] ) && is_string( $document['version'] ) ? $document['version'] : '';
	$content       = isset( $document['content'] ) && is_string( $document['content'] ) ? $document['content'] : '';

	if (
		$locale === ''
		|| $document_type === ''
		|| $version === ''
		|| trim( $content ) === ''
		|| ! function_exists( 'deoia_subscriptions_legal_version_is_valid' )
		|| ! deoia_subscriptions_legal_version_is_valid( $version )
	) {
		return deoia_subscriptions_legal_document_fallback_html();
	}

	$normalized = str_replace( array( "\r\n", "\r" ), "\n", $content );
	$lines      = explode( "\n", $normalized );
	$parts      = array();
	$saw_title  = false;

	foreach ( $lines as $raw_line ) {
		$line = trim( $raw_line );
		if ( $line === '' ) {
			continue;
		}

		if ( ! $saw_title ) {
			$parts[]   = '<h1>' . deoia_subscriptions_legal_format_line_html( $line ) . '</h1>';
			$saw_title = true;
			continue;
		}

		if ( deoia_subscriptions_legal_line_is_section_heading( $line ) ) {
			$parts[] = '<h2>' . deoia_subscriptions_legal_format_line_html( $line ) . '</h2>';
			continue;
		}

		$parts[] = '<p>' . deoia_subscriptions_legal_format_line_html( $line ) . '</p>';
	}

	if ( $parts === array() ) {
		return deoia_subscriptions_legal_document_fallback_html();
	}

	$inner = wp_kses_post( implode( "\n", $parts ) );

	return sprintf(
		'<article class="deoia-legal-document" data-legal-locale="%1$s" data-legal-type="%2$s" data-legal-version="%3$s">%4$s</article>',
		esc_attr( $locale ),
		esc_attr( $document_type ),
		esc_attr( $version ),
		$inner
	);
}
