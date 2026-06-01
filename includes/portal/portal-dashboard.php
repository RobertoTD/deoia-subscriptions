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
 * @param array<string, mixed>|null $subscription
 * @param string                    $key
 */
function deoia_subscriptions_portal_subscription_bool( ?array $subscription, string $key ): bool {
	if ( $subscription === null || ! array_key_exists( $key, $subscription ) ) {
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
 */
function deoia_subscriptions_portal_subscription_payment_attention_required( ?array $subscription ): bool {
	if ( $subscription === null ) {
		return false;
	}

	$stripe_status = strtolower(
		deoia_subscriptions_portal_dashboard_section_string( $subscription, 'stripe_status' )
	);

	return in_array( $stripe_status, array( 'past_due', 'unpaid', 'incomplete' ), true );
}

/**
 * @param array<string, mixed>|null $subscription
 * @return array{label: string, modifier: string}|null
 */
function deoia_subscriptions_portal_resolve_subscription_billing_badge( ?array $subscription ): ?array {
	if ( $subscription === null ) {
		return null;
	}

	$billing_state = deoia_subscriptions_portal_dashboard_section_string( $subscription, 'billing_state' );
	if ( $billing_state === '' ) {
		return null;
	}

	if ( deoia_subscriptions_portal_subscription_payment_attention_required( $subscription ) ) {
		return array(
			'label'    => __( 'Pago pendiente', 'deoia-subscriptions' ),
			'modifier' => 'deoia-account-portal__badge--warn',
		);
	}

	if ( $billing_state === 'active' ) {
		return array(
			'label'    => __( 'Activa', 'deoia-subscriptions' ),
			'modifier' => 'deoia-account-portal__badge--ok',
		);
	}

	return array(
		'label'    => $billing_state,
		'modifier' => deoia_subscriptions_portal_status_modifier( $billing_state ),
	);
}

/**
 * @param array<string, mixed>|null $subscription
 */
function deoia_subscriptions_portal_render_payment_attention_notice( ?array $subscription ): string {
	if ( ! deoia_subscriptions_portal_subscription_payment_attention_required( $subscription ) ) {
		return '';
	}

	ob_start();
	?>
	<div class="deoia-account-portal__notice deoia-account-portal__notice--warn" role="alert">
		<p>
			<?php
			echo esc_html__(
				'Tu último pago no pudo completarse. Actualiza tu método de pago para recuperar el acceso Pro.',
				'deoia-subscriptions'
			);
			?>
		</p>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * @param string $value ISO 8601 timestamp from API.
 */
function deoia_subscriptions_portal_format_subscription_date_for_display( string $value ): string {
	$value = trim( $value );
	if ( $value === '' ) {
		return '';
	}

	try {
		$dt = new DateTime( $value );
	} catch ( Exception $e ) {
		return esc_html( $value );
	}

	$timestamp = $dt->getTimestamp();

	if ( class_exists( 'IntlDateFormatter' ) ) {
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : null;
		$formatter = new IntlDateFormatter(
			'es_ES',
			IntlDateFormatter::LONG,
			IntlDateFormatter::NONE,
			$timezone
		);
		if ( $formatter ) {
			$formatted = $formatter->format( $dt );
			if ( is_string( $formatted ) && $formatted !== '' ) {
				return esc_html( $formatted );
			}
		}
	}

	if ( function_exists( 'wp_date' ) ) {
		return esc_html( wp_date( 'j F Y', $timestamp ) );
	}

	return esc_html( date_i18n( 'j F Y', $timestamp ) );
}

/**
 * @param array<string, mixed>|null $subscription
 */
function deoia_subscriptions_portal_render_scheduled_cancellation_notice( ?array $subscription ): string {
	if ( $subscription === null || ! deoia_subscriptions_portal_subscription_bool( $subscription, 'is_cancel_scheduled' ) ) {
		return '';
	}

	$cancel_at = deoia_subscriptions_portal_dashboard_section_string( $subscription, 'cancel_at' );
	$period_end = deoia_subscriptions_portal_dashboard_section_string( $subscription, 'current_period_end' );

	$date_display = $cancel_at !== '' ? $cancel_at : $period_end;
	if ( $date_display !== '' ) {
		$date_display = deoia_subscriptions_portal_format_subscription_date_for_display( $date_display );
	}

	if ( $date_display !== '' ) {
		$message = sprintf(
			/* translators: %s: scheduled cancellation date */
			esc_html__(
				'Has solicitado cancelar tu suscripción. Tus beneficios Pro seguirán activos hasta el %s. Después de esa fecha tu agenda volverá al plan Freemium y no se realizarán nuevos cobros.',
				'deoia-subscriptions'
			),
			$date_display
		);
	} else {
		$message = esc_html__(
			'Has solicitado cancelar tu suscripción. Tus beneficios Pro seguirán activos hasta la fecha indicada en tu suscripción. Después de esa fecha tu agenda volverá al plan Freemium y no se realizarán nuevos cobros.',
			'deoia-subscriptions'
		);
	}

	ob_start();
	?>
	<div class="deoia-account-portal__notice deoia-account-portal__notice--subscription-scheduled" role="status">
		<p><?php echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_html/sprintf above. ?></p>
	</div>
	<?php
	return (string) ob_get_clean();
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
 * App entry URL on the provisioned tenant (wp-agenda-automatizada /agenda-app/).
 *
 * @param string $site_url Tenant root from dashboard (links.agenda_url or agenda.site_url).
 */
function deoia_subscriptions_portal_resolve_agenda_app_url( string $site_url ): string {
	$site_url = trim( $site_url );
	if ( $site_url === '' ) {
		return '';
	}

	return trailingslashit( $site_url ) . 'agenda-app/';
}

/**
 * @param string $plan_tier Raw plan tier from API (e.g. pro).
 */
function deoia_subscriptions_portal_format_plan_tier_label( string $plan_tier ): string {
	$plan_tier = trim( $plan_tier );
	if ( $plan_tier === '' ) {
		return '';
	}

	return strtoupper( $plan_tier );
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
	$subscription = isset( $dashboard['subscription'] ) && is_array( $dashboard['subscription'] )
		? $dashboard['subscription']
		: null;
	$links = isset( $dashboard['links'] ) && is_array( $dashboard['links'] )
		? $dashboard['links']
		: array();

	$agenda_name = deoia_subscriptions_portal_dashboard_section_string( $agenda, 'agenda_name' );
	$agenda_slug = deoia_subscriptions_portal_dashboard_section_string( $agenda, 'slug' );
	$agenda_site = deoia_subscriptions_portal_dashboard_section_string( $agenda, 'site_url' );
	$link_agenda = deoia_subscriptions_portal_dashboard_pick_string( $links['agenda_url'] ?? '' );

	$tenant_site_url = $link_agenda !== '' ? $link_agenda : $agenda_site;
	$agenda_app_url  = deoia_subscriptions_portal_resolve_agenda_app_url( $tenant_site_url );

	$plan_tier_raw = deoia_subscriptions_portal_dashboard_section_string( $subscription, 'plan_tier' );
	$plan_tier     = deoia_subscriptions_portal_format_plan_tier_label( $plan_tier_raw );
	$billing_badge = deoia_subscriptions_portal_resolve_subscription_billing_badge( $subscription );

	$agenda_lead = __( 'Gestiona tus citas desde la app de agenda.', 'deoia-subscriptions' );
	if ( $agenda_name !== '' && ( $agenda_slug === '' || strcasecmp( $agenda_name, $agenda_slug ) !== 0 ) ) {
		$agenda_lead = $agenda_name;
	}

	ob_start();
	?>
	<?php if ( ! $found ) : ?>
		<section class="deoia-account-portal__notice deoia-account-portal__notice--warn" role="alert">
			<p><?php echo esc_html__( 'No pudimos mostrar el panel completo de tu cuenta en este momento.', 'deoia-subscriptions' ); ?></p>
		</section>
	<?php endif; ?>

	<header class="deoia-account-portal__header">
		<h2 class="deoia-account-portal__title"><?php echo esc_html__( 'Portal DEOIA', 'deoia-subscriptions' ); ?></h2>
		<?php if ( $agenda_slug !== '' ) : ?>
			<p class="deoia-account-portal__subtitle">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: agenda slug */
						__( 'Agenda: %s', 'deoia-subscriptions' ),
						$agenda_slug
					)
				);
				?>
			</p>
		<?php endif; ?>
	</header>

	<section class="deoia-account-portal__grid deoia-account-portal__grid--dashboard" aria-label="<?php echo esc_attr__( 'Tu panel', 'deoia-subscriptions' ); ?>">
		<article class="deoia-account-portal__card deoia-account-portal__card--primary">
			<h3 class="deoia-account-portal__card-title"><?php echo esc_html__( 'Agenda', 'deoia-subscriptions' ); ?></h3>
			<p class="deoia-account-portal__lead"><?php echo esc_html( $agenda_lead ); ?></p>
			<p class="deoia-account-portal__actions">
				<?php
				$agenda_app_href = $agenda_app_url !== ''
					? esc_url( $agenda_app_url, array( 'https', 'http' ) )
					: '';
				?>
				<?php if ( $agenda_app_href !== '' ) : ?>
					<a class="deoia-account-portal__btn deoia-account-portal__btn--primary" href="<?php echo esc_url( $agenda_app_href ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html__( 'Ir a mi agenda', 'deoia-subscriptions' ); ?>
					</a>
				<?php else : ?>
					<span class="deoia-account-portal__btn deoia-account-portal__btn--disabled" aria-disabled="true">
						<?php echo esc_html__( 'Ir a mi agenda (no disponible)', 'deoia-subscriptions' ); ?>
					</span>
				<?php endif; ?>
			</p>
		</article>

		<article class="deoia-account-portal__card deoia-account-portal__card--subscription">
			<h3 class="deoia-account-portal__card-title"><?php echo esc_html__( 'Suscripción', 'deoia-subscriptions' ); ?></h3>
			<?php if ( $plan_tier !== '' ) : ?>
				<p class="deoia-account-portal__label"><?php echo esc_html__( 'Plan', 'deoia-subscriptions' ); ?></p>
				<p class="deoia-account-portal__value"><?php echo esc_html( $plan_tier ); ?></p>
			<?php endif; ?>
			<?php if ( $billing_badge !== null ) : ?>
				<p class="deoia-account-portal__label"><?php echo esc_html__( 'Estado', 'deoia-subscriptions' ); ?></p>
				<p>
					<span class="deoia-account-portal__badge <?php echo esc_attr( $billing_badge['modifier'] ); ?>">
						<?php echo esc_html( $billing_badge['label'] ); ?>
					</span>
				</p>
			<?php endif; ?>
			<?php
			echo deoia_subscriptions_portal_render_payment_attention_notice( $subscription );
			if ( ! deoia_subscriptions_portal_subscription_payment_attention_required( $subscription ) ) {
				echo deoia_subscriptions_portal_render_scheduled_cancellation_notice( $subscription );
			}
			if ( function_exists( 'deoia_subscriptions_portal_render_billing_button' ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in billing render.
				echo deoia_subscriptions_portal_render_billing_button( $subscription );
			}
			?>
		</article>
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
