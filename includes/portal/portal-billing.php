<?php
/**
 * Portal billing: Stripe Customer Portal via server-side POST (M5.1C).
 *
 * @package DeoiaSubscriptions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DEOIA_PORTAL_BILLING_ACTION = 'deoia_portal_billing';

const DEOIA_PORTAL_BILLING_POST_ACTION = 'manage';

/**
 * @return string|null
 */
function deoia_subscriptions_backend_portal_billing_url(): ?string {
	if (
		defined( 'DEOIA_SUBSCRIPTIONS_BACKEND_PORTAL_BILLING_URL' )
		&& is_string( constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_PORTAL_BILLING_URL' ) )
		&& constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_PORTAL_BILLING_URL' ) !== ''
	) {
		return (string) constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_PORTAL_BILLING_URL' );
	}

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

	return substr( $start_url, 0, -$suffix_length ) . '/portal/session/billing-portal';
}

/**
 * @param string[] $hosts
 * @return string[]
 */
function deoia_subscriptions_portal_allow_stripe_billing_redirect_host( array $hosts ): array {
	$hosts[] = 'billing.stripe.com';
	return $hosts;
}

/**
 * Whether ?billing_return=1 is present.
 */
function deoia_subscriptions_portal_get_billing_return_from_request(): bool {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only PRG flag.
	if ( ! isset( $_GET['billing_return'] ) ) {
		return false;
	}

	$raw = wp_unslash( $_GET['billing_return'] );
	return $raw === '1' || $raw === 1;
}

/**
 * Whether ?billing_error=1 is present.
 */
function deoia_subscriptions_portal_get_billing_error_from_request(): bool {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only PRG flag.
	if ( ! isset( $_GET['billing_error'] ) ) {
		return false;
	}

	$raw = wp_unslash( $_GET['billing_error'] );
	return $raw === '1' || $raw === 1;
}

/**
 * @param string $url
 * @return bool
 */
function deoia_subscriptions_portal_is_safe_stripe_billing_portal_url( string $url ): bool {
	$url = trim( $url );
	if ( $url === '' ) {
		return false;
	}

	$parsed = wp_parse_url( $url );
	if ( ! is_array( $parsed ) ) {
		return false;
	}

	$scheme = isset( $parsed['scheme'] ) ? strtolower( (string) $parsed['scheme'] ) : '';
	$host   = isset( $parsed['host'] ) ? strtolower( (string) $parsed['host'] ) : '';

	return $scheme === 'https' && $host === 'billing.stripe.com';
}

/**
 * @param string $session_token Plain portal session token from cookie.
 * @return array{status: string, url?: string}
 */
function deoia_subscriptions_portal_request_billing_portal_url( string $session_token ): array {
	if ( $session_token === '' ) {
		return array( 'status' => 'no_session' );
	}

	$backend_url = deoia_subscriptions_backend_portal_billing_url();
	if ( $backend_url === null || $backend_url === '' ) {
		return array( 'status' => 'misconfigured' );
	}

	if ( ! function_exists( 'deoia_subscriptions_portal_internal_token' ) ) {
		return array( 'status' => 'misconfigured' );
	}

	$internal_token = deoia_subscriptions_portal_internal_token();
	if ( $internal_token === null ) {
		return array( 'status' => 'misconfigured' );
	}

	if ( ! defined( 'DEOIA_PORTAL_INTERNAL_TOKEN_HEADER' ) ) {
		return array( 'status' => 'misconfigured' );
	}

	$response = wp_remote_post(
		$backend_url,
		array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type'                     => 'application/json',
				'Accept'                           => 'application/json',
				DEOIA_PORTAL_INTERNAL_TOKEN_HEADER => $internal_token,
			),
			'body'    => wp_json_encode(
				array(
					'session_token' => $session_token,
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return array( 'status' => 'unavailable' );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$raw  = (string) wp_remote_retrieve_body( $response );
	$data = json_decode( $raw, true );

	if ( $code === 401 ) {
		return array( 'status' => 'session_invalid' );
	}

	if ( $code === 409 ) {
		return array( 'status' => 'unavailable' );
	}

	if ( $code === 403 || $code >= 500 ) {
		return array( 'status' => 'unavailable' );
	}

	if ( $code !== 200 || ! is_array( $data ) || empty( $data['ok'] ) ) {
		return array( 'status' => 'unavailable' );
	}

	$url = isset( $data['url'] ) && is_string( $data['url'] ) ? trim( $data['url'] ) : '';
	if ( $url === '' ) {
		return array( 'status' => 'unavailable' );
	}

	return array(
		'status' => 'success',
		'url'    => $url,
	);
}

/**
 * Redirect after billing POST (PRG).
 */
function deoia_subscriptions_portal_redirect_after_billing_error(): void {
	$url = function_exists( 'deoia_subscriptions_portal_account_url' )
		? deoia_subscriptions_portal_account_url()
		: home_url( '/account/' );

	$url = add_query_arg( 'billing_error', '1', $url );
	wp_safe_redirect( $url );
	exit;
}

/**
 * @param array<string, mixed>|null $subscription
 */
function deoia_subscriptions_portal_subscription_bool( ?array $subscription, string $key ): bool {
	if ( $subscription === null ) {
		return false;
	}

	if ( ! array_key_exists( $key, $subscription ) ) {
		return false;
	}

	$value = $subscription[ $key ];
	if ( is_bool( $value ) ) {
		return $value;
	}

	if ( is_string( $value ) ) {
		$lower = strtolower( trim( $value ) );
		return $lower === '1' || $lower === 'true' || $lower === 'yes';
	}

	return ! empty( $value );
}

/**
 * @param array<string, mixed>|null $subscription
 * @return array{mode: string, label: string, hint: string}
 */
function deoia_subscriptions_portal_resolve_billing_button_ui( ?array $subscription ): array {
	$unavailable_label = __( 'Gestión no disponible', 'deoia-subscriptions' );

	if ( $subscription === null ) {
		return array(
			'mode'  => 'hidden',
			'label' => $unavailable_label,
			'hint'  => '',
		);
	}

	$billing_state = function_exists( 'deoia_subscriptions_portal_dashboard_section_string' )
		? deoia_subscriptions_portal_dashboard_section_string( $subscription, 'billing_state' )
		: '';

	if ( $billing_state === 'missing' ) {
		return array(
			'mode'  => 'hidden',
			'label' => $unavailable_label,
			'hint'  => '',
		);
	}

	$sync_pending = deoia_subscriptions_portal_subscription_bool( $subscription, 'sync_pending' )
		|| $billing_state === 'sync_pending';

	if ( $sync_pending ) {
		return array(
			'mode'  => 'disabled',
			'label' => __( 'Gestionar pago y suscripción', 'deoia-subscriptions' ),
			'hint'  => __(
				'Estamos sincronizando tu suscripción. Inténtalo de nuevo en unos minutos.',
				'deoia-subscriptions'
			),
		);
	}

	$stripe_status = function_exists( 'deoia_subscriptions_portal_dashboard_section_string' )
		? strtolower( deoia_subscriptions_portal_dashboard_section_string( $subscription, 'stripe_status' ) )
		: '';

	$payment_issue = in_array( $stripe_status, array( 'past_due', 'unpaid', 'incomplete' ), true );
	$cancel_at_period_end = deoia_subscriptions_portal_subscription_bool( $subscription, 'cancel_at_period_end' );

	if ( $payment_issue ) {
		$label = __( 'Actualizar pago', 'deoia-subscriptions' );
	} elseif ( $cancel_at_period_end ) {
		$label = __( 'Revisar suscripción', 'deoia-subscriptions' );
	} else {
		$label = __( 'Gestionar pago y suscripción', 'deoia-subscriptions' );
	}

	return array(
		'mode'  => 'form',
		'label' => $label,
		'hint'  => '',
	);
}

/**
 * @param array<string, mixed>|null $subscription
 */
function deoia_subscriptions_portal_render_billing_button( ?array $subscription ): string {
	$ui = deoia_subscriptions_portal_resolve_billing_button_ui( $subscription );

	if ( $ui['mode'] === 'hidden' ) {
		return '';
	}

	$form_action = function_exists( 'deoia_subscriptions_portal_account_url' )
		? deoia_subscriptions_portal_account_url()
		: home_url( '/account/' );

	ob_start();

	if ( $ui['hint'] !== '' ) {
		?>
		<p class="deoia-account-portal__hint deoia-account-portal__hint--billing">
			<?php echo esc_html( $ui['hint'] ); ?>
		</p>
		<?php
	}

	if ( $ui['mode'] === 'disabled' ) {
		?>
		<p class="deoia-account-portal__actions deoia-account-portal__actions--billing">
			<span class="deoia-account-portal__btn deoia-account-portal__btn--disabled" aria-disabled="true">
				<?php echo esc_html( $ui['label'] ); ?>
			</span>
		</p>
		<?php
		return (string) ob_get_clean();
	}

	?>
	<p class="deoia-account-portal__actions deoia-account-portal__actions--billing">
		<form class="deoia-account-portal__billing-form" method="post" action="<?php echo esc_url( $form_action ); ?>">
			<?php wp_nonce_field( DEOIA_PORTAL_BILLING_ACTION, 'deoia_portal_billing_nonce' ); ?>
			<input type="hidden" name="deoia_portal_billing_action" value="<?php echo esc_attr( DEOIA_PORTAL_BILLING_POST_ACTION ); ?>" />
			<button type="submit" class="deoia-account-portal__btn deoia-account-portal__btn--primary deoia-account-portal__btn--billing">
				<?php echo esc_html( $ui['label'] ); ?>
			</button>
		</form>
	</p>
	<?php

	return (string) ob_get_clean();
}

/**
 * PRG notices for billing return / error.
 *
 * @return string
 */
function deoia_subscriptions_portal_render_billing_prg_notices(): string {
	$html = '';

	if ( deoia_subscriptions_portal_get_billing_return_from_request() ) {
		ob_start();
		?>
		<section class="deoia-account-portal__notice deoia-account-portal__notice--ok" role="status">
			<p>
				<?php
				echo esc_html__(
					'Volviste de Stripe. Si realizaste cambios, el estado se actualizará en unos minutos.',
					'deoia-subscriptions'
				);
				?>
			</p>
		</section>
		<?php
		$html .= (string) ob_get_clean();
	}

	if ( deoia_subscriptions_portal_get_billing_error_from_request() ) {
		ob_start();
		?>
		<section class="deoia-account-portal__notice deoia-account-portal__notice--warn" role="alert">
			<p>
				<?php
				echo esc_html__(
					'No pudimos abrir la gestión de pago en este momento. Inténtalo más tarde.',
					'deoia-subscriptions'
				);
				?>
			</p>
		</section>
		<?php
		$html .= (string) ob_get_clean();
	}

	return $html;
}

/**
 * template_redirect: billing portal POST on /account/ (priority 3).
 */
function deoia_subscriptions_portal_handle_billing_post(): void {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}

	if ( ! function_exists( 'deoia_subscriptions_portal_is_account_page_view' )
		|| ! deoia_subscriptions_portal_is_account_page_view() ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) !== 'POST' ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.
	if ( ! isset( $_POST['deoia_portal_billing_action'] )
		|| sanitize_key( wp_unslash( (string) $_POST['deoia_portal_billing_action'] ) ) !== DEOIA_PORTAL_BILLING_POST_ACTION ) {
		return;
	}

	$nonce = isset( $_POST['deoia_portal_billing_nonce'] )
		? sanitize_text_field( wp_unslash( (string) $_POST['deoia_portal_billing_nonce'] ) )
		: '';

	if ( $nonce === '' || ! wp_verify_nonce( $nonce, DEOIA_PORTAL_BILLING_ACTION ) ) {
		deoia_subscriptions_portal_redirect_after_billing_error();
	}

	if ( ! function_exists( 'deoia_subscriptions_portal_get_session_token_from_cookie' ) ) {
		deoia_subscriptions_portal_redirect_after_billing_error();
	}

	$session_token = deoia_subscriptions_portal_get_session_token_from_cookie();
	if ( $session_token === '' ) {
		deoia_subscriptions_portal_redirect_after_billing_error();
	}

	$result = deoia_subscriptions_portal_request_billing_portal_url( $session_token );

	if ( $result['status'] === 'session_invalid' ) {
		if ( function_exists( 'deoia_subscriptions_portal_clear_session_cookie' ) ) {
			deoia_subscriptions_portal_clear_session_cookie();
		}
		deoia_subscriptions_portal_redirect_after_billing_error();
	}

	if ( $result['status'] === 'success' ) {
		$url = $result['url'] ?? '';
		if ( is_string( $url ) && deoia_subscriptions_portal_is_safe_stripe_billing_portal_url( $url ) ) {
			wp_safe_redirect( $url );
			exit;
		}
	}

	deoia_subscriptions_portal_redirect_after_billing_error();
}
