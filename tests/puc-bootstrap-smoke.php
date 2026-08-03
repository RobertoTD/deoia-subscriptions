<?php
/**
 * Smoke: load plugin bootstrap (incl. PUC) without a full WordPress install.
 *
 * Usage: php tests/puc-bootstrap-smoke.php
 *
 * @package DeoiaSubscriptions
 */

declare(strict_types=1);

$failures = 0;

function deoia_puc_smoke_fail( string $message ): void {
	global $failures;
	$failures++;
	fwrite( STDERR, "FAIL  {$message}\n" );
}

function deoia_puc_smoke_pass( string $message ): void {
	echo "PASS  {$message}\n";
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() );
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( string $file ): string {
		return trailingslashit( dirname( $file ) );
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( string $file ): string {
		return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( string $value ): string {
		return rtrim( $value, '/\\' );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}

if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( $tag, $callback ) {
		return true;
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( string $path ): string {
		return str_replace( '\\', '/', $path );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( '_x' ) ) {
	function _x( string $text, string $context, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string {
		return $url;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return $url;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return is_string( $str ) ? trim( $str ) : '';
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		return is_string( $email ) ? trim( $email ) : '';
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return is_string( $key ) ? preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) : '';
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return is_string( $email ) && filter_var( $email, FILTER_VALIDATE_EMAIL );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		return $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) {
		return true;
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	function get_site_option( $option, $default = false ) {
		return $default;
	}
}

if ( ! function_exists( 'update_site_option' ) ) {
	function update_site_option( $option, $value ) {
		return true;
	}
}

if ( ! function_exists( 'delete_site_option' ) ) {
	function delete_site_option( $option ) {
		return true;
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		return false;
	}
}

if ( ! function_exists( 'wp_get_schedule' ) ) {
	function wp_get_schedule( $hook, $args = array() ) {
		return false;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) {
		return false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
		return true;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
		return true;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook, $args = array() ) {
		return true;
	}
}

if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event( $timestamp, $hook, $args = array() ) {
		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		return null;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		return $value;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return false;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '', $filter = 'raw' ) {
		return '6.4';
	}
}

if ( ! function_exists( 'get_site_transient' ) ) {
	function get_site_transient( $transient ) {
		return false;
	}
}

if ( ! function_exists( 'set_site_transient' ) ) {
	function set_site_transient( $transient, $value, $expiration = 0 ) {
		return true;
	}
}

if ( ! function_exists( 'delete_site_transient' ) ) {
	function delete_site_transient( $transient ) {
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $transient ) {
		return false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ) {
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $transient ) {
		return true;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '', $scheme = null ) {
		return 'https://example.test/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '', $scheme = 'admin' ) {
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $path = '', $plugin = '' ) {
		return 'https://example.test/wp-content/plugins/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '', $scheme = 'rest' ) {
		return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return 'test-nonce';
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		return new WP_Error( 'http_request_failed', 'Network unavailable (smoke stub)' );
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		return new WP_Error( 'http_request_failed', 'Network unavailable (smoke stub)' );
	}
}

if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ) {
		return new WP_Error( 'http_request_failed', 'Network unavailable (smoke stub)' );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_Error', false ) ) {
	class WP_Error {
		public $errors = array();
		public $error_data = array();
		private $code;
		private $message;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			if ( $code !== '' ) {
				$this->errors[ $code ][] = $message;
			}
			if ( $data !== '' ) {
				$this->error_data[ $code ] = $data;
			}
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message( $code = '' ) {
			return (string) $this->message;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request', false ) ) {
	class WP_REST_Request {}
}

if ( ! class_exists( 'WP_REST_Response', false ) ) {
	class WP_REST_Response {
		public function __construct( $data = null, $status = 200 ) {}
	}
}

if ( ! class_exists( 'WP_Post', false ) ) {
	class WP_Post {
		public $post_name = '';
		public $post_parent = 0;
	}
}

$main = dirname( __DIR__ ) . '/deoia-subscriptions.php';

try {
	require $main;
} catch ( Throwable $e ) {
	deoia_puc_smoke_fail( 'plugin load threw: ' . $e->getMessage() );
	exit( 1 );
}

if ( ! defined( 'DEOIA_SUBSCRIPTIONS_VERSION' ) || DEOIA_SUBSCRIPTIONS_VERSION !== '1.6.4' ) {
	deoia_puc_smoke_fail( 'DEOIA_SUBSCRIPTIONS_VERSION must be 1.6.4' );
} else {
	deoia_puc_smoke_pass( 'version constant is 1.6.4' );
}

$src = (string) file_get_contents( $main );

if ( strpos( $src, "Update URI: https://github.com/RobertoTD/deoia-subscriptions" ) === false ) {
	deoia_puc_smoke_fail( 'Update URI header missing or incorrect' );
} else {
	deoia_puc_smoke_pass( 'Update URI header present' );
}

if ( strpos( $src, 'https://github.com/RobertoTD/deoia-subscriptions/' ) === false ) {
	deoia_puc_smoke_fail( 'PUC repo URL missing' );
} else {
	deoia_puc_smoke_pass( 'PUC repo URL configured' );
}

if ( strpos( $src, "setBranch( 'master' )" ) === false && strpos( $src, 'setBranch( "master" )' ) === false ) {
	deoia_puc_smoke_fail( 'setBranch(master) missing' );
} else {
	deoia_puc_smoke_pass( 'branch master configured' );
}

if ( strpos( $src, 'enableReleaseAssets' ) === false ) {
	deoia_puc_smoke_fail( 'enableReleaseAssets missing' );
} else {
	deoia_puc_smoke_pass( 'release assets enabled' );
}

if ( preg_match( "/setAuthentication\s*\(/", $src ) ) {
	deoia_puc_smoke_fail( 'authentication must not be embedded' );
} else {
	deoia_puc_smoke_pass( 'no embedded authentication' );
}

if ( ! class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class ) ) {
	deoia_puc_smoke_fail( 'PucFactory was not loaded' );
} else {
	deoia_puc_smoke_pass( 'PucFactory loaded' );
}

if ( ! function_exists( 'deoia_subscriptions_bootstrap_update_checker' ) ) {
	deoia_puc_smoke_fail( 'bootstrap function missing' );
} else {
	deoia_puc_smoke_pass( 'bootstrap function present' );
}

if ( ! function_exists( 'deoia_subscriptions_render_legal_terms_shortcode' ) ) {
	deoia_puc_smoke_fail( 'legal shortcodes not loaded (WIP must be preserved)' );
} else {
	deoia_puc_smoke_pass( 'legal WIP still loaded' );
}

if ( $failures > 0 ) {
	fwrite( STDERR, "\n{$failures} failure(s)\n" );
	exit( 1 );
}

echo "\nAll PUC smoke checks passed.\n";
exit( 0 );
