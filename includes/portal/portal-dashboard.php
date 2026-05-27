<?php
/**
 * Portal account dashboard: server-side fetch + render (M3.7B).
 *
 * @package DeoiaSubscriptions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DEOIA_PORTAL_INTERNAL_TOKEN_HEADER = 'X-DEOIA-Portal-Internal-Token';

/**
 * @return string|null
 */
function deoia_subscriptions_backend_portal_dashboard_url(): ?string {
	if (
		defined( 'DEOIA_SUBSCRIPTIONS_BACKEND_PORTAL_DASHBOARD_URL' )
		&& is_string( constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_PORTAL_DASHBOARD_URL' ) )
		&& constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_PORTAL_DASHBOARD_URL' ) !== ''
	) {
		return (string) constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_PORTAL_DASHBOARD_URL' );
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

	return substr( $start_url, 0, -$suffix_length ) . '/portal/session/dashboard';
}

/**
 * @return string|null
 */
function deoia_subscriptions_portal_internal_token(): ?string {
	if (
		defined( 'DEOIA_PORTAL_INTERNAL_TOKEN' )
		&& is_string( constant( 'DEOIA_PORTAL_INTERNAL_TOKEN' ) )
		&& constant( 'DEOIA_PORTAL_INTERNAL_TOKEN' ) !== ''
	) {
		return (string) constant( 'DEOIA_PORTAL_INTERNAL_TOKEN' );
	}

	return null;
}

/**
 * Whether the current request is the portal account page (not access/verify).
 */
function deoia_subscriptions_portal_is_account_page_view(): bool {
	if ( function_exists( 'deoia_subscriptions_portal_is_verify_request' )
		&& deoia_subscriptions_portal_is_verify_request() ) {
		return false;
	}

	if ( function_exists( 'deoia_subscriptions_portal_is_access_page_view' )
		&& deoia_subscriptions_portal_is_access_page_view() ) {
		return false;
	}

	if ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ) {
		$uri = wp_unslash( $_SERVER['REQUEST_URI'] );
		if ( is_string( $uri ) ) {
			if ( strpos( $uri, '/account/access' ) !== false ) {
				return false;
			}
			if ( preg_match( '#/(?:index\.php/)?account/?(?:\?|$)#', $uri ) === 1
				|| preg_match( '#/account/?$#', $uri ) === 1 ) {
				return true;
			}
		}
	}

	if ( ! is_page() ) {
		return false;
	}

	$page = get_queried_object();
	if ( ! $page instanceof WP_Post || $page->post_name !== 'account' ) {
		return false;
	}

	return ! $page->post_parent;
}

/**
 * Reads portal session token from httpOnly cookie (server-side only).
 */
function deoia_subscriptions_portal_get_session_token_from_cookie(): string {
	if ( ! defined( 'DEOIA_PORTAL_SESSION_COOKIE_NAME' ) ) {
		return '';
	}

	$name = DEOIA_PORTAL_SESSION_COOKIE_NAME;
	if ( ! isset( $_COOKIE[ $name ] ) ) {
		return '';
	}

	$raw = wp_unslash( $_COOKIE[ $name ] );
	if ( ! is_string( $raw ) ) {
		return '';
	}

	return sanitize_text_field( trim( $raw ) );
}

/**
 * Clears portal session cookie (same flags as verify setcookie).
 */
function deoia_subscriptions_portal_clear_session_cookie(): void {
	if ( ! defined( 'DEOIA_PORTAL_SESSION_COOKIE_NAME' ) ) {
		return;
	}

	$name = DEOIA_PORTAL_SESSION_COOKIE_NAME;

	setcookie(
		$name,
		'',
		array(
			'expires'  => time() - YEAR_IN_SECONDS,
			'path'     => COOKIEPATH,
			'domain'   => COOKIE_DOMAIN,
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		)
	);

	unset( $_COOKIE[ $name ] );
}

/**
 * @param mixed $value
 * @return string
 */
function deoia_subscriptions_portal_dashboard_pick_string( $value ): string {
	if ( ! is_string( $value ) ) {
		return '';
	}
	$trimmed = trim( $value );
	return $trimmed;
}

/**
 * Fetches dashboard from backend (server-side only).
 *
 * @param string $session_token Plain session token from cookie.
 * @return array{status: string, dashboard?: array<string, mixed>}
 */
function deoia_subscriptions_portal_fetch_dashboard( string $session_token ): array {
	if ( $session_token === '' ) {
		return array( 'status' => 'no_session' );
	}

	$backend_url    = deoia_subscriptions_backend_portal_dashboard_url();
	$internal_token = deoia_subscriptions_portal_internal_token();

	if ( $backend_url === null || $backend_url === '' || $internal_token === null ) {
		return array( 'status' => 'misconfigured' );
	}

	$response = wp_remote_post(
		$backend_url,
		array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type'                    => 'application/json',
				'Accept'                          => 'application/json',
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

	if ( $code === 403 ) {
		return array( 'status' => 'misconfigured' );
	}

	if ( $code !== 200 || ! is_array( $data ) || empty( $data['ok'] ) ) {
		return array( 'status' => 'unavailable' );
	}

	if ( ! isset( $data['dashboard'] ) || ! is_array( $data['dashboard'] ) ) {
		return array( 'status' => 'unavailable' );
	}

	return array(
		'status'    => 'success',
		'dashboard' => $data['dashboard'],
	);
}

/**
 * @param array<string, mixed>|null $section
 * @param string                    $key
 * @return string
 */
function deoia_subscriptions_portal_dashboard_section_string( ?array $section, string $key ): string {
	if ( $section === null ) {
		return '';
	}
	return deoia_subscriptions_portal_dashboard_pick_string( $section[ $key ] ?? '' );
}

/**
 * @param array<string, mixed> $dashboard
 * @return string
 */
function deoia_subscriptions_portal_render_dashboard_real( array $dashboard ): string {
	$found = ! empty( $dashboard['found'] );

	$agenda = isset( $dashboard['agenda'] ) && is_array( $dashboard['agenda'] )
		? $dashboard['agenda']
		: null;
	$installation = isset( $dashboard['installation'] ) && is_array( $dashboard['installation'] )
		? $dashboard['installation']
		: null;
	$subscription = isset( $dashboard['subscription'] ) && is_array( $dashboard['subscription'] )
		? $dashboard['subscription']
		: null;
	$account = isset( $dashboard['account'] ) && is_array( $dashboard['account'] )
		? $dashboard['account']
		: null;
	$links = isset( $dashboard['links'] ) && is_array( $dashboard['links'] )
		? $dashboard['links']
		: array();

	$agenda_name   = deoia_subscriptions_portal_dashboard_section_string( $agenda, 'agenda_name' );
	$agenda_slug   = deoia_subscriptions_portal_dashboard_section_string( $agenda, 'slug' );
	$agenda_site   = deoia_subscriptions_portal_dashboard_section_string( $agenda, 'site_url' );
	$agenda_wp     = deoia_subscriptions_portal_dashboard_section_string( $agenda, 'wp_login_url' );
	$link_agenda   = deoia_subscriptions_portal_dashboard_pick_string( $links['agenda_url'] ?? '' );
	$link_wp       = deoia_subscriptions_portal_dashboard_pick_string( $links['wp_login_url'] ?? '' );

	$open_agenda_url = $link_agenda !== '' ? $link_agenda : $agenda_site;
	$wp_login_url    = $link_wp !== '' ? $link_wp : $agenda_wp;

	$inst_status = deoia_subscriptions_portal_dashboard_section_string( $installation, 'status' );
	$inst_type   = deoia_subscriptions_portal_dashboard_section_string( $installation, 'installation_type' );

	$plan_tier      = deoia_subscriptions_portal_dashboard_section_string( $subscription, 'plan_tier' );
	$stripe_status  = deoia_subscriptions_portal_dashboard_section_string( $subscription, 'stripe_status' );
	$billing_state  = deoia_subscriptions_portal_dashboard_section_string( $subscription, 'billing_state' );
	$email          = deoia_subscriptions_portal_dashboard_section_string( $account, 'email_canonical' );

	$messages = array();
	if ( isset( $dashboard['messages'] ) && is_array( $dashboard['messages'] ) ) {
		foreach ( $dashboard['messages'] as $message ) {
			if ( is_string( $message ) && trim( $message ) !== '' ) {
				$messages[] = trim( $message );
			}
		}
	}

	$title = $agenda_name !== '' ? $agenda_name : __( 'Tu agenda', 'deoia-subscriptions' );

	ob_start();
	?>
	<?php if ( ! $found ) : ?>
		<section class="deoia-account-portal__notice deoia-account-portal__notice--warn" role="alert">
			<p><?php echo esc_html__( 'No pudimos mostrar el panel completo de tu cuenta en este momento.', 'deoia-subscriptions' ); ?></p>
		</section>
	<?php endif; ?>

	<header class="deoia-account-portal__header">
		<h2 class="deoia-account-portal__title"><?php echo esc_html( $title ); ?></h2>
		<?php if ( $agenda_slug !== '' ) : ?>
			<p class="deoia-account-portal__subtitle">
				<code><?php echo esc_html( $agenda_slug ); ?></code>
			</p>
		<?php endif; ?>
	</header>

	<section class="deoia-account-portal__grid" aria-label="<?php echo esc_attr__( 'Resumen de tu cuenta', 'deoia-subscriptions' ); ?>">
		<article class="deoia-account-portal__card deoia-account-portal__card--primary">
			<h3 class="deoia-account-portal__card-title"><?php echo esc_html__( 'Agenda', 'deoia-subscriptions' ); ?></h3>
			<p class="deoia-account-portal__value"><?php echo esc_html( $title ); ?></p>
			<p class="deoia-account-portal__actions">
				<?php
				$open_agenda_href = $open_agenda_url !== ''
					? esc_url( $open_agenda_url, array( 'https', 'http' ) )
					: '';
				?>
				<?php if ( $open_agenda_href !== '' ) : ?>
					<a class="deoia-account-portal__btn deoia-account-portal__btn--primary" href="<?php echo esc_url( $open_agenda_href ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html__( 'Abrir agenda', 'deoia-subscriptions' ); ?>
					</a>
				<?php else : ?>
					<span class="deoia-account-portal__btn deoia-account-portal__btn--disabled" aria-disabled="true">
						<?php echo esc_html__( 'Abrir agenda (no disponible)', 'deoia-subscriptions' ); ?>
					</span>
				<?php endif; ?>
				<?php
				$wp_login_href = $wp_login_url !== ''
					? esc_url( $wp_login_url, array( 'https', 'http' ) )
					: '';
				?>
				<?php if ( $wp_login_href !== '' ) : ?>
					<a class="deoia-account-portal__btn deoia-account-portal__btn--secondary" href="<?php echo esc_url( $wp_login_href ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html__( 'Entrar a WordPress', 'deoia-subscriptions' ); ?>
					</a>
				<?php else : ?>
					<span class="deoia-account-portal__btn deoia-account-portal__btn--disabled" aria-disabled="true">
						<?php echo esc_html__( 'WordPress (no disponible)', 'deoia-subscriptions' ); ?>
					</span>
				<?php endif; ?>
			</p>
		</article>

		<article class="deoia-account-portal__card">
			<h3 class="deoia-account-portal__card-title"><?php echo esc_html__( 'Instalación', 'deoia-subscriptions' ); ?></h3>
			<?php if ( $inst_status !== '' ) : ?>
				<p>
					<span class="deoia-account-portal__badge <?php echo esc_attr( deoia_subscriptions_portal_status_modifier( $inst_status ) ); ?>">
						<?php echo esc_html( $inst_status ); ?>
					</span>
				</p>
			<?php endif; ?>
			<?php if ( $inst_type !== '' ) : ?>
				<p class="deoia-account-portal__label"><?php echo esc_html__( 'Tipo', 'deoia-subscriptions' ); ?></p>
				<p class="deoia-account-portal__value"><?php echo esc_html( $inst_type ); ?></p>
			<?php endif; ?>
		</article>

		<article class="deoia-account-portal__card">
			<h3 class="deoia-account-portal__card-title"><?php echo esc_html__( 'Suscripción', 'deoia-subscriptions' ); ?></h3>
			<?php if ( $plan_tier !== '' ) : ?>
				<p class="deoia-account-portal__label"><?php echo esc_html__( 'Plan', 'deoia-subscriptions' ); ?></p>
				<p class="deoia-account-portal__value"><?php echo esc_html( $plan_tier ); ?></p>
			<?php endif; ?>
			<?php if ( $stripe_status !== '' ) : ?>
				<p class="deoia-account-portal__label"><?php echo esc_html__( 'Estado Stripe', 'deoia-subscriptions' ); ?></p>
				<p class="deoia-account-portal__value"><?php echo esc_html( $stripe_status ); ?></p>
			<?php endif; ?>
			<?php if ( $billing_state !== '' ) : ?>
				<p class="deoia-account-portal__label"><?php echo esc_html__( 'Facturación', 'deoia-subscriptions' ); ?></p>
				<p>
					<span class="deoia-account-portal__badge <?php echo esc_attr( deoia_subscriptions_portal_status_modifier( $billing_state ) ); ?>">
						<?php echo esc_html( $billing_state ); ?>
					</span>
				</p>
			<?php endif; ?>
			<?php
			if ( function_exists( 'deoia_subscriptions_portal_render_billing_button' ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in billing render.
				echo deoia_subscriptions_portal_render_billing_button( $subscription );
			}
			?>
		</article>

		<article class="deoia-account-portal__card">
			<h3 class="deoia-account-portal__card-title"><?php echo esc_html__( 'Cuenta', 'deoia-subscriptions' ); ?></h3>
			<?php if ( $email !== '' ) : ?>
				<p class="deoia-account-portal__label"><?php echo esc_html__( 'Correo', 'deoia-subscriptions' ); ?></p>
				<p class="deoia-account-portal__value"><?php echo esc_html( $email ); ?></p>
			<?php endif; ?>
		</article>

		<?php if ( $messages !== array() ) : ?>
			<article class="deoia-account-portal__card deoia-account-portal__card--wide">
				<h3 class="deoia-account-portal__card-title"><?php echo esc_html__( 'Avisos', 'deoia-subscriptions' ); ?></h3>
				<ul class="deoia-account-portal__messages">
					<?php foreach ( $messages as $message ) : ?>
						<li><?php echo esc_html( $message ); ?></li>
					<?php endforeach; ?>
				</ul>
			</article>
		<?php endif; ?>
	</section>
	<?php
	return (string) ob_get_clean();
}

/**
 * @param string $message
 * @return string
 */
function deoia_subscriptions_portal_render_notice_generic( string $message ): string {
	ob_start();
	?>
	<section class="deoia-account-portal__notice deoia-account-portal__notice--warn" role="alert">
		<p><?php echo esc_html( $message ); ?></p>
	</section>
	<?php
	return (string) ob_get_clean();
}

/**
 * Unauthenticated CTA to request access.
 *
 * @return string
 */
function deoia_subscriptions_portal_render_unauthenticated_cta(): string {
	$access_url = function_exists( 'deoia_subscriptions_portal_access_url' )
		? deoia_subscriptions_portal_access_url()
		: home_url( '/account/access/' );

	ob_start();
	?>
	<header class="deoia-account-portal__header">
		<h2 class="deoia-account-portal__title"><?php echo esc_html__( 'Portal DEOIA', 'deoia-subscriptions' ); ?></h2>
		<p class="deoia-account-portal__subtitle"><?php echo esc_html__( 'Accede con tu correo y un enlace seguro.', 'deoia-subscriptions' ); ?></p>
	</header>
	<section class="deoia-account-portal__cta" aria-labelledby="deoia-portal-cta-heading">
		<h3 id="deoia-portal-cta-heading" class="deoia-account-portal__section-title">
			<?php echo esc_html__( 'Acceso requerido', 'deoia-subscriptions' ); ?>
		</h3>
		<p><?php echo esc_html__( 'Para ver tu panel, solicita un enlace de acceso a tu correo autorizado.', 'deoia-subscriptions' ); ?></p>
		<p class="deoia-account-portal__cta-actions">
			<a class="deoia-account-portal__btn deoia-account-portal__btn--primary" href="<?php echo esc_url( $access_url ); ?>">
				<?php echo esc_html__( 'Solicitar enlace de acceso', 'deoia-subscriptions' ); ?>
			</a>
		</p>
	</section>
	<?php
	return (string) ob_get_clean();
}

/**
 * Session expired / invalid CTA.
 *
 * @return string
 */
function deoia_subscriptions_portal_render_session_invalid_cta(): string {
	$access_url = function_exists( 'deoia_subscriptions_portal_access_url' )
		? deoia_subscriptions_portal_access_url()
		: home_url( '/account/access/' );

	ob_start();
	?>
	<?php
	echo deoia_subscriptions_portal_render_notice_generic(
		__( 'Tu sesión expiró o ya no es válida. Solicita un nuevo enlace de acceso.', 'deoia-subscriptions' )
	);
	?>
	<section class="deoia-account-portal__cta">
		<p class="deoia-account-portal__cta-actions">
			<a class="deoia-account-portal__btn deoia-account-portal__btn--primary" href="<?php echo esc_url( $access_url ); ?>">
				<?php echo esc_html__( 'Solicitar enlace de acceso', 'deoia-subscriptions' ); ?>
			</a>
		</p>
	</section>
	<?php
	return (string) ob_get_clean();
}

/**
 * Renders /account/ portal view (dashboard or auth states).
 *
 * @return string
 */
function deoia_subscriptions_render_portal_account_dashboard_view(): string {
	$session_token = deoia_subscriptions_portal_get_session_token_from_cookie();
	$result        = deoia_subscriptions_portal_fetch_dashboard( $session_token );

	$inner = '';

	switch ( $result['status'] ) {
		case 'no_session':
			$inner = deoia_subscriptions_portal_render_unauthenticated_cta();
			break;
		case 'session_invalid':
			deoia_subscriptions_portal_clear_session_cookie();
			$inner = deoia_subscriptions_portal_render_session_invalid_cta();
			break;
		case 'misconfigured':
		case 'unavailable':
			$inner = deoia_subscriptions_portal_render_notice_generic(
				__( 'No pudimos cargar tu portal en este momento. Inténtalo más tarde.', 'deoia-subscriptions' )
			);
			break;
		case 'success':
			$dashboard = $result['dashboard'] ?? array();
			if ( ! is_array( $dashboard ) ) {
				$dashboard = array();
			}
			$inner = deoia_subscriptions_portal_render_dashboard_real( $dashboard );
			break;
		default:
			$inner = deoia_subscriptions_portal_render_notice_generic(
				__( 'No pudimos cargar tu portal en este momento. Inténtalo más tarde.', 'deoia-subscriptions' )
			);
			break;
	}

	$wrapper_class = 'deoia-account-portal';
	if ( $result['status'] === 'success' ) {
		$wrapper_class .= ' deoia-account-portal--live';
	}

	$billing_notices = function_exists( 'deoia_subscriptions_portal_render_billing_prg_notices' )
		? deoia_subscriptions_portal_render_billing_prg_notices()
		: '';

	ob_start();
	?>
	<div class="<?php echo esc_attr( $wrapper_class ); ?>" id="deoia-account-portal">
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in billing PRG notices.
		echo $billing_notices;
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inner HTML built with escaping helpers.
		echo $inner;
		?>
	</div>
	<?php
	return (string) ob_get_clean();
}
