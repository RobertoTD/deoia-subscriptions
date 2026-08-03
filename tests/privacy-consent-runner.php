<?php
/**
 * Privacy consent helpers + form/REST contract smoke (no Composer).
 *
 * Usage: php tests/privacy-consent-runner.php
 *
 * @package DeoiaSubscriptions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['deoia_test_assertions'] = 0;
$GLOBALS['deoia_test_failures']   = 0;
$GLOBALS['deoia_test_transients'] = array();
$GLOBALS['deoia_test_http_calls'] = array();
$GLOBALS['deoia_test_http_handler'] = null;
$GLOBALS['deoia_test_remote_posts'] = array();

/**
 * @param string   $label
 * @param callable $fn
 */
function deoia_test( string $label, callable $fn ): void {
	$GLOBALS['deoia_test_http_calls']   = array();
	$GLOBALS['deoia_test_transients']   = array();
	$GLOBALS['deoia_test_http_handler'] = null;
	$GLOBALS['deoia_test_remote_posts'] = array();

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
 * @param mixed  $actual
 * @param mixed  $expected
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

function __( $text, $domain = null ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( $text, $domain = null ) {
	return esc_html( $text );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return (string) $url;
}

function esc_url_raw( $url ) {
	return (string) $url;
}

function sanitize_text_field( $str ) {
	return trim( strip_tags( (string) $str ) );
}

function sanitize_email( $email ) {
	return trim( (string) $email );
}

function is_email( $email ) {
	return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

function get_transient( $key ) {
	return $GLOBALS['deoia_test_transients'][ $key ] ?? false;
}

function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['deoia_test_transients'][ $key ] = $value;
	return true;
}

function delete_transient( $key ) {
	unset( $GLOBALS['deoia_test_transients'][ $key ] );
	return true;
}

function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['deoia_test_http_calls'][] = array(
		'url'  => (string) $url,
		'args' => $args,
	);
	if ( is_callable( $GLOBALS['deoia_test_http_handler'] ) ) {
		return ( $GLOBALS['deoia_test_http_handler'] )( (string) $url, $args );
	}
	return array(
		'response' => array( 'code' => 500 ),
		'body'     => '',
	);
}

function wp_remote_retrieve_response_code( $response ) {
	return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_body( $response ) {
	return (string) ( $response['body'] ?? '' );
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

class WP_Error {
	/** @var string */
	public $code;
	/** @var string */
	public $message;

	public function __construct( string $code = '', string $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
}

class WP_REST_Request {
	/** @var array<string, mixed> */
	private $params;

	/**
	 * @param array<string, mixed> $params
	 */
	public function __construct( array $params = array() ) {
		$this->params = $params;
	}

	/**
	 * @param string $key
	 * @return mixed
	 */
	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}
}

class WP_REST_Response {
	/** @var mixed */
	public $data;
	/** @var int */
	public $status;

	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = (int) $status;
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
require_once dirname( __DIR__ ) . '/includes/legal/privacy-consent.php';

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

deoia_test(
	'privacy consent true helper accepts boolean/string forms only as documented',
	static function (): void {
		deoia_assert_true( deoia_subscriptions_privacy_consent_is_true( true ) );
		deoia_assert_true( deoia_subscriptions_privacy_consent_is_true( 1 ) );
		deoia_assert_true( deoia_subscriptions_privacy_consent_is_true( '1' ) );
		deoia_assert_true( deoia_subscriptions_privacy_consent_is_true( 'true' ) );
		deoia_assert_false( deoia_subscriptions_privacy_consent_is_true( false ) );
		deoia_assert_false( deoia_subscriptions_privacy_consent_is_true( 0 ) );
		deoia_assert_false( deoia_subscriptions_privacy_consent_is_true( 'false' ) );
		deoia_assert_false( deoia_subscriptions_privacy_consent_is_true( null ) );
	}
);

deoia_test(
	'REST validation rejects missing/false consent without forwarding',
	static function (): void {
		$res = deoia_subscriptions_rest_validate_privacy_consent( new WP_REST_Request( array() ) );
		deoia_assert_true( $res instanceof WP_REST_Response );
		deoia_assert_same( $res->status, 400 );
		deoia_assert_same( $res->data['error'] ?? null, 'privacy_consent_required' );

		$res2 = deoia_subscriptions_rest_validate_privacy_consent(
			new WP_REST_Request(
				array(
					'privacy_consent'        => false,
					'privacy_notice_version' => '2026-08-03.1',
				)
			)
		);
		deoia_assert_same( $res2->data['error'] ?? null, 'privacy_consent_required' );
	}
);

deoia_test(
	'REST validation rejects invalid privacy_notice_version',
	static function (): void {
		$res = deoia_subscriptions_rest_validate_privacy_consent(
			new WP_REST_Request(
				array(
					'privacy_consent'        => true,
					'privacy_notice_version' => 'not-a-version',
				)
			)
		);
		deoia_assert_same( $res->status, 400 );
		deoia_assert_same( $res->data['error'] ?? null, 'privacy_notice_version_invalid' );
	}
);

deoia_test(
	'REST validation accepts affirmative consent with valid version',
	static function (): void {
		$res = deoia_subscriptions_rest_validate_privacy_consent(
			new WP_REST_Request(
				array(
					'privacy_consent'        => true,
					'privacy_notice_version' => '2026-08-03.1',
				)
			)
		);
		deoia_assert_same( $res, null );
	}
);

deoia_test(
	'resolve privacy meta uses existing legal URL + version from resolver',
	static function (): void {
		$GLOBALS['deoia_test_http_handler'] = static function ( string $url ) {
			deoia_assert_same( $url, 'https://api.example.test/legal/es-MX/privacy' );
			return deoia_test_http_ok(
				array(
					'ok'           => true,
					'locale'       => 'es-MX',
					'documentType' => 'privacy',
					'version'      => '2026-08-03.1',
					'content'      => "Aviso\n\nVersión: 2026-08-03.1\n",
				)
			);
		};

		$meta = deoia_subscriptions_resolve_privacy_notice_meta( false );
		deoia_assert_true( is_array( $meta ) );
		deoia_assert_same( $meta['version'], '2026-08-03.1' );
		deoia_assert_same( $meta['url'], 'https://api.example.test/legal/es-MX/privacy' );
		deoia_assert_same( $meta['documentType'], 'privacy' );
	}
);

deoia_test(
	'refresh clears cache before re-fetch',
	static function (): void {
		set_transient(
			deoia_subscriptions_legal_cache_key( 'privacy' ),
			array(
				'locale'       => 'es-MX',
				'documentType' => 'privacy',
				'version'      => '2026-08-02.2',
				'content'      => 'old',
			),
			900
		);

		$calls = 0;
		$GLOBALS['deoia_test_http_handler'] = static function () use ( &$calls ) {
			$calls++;
			return deoia_test_http_ok(
				array(
					'ok'           => true,
					'locale'       => 'es-MX',
					'documentType' => 'privacy',
					'version'      => '2026-08-03.1',
					'content'      => 'new',
				)
			);
		};

		$meta = deoia_subscriptions_resolve_privacy_notice_meta( true );
		deoia_assert_same( $calls, 1 );
		deoia_assert_same( $meta['version'] ?? null, '2026-08-03.1' );
	}
);

deoia_test(
	'subscription form JS has no hard-coded privacy document version authority',
	static function (): void {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/subscription-form.js' );
		deoia_assert_contains( 'privacy_consent', $js );
		deoia_assert_contains( 'privacy_notice_version', $js );
		deoia_assert_contains( 'privacy_notice_version_outdated', $js );
		deoia_assert_contains( 'validatePrivacyConsentBeforeSubmit', $js );
		deoia_assert_contains( 'refreshPrivacyNoticeMeta', $js );
		deoia_assert_not_contains( "privacyNoticeVersion = '2026-08-03.1'", $js );
		deoia_assert_not_contains( 'privacy_notice_version: \'2026-08-03.1\'', $js );
	}
);

deoia_test(
	'form PHP template does not hard-code privacy version as permanent authority',
	static function (): void {
		$php = (string) file_get_contents( dirname( __DIR__ ) . '/deoia-subscriptions.php' );
		deoia_assert_contains( 'deoia-subscription-form__privacy', $php );
		deoia_assert_contains( 'deoia_subscriptions_resolve_privacy_notice_meta', $php );
		deoia_assert_contains( 'data-privacy-version', $php );
		deoia_assert_contains( 'privacy-notice-meta', $php );
		// Version may appear in comments/tests elsewhere, but markup must use resolved variable.
		deoia_assert_not_contains( 'data-privacy-version="2026-08-03.1"', $php );
		deoia_assert_contains( 'DEOIA_SUBSCRIPTION_PRIVACY_CONSENT_TEXT', $php );

		$consent_php = (string) file_get_contents( dirname( __DIR__ ) . '/includes/legal/privacy-consent.php' );
		deoia_assert_contains( 'Manifiesto que he leído el Aviso de Privacidad Integral', $consent_php );
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
