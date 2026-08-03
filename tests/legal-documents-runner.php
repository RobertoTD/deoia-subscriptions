<?php
/**
 * Lightweight runner for legal shortcode microcycle (no Composer).
 *
 * Usage: php tests/legal-documents-runner.php
 *
 * @package DeoiaSubscriptions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** @var array<string, callable> */
$GLOBALS['deoia_test_shortcodes'] = array();

/** @var array<string, mixed> */
$GLOBALS['deoia_test_transients'] = array();

/** @var list<array{url: string, args: array<string, mixed>}> */
$GLOBALS['deoia_test_http_calls'] = array();

/** @var callable|null */
$GLOBALS['deoia_test_http_handler'] = null;

/** @var int */
$GLOBALS['deoia_test_assertions'] = 0;

/** @var int */
$GLOBALS['deoia_test_failures'] = 0;

/**
 * @param string   $label
 * @param callable $fn
 */
function deoia_test( string $label, callable $fn ): void {
	$GLOBALS['deoia_test_http_calls'] = array();
	$GLOBALS['deoia_test_transients'] = array();
	$GLOBALS['deoia_test_http_handler'] = null;

	try {
		$fn();
		echo "PASS  {$label}\n";
	} catch ( Throwable $e ) {
		$GLOBALS['deoia_test_failures']++;
		echo "FAIL  {$label}\n";
		echo '      ' . $e->getMessage() . "\n";
	}
}

/**
 * @param mixed $actual
 * @param mixed $expected
 * @param string $message
 */
function deoia_assert_same( $actual, $expected, string $message = '' ): void {
	$GLOBALS['deoia_test_assertions']++;
	if ( $actual !== $expected ) {
		$prefix = $message !== '' ? $message . ': ' : '';
		throw new RuntimeException(
			$prefix . 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true )
		);
	}
}

/**
 * @param mixed  $value
 * @param string $message
 */
function deoia_assert_true( $value, string $message = '' ): void {
	$GLOBALS['deoia_test_assertions']++;
	if ( $value !== true ) {
		throw new RuntimeException( $message !== '' ? $message : 'expected true' );
	}
}

/**
 * @param mixed  $value
 * @param string $message
 */
function deoia_assert_false( $value, string $message = '' ): void {
	$GLOBALS['deoia_test_assertions']++;
	if ( $value !== false ) {
		throw new RuntimeException( $message !== '' ? $message : 'expected false' );
	}
}

/**
 * @param string $needle
 * @param string $haystack
 * @param string $message
 */
function deoia_assert_contains( string $needle, string $haystack, string $message = '' ): void {
	$GLOBALS['deoia_test_assertions']++;
	if ( strpos( $haystack, $needle ) === false ) {
		$prefix = $message !== '' ? $message . ': ' : '';
		throw new RuntimeException( $prefix . "missing substring: {$needle}" );
	}
}

/**
 * @param string $needle
 * @param string $haystack
 * @param string $message
 */
function deoia_assert_not_contains( string $needle, string $haystack, string $message = '' ): void {
	$GLOBALS['deoia_test_assertions']++;
	if ( strpos( $haystack, $needle ) !== false ) {
		$prefix = $message !== '' ? $message . ': ' : '';
		throw new RuntimeException( $prefix . "unexpected substring: {$needle}" );
	}
}

function add_shortcode( string $tag, $callback ): void {
	$GLOBALS['deoia_test_shortcodes'][ $tag ] = $callback;
}

function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function esc_attr( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

/**
 * Minimal make_clickable for http(s) bare URLs.
 *
 * @param string $text
 * @return string
 */
function make_clickable( string $text ): string {
	return (string) preg_replace_callback(
		'~https?://[^\s<]+~i',
		static function ( array $m ): string {
			$url = $m[0];
			return '<a href="' . esc_attr( $url ) . '" rel="nofollow">' . esc_html( $url ) . '</a>';
		},
		$text
	);
}

/**
 * Strip disallowed tags; keep semantic legal markup. Must neutralize real <script>.
 *
 * @param string $content
 * @return string
 */
function wp_kses_post( string $content ): string {
	$without_scripts = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $content );
	if ( ! is_string( $without_scripts ) ) {
		$without_scripts = '';
	}

	$allowed = '<h1><h2><h3><p><a><br><em><strong>';
	$stripped = strip_tags( $without_scripts, $allowed );
	return is_string( $stripped ) ? $stripped : '';
}

/**
 * @param string $key
 * @return mixed
 */
function get_transient( string $key ) {
	if ( ! array_key_exists( $key, $GLOBALS['deoia_test_transients'] ) ) {
		return false;
	}
	$entry = $GLOBALS['deoia_test_transients'][ $key ];
	if ( ! is_array( $entry ) ) {
		return false;
	}
	if ( isset( $entry['expires'] ) && is_int( $entry['expires'] ) && $entry['expires'] < time() ) {
		unset( $GLOBALS['deoia_test_transients'][ $key ] );
		return false;
	}
	return $entry['value'] ?? false;
}

/**
 * @param string $key
 * @param mixed  $value
 * @param int    $expiration
 * @return bool
 */
function set_transient( string $key, $value, int $expiration = 0 ): bool {
	$GLOBALS['deoia_test_transients'][ $key ] = array(
		'value'   => $value,
		'expires' => $expiration > 0 ? time() + $expiration : PHP_INT_MAX,
	);
	return true;
}

/**
 * @param string               $url
 * @param array<string, mixed> $args
 * @return array<string, mixed>|WP_Error
 */
function wp_remote_get( string $url, array $args = array() ) {
	$GLOBALS['deoia_test_http_calls'][] = array(
		'url'  => $url,
		'args' => $args,
	);

	$handler = $GLOBALS['deoia_test_http_handler'];
	if ( ! is_callable( $handler ) ) {
		return new WP_Error( 'no_handler', 'HTTP handler not configured' );
	}

	return $handler( $url, $args );
}

/**
 * @param array<string, mixed>|WP_Error $response
 * @return int|string
 */
function wp_remote_retrieve_response_code( $response ) {
	if ( $response instanceof WP_Error ) {
		return 0;
	}
	return $response['response']['code'] ?? 0;
}

/**
 * @param array<string, mixed>|WP_Error $response
 * @return string
 */
function wp_remote_retrieve_body( $response ): string {
	if ( $response instanceof WP_Error ) {
		return '';
	}
	return isset( $response['body'] ) && is_string( $response['body'] ) ? $response['body'] : '';
}

/**
 * @param mixed $thing
 * @return bool
 */
function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

final class WP_Error {
	/** @var string */
	public $code;

	/** @var string */
	public $message;

	public function __construct( string $code = '', string $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
}

function deoia_subscriptions_backend_start_url_is_configured(): bool {
	return defined( 'DEOIA_SUBSCRIPTIONS_BACKEND_START_URL' )
		&& is_string( constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_START_URL' ) )
		&& constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_START_URL' ) !== '';
}

if ( ! defined( 'DEOIA_SUBSCRIPTIONS_BACKEND_START_URL' ) ) {
	define( 'DEOIA_SUBSCRIPTIONS_BACKEND_START_URL', 'https://api.example.test/subscriptions/start' );
}

require_once dirname( __DIR__ ) . '/includes/legal/legal-document-client.php';
require_once dirname( __DIR__ ) . '/includes/legal/legal-document-render.php';
require_once dirname( __DIR__ ) . '/includes/legal/legal-document-shortcodes.php';

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function deoia_test_http_ok( array $payload ): array {
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => (string) json_encode( $payload ),
	);
}

$fixture_content = <<<'TXT'
Términos y condiciones
Términos y Condiciones de Uso de DEOIA Citas

Última actualización: 2 de agosto de 2026
Versión: 2026-08-02.2

1. Identidad del proveedor
DEOIA Citas es el nombre comercial del proveedor.

Sitio web: https://deoia.com
TXT;

deoia_test(
	'registers both shortcodes',
	static function (): void {
		deoia_assert_true( isset( $GLOBALS['deoia_test_shortcodes']['deoia_legal_terms'] ) );
		deoia_assert_true( isset( $GLOBALS['deoia_test_shortcodes']['deoia_legal_privacy'] ) );
	}
);

deoia_test(
	'terms shortcode requests /legal/es-MX/terms',
	static function () use ( $fixture_content ): void {
		$GLOBALS['deoia_test_http_handler'] = static function ( string $url, array $args ) use ( $fixture_content ) {
			deoia_assert_same( $url, 'https://api.example.test/legal/es-MX/terms' );
			deoia_assert_same( $args['timeout'] ?? null, 15 );
			deoia_assert_same( $args['headers']['Accept'] ?? null, 'application/json' );
			return deoia_test_http_ok(
				array(
					'ok'           => true,
					'locale'       => 'es-MX',
					'documentType' => 'terms',
					'version'      => '2026-08-02.2',
					'content'      => $fixture_content,
				)
			);
		};

		$html = deoia_subscriptions_render_legal_terms_shortcode();
		deoia_assert_same( count( $GLOBALS['deoia_test_http_calls'] ), 1 );
		deoia_assert_contains( 'deoia-legal-document', $html );
		deoia_assert_contains( 'data-legal-type="terms"', $html );
	}
);

deoia_test(
	'privacy shortcode requests /legal/es-MX/privacy',
	static function (): void {
		$GLOBALS['deoia_test_http_handler'] = static function ( string $url ) {
			deoia_assert_same( $url, 'https://api.example.test/legal/es-MX/privacy' );
			return deoia_test_http_ok(
				array(
					'ok'           => true,
					'locale'       => 'es-MX',
					'documentType' => 'privacy',
					'version'      => '2026-08-02.2',
					'content'      => "Política de Privacidad\n\n1. Identidad\nTexto.",
				)
			);
		};

		$html = deoia_subscriptions_render_legal_privacy_shortcode();
		deoia_assert_contains( 'data-legal-type="privacy"', $html );
		deoia_assert_same( $GLOBALS['deoia_test_http_calls'][0]['url'], 'https://api.example.test/legal/es-MX/privacy' );
	}
);

deoia_test(
	'renders title, sections, paragraphs, bare URL; no ordered lists',
	static function () use ( $fixture_content ): void {
		$html = deoia_subscriptions_render_legal_document_html(
			array(
				'locale'       => 'es-MX',
				'documentType' => 'terms',
				'version'      => '2026-08-02.2',
				'content'      => $fixture_content,
			)
		);

		deoia_assert_contains( '<h1>Términos y condiciones</h1>', $html );
		deoia_assert_contains( '<h2>1. Identidad del proveedor</h2>', $html );
		deoia_assert_contains( '<p>DEOIA Citas es el nombre comercial del proveedor.</p>', $html );
		deoia_assert_contains( '<a href="https://deoia.com"', $html );
		deoia_assert_not_contains( '<ol', $html );
		deoia_assert_not_contains( '<li', $html );
		deoia_assert_contains( 'data-legal-version="2026-08-02.2"', $html );
		deoia_assert_contains( '>Versión: 2026-08-02.2</p>', $html );
		// No invented visible badge beyond the body line.
		deoia_assert_false( (bool) preg_match( '/Versión:\s*2026-08-02\.2.*Versión:\s*2026-08-02\.2/s', $html ) );
	}
);

deoia_test(
	'neutralizes executable script tags from content',
	static function (): void {
		$html = deoia_subscriptions_render_legal_document_html(
			array(
				'locale'       => 'es-MX',
				'documentType' => 'terms',
				'version'      => '2026-08-02.2',
				'content'      => "Título seguro\n\n<script>alert(1)</script>\nTexto final.",
			)
		);

		deoia_assert_not_contains( '<script', strtolower( $html ) );
		deoia_assert_not_contains( '</script>', strtolower( $html ) );
		deoia_assert_contains( 'alert(1)', $html ); // escaped text may remain as entities/text
		deoia_assert_contains( '&lt;script&gt;', $html );
	}
);

deoia_test(
	'fallback on HTTP, invalid JSON, and invalid contract; errors are not cached',
	static function (): void {
		$GLOBALS['deoia_test_http_handler'] = static function () {
			return array(
				'response' => array( 'code' => 500 ),
				'body'     => '{"ok":false}',
			);
		};
		$html = deoia_subscriptions_render_legal_terms_shortcode();
		deoia_assert_contains( DEOIA_LEGAL_FALLBACK_MESSAGE, $html );
		deoia_assert_false( get_transient( deoia_subscriptions_legal_cache_key( 'terms' ) ) !== false );

		$GLOBALS['deoia_test_http_calls']   = array();
		$GLOBALS['deoia_test_http_handler'] = static function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => 'not-json',
			);
		};
		$html = deoia_subscriptions_render_legal_terms_shortcode();
		deoia_assert_contains( DEOIA_LEGAL_FALLBACK_MESSAGE, $html );
		deoia_assert_false( isset( $GLOBALS['deoia_test_transients'][ deoia_subscriptions_legal_cache_key( 'terms' ) ] ) );

		$GLOBALS['deoia_test_http_handler'] = static function () {
			return deoia_test_http_ok(
				array(
					'ok'           => true,
					'locale'       => 'es-MX',
					'documentType' => 'privacy', // mismatch for terms shortcode
					'version'      => '2026-08-02.2',
					'content'      => 'x',
				)
			);
		};
		$html = deoia_subscriptions_render_legal_terms_shortcode();
		deoia_assert_contains( DEOIA_LEGAL_FALLBACK_MESSAGE, $html );
		deoia_assert_false( isset( $GLOBALS['deoia_test_transients'][ deoia_subscriptions_legal_cache_key( 'terms' ) ] ) );
	}
);

deoia_test(
	'caches only successful fetches',
	static function () use ( $fixture_content ): void {
		$calls = 0;
		$GLOBALS['deoia_test_http_handler'] = static function () use ( &$calls, $fixture_content ) {
			$calls++;
			return deoia_test_http_ok(
				array(
					'ok'           => true,
					'locale'       => 'es-MX',
					'documentType' => 'terms',
					'version'      => '2026-08-02.2',
					'content'      => $fixture_content,
				)
			);
		};

		$html1 = deoia_subscriptions_render_legal_terms_shortcode();
		$html2 = deoia_subscriptions_render_legal_terms_shortcode();
		deoia_assert_same( $calls, 1 );
		deoia_assert_contains( 'data-legal-version="2026-08-02.2"', $html1 );
		deoia_assert_contains( 'data-legal-version="2026-08-02.2"', $html2 );
		deoia_assert_true( is_array( get_transient( deoia_subscriptions_legal_cache_key( 'terms' ) ) ) );
	}
);

deoia_test(
	'no client-side JavaScript or fetch helpers in legal modules',
	static function (): void {
		$dir = dirname( __DIR__ ) . '/includes/legal';
		foreach ( array( 'legal-document-client.php', 'legal-document-render.php', 'legal-document-shortcodes.php' ) as $file ) {
			$src = (string) file_get_contents( $dir . '/' . $file );
			deoia_assert_not_contains( 'wp_enqueue_script', $src, $file );
			deoia_assert_not_contains( 'fetch(', $src, $file );
			deoia_assert_not_contains( '<script', $src, $file );
		}
	}
);

echo "\n";
echo 'Assertions: ' . (int) $GLOBALS['deoia_test_assertions'] . "\n";
echo 'Failures:   ' . (int) $GLOBALS['deoia_test_failures'] . "\n";

if ( (int) $GLOBALS['deoia_test_failures'] > 0 ) {
	exit( 1 );
}

echo "OK\n";
exit( 0 );
