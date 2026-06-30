<?php
/**
 * Plugin Name: DEOIA Subscriptions
 * Description: Formulario de suscripción y Checkout Session de Stripe (REST).
 * Version: 1.5.5
 * Author: DEOIA
 * Text Domain: deoia-subscriptions
 *
 * @package DeoiaSubscriptions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DEOIA_SUBSCRIPTIONS_VERSION', '1.6.0' );
define( 'DEOIA_SUBSCRIPTIONS_FILE', __FILE__ );
define( 'DEOIA_SUBSCRIPTIONS_DIR', plugin_dir_path( __FILE__ ) );

require_once DEOIA_SUBSCRIPTIONS_DIR . 'includes/portal/account-portal-shortcode.php';
require_once DEOIA_SUBSCRIPTIONS_DIR . 'includes/portal/portal-access-verify.php';
require_once DEOIA_SUBSCRIPTIONS_DIR . 'includes/portal/portal-access-request.php';
require_once DEOIA_SUBSCRIPTIONS_DIR . 'includes/portal/portal-dashboard.php';
require_once DEOIA_SUBSCRIPTIONS_DIR . 'includes/portal/portal-billing.php';
require_once DEOIA_SUBSCRIPTIONS_DIR . 'includes/portal/subscription-thank-you-shortcode.php';

add_action( 'template_redirect', 'deoia_subscriptions_portal_handle_magic_link_verify', 1 );
add_action( 'template_redirect', 'deoia_subscriptions_portal_handle_access_request_post', 2 );
add_action( 'template_redirect', 'deoia_subscriptions_portal_handle_billing_post', 3 );
add_filter( 'allowed_redirect_hosts', 'deoia_subscriptions_portal_allow_stripe_billing_redirect_host' );

/**
 * Registra assets (el shortcode hace enqueue al renderizar).
 */
function deoia_subscriptions_register_assets(): void {
	wp_register_style(
		'deoia-account-portal',
		plugins_url( 'assets/css/account-portal.css', DEOIA_SUBSCRIPTIONS_FILE ),
		array(),
		DEOIA_SUBSCRIPTIONS_VERSION
	);

	wp_register_style(
		'deoia-subscription-form',
		plugins_url( 'assets/css/subscription-form.css', DEOIA_SUBSCRIPTIONS_FILE ),
		array(),
		DEOIA_SUBSCRIPTIONS_VERSION
	);

	wp_register_script(
		'deoia-subscription-form',
		plugins_url( 'assets/js/subscription-form.js', DEOIA_SUBSCRIPTIONS_FILE ),
		array(),
		DEOIA_SUBSCRIPTIONS_VERSION,
		true
	);

	wp_localize_script(
		'deoia-subscription-form',
		'deoiaSubscriptions',
		array(
			'restUrl'             => esc_url_raw( rest_url( 'deoia/v1/start-subscription' ) ),
			'freemiumUrl'         => esc_url_raw( rest_url( 'deoia/v1/start-freemium-subscription' ) ),
			'slugAvailabilityUrl' => esc_url_raw( rest_url( 'deoia/v1/slug-availability' ) ),
			'freemiumRedirectUrl' => esc_url_raw( deoia_subscriptions_resolve_thank_you_page_url() ),
			'publicDomain'        => defined( 'DEOIA_SUBSCRIPTIONS_PUBLIC_DOMAIN' ) && is_string( constant( 'DEOIA_SUBSCRIPTIONS_PUBLIC_DOMAIN' ) ) && constant( 'DEOIA_SUBSCRIPTIONS_PUBLIC_DOMAIN' ) !== ''
				? sanitize_text_field( (string) constant( 'DEOIA_SUBSCRIPTIONS_PUBLIC_DOMAIN' ) )
				: 'deoia.com',
			'nonce'               => wp_create_nonce( 'wp_rest' ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'deoia_subscriptions_register_assets' );

/**
 * Shortcode: formulario mínimo de suscripción.
 *
 * @return string
 */
function deoia_subscriptions_render_form_shortcode(): string {
	wp_enqueue_style( 'deoia-subscription-form' );
	wp_enqueue_script( 'deoia-subscription-form' );

	$logo_url       = plugins_url( 'assets/img/deoia-citas-logo.svg', DEOIA_SUBSCRIPTIONS_FILE );
	$public_domain  = defined( 'DEOIA_SUBSCRIPTIONS_PUBLIC_DOMAIN' ) && is_string( constant( 'DEOIA_SUBSCRIPTIONS_PUBLIC_DOMAIN' ) ) && constant( 'DEOIA_SUBSCRIPTIONS_PUBLIC_DOMAIN' ) !== ''
		? (string) constant( 'DEOIA_SUBSCRIPTIONS_PUBLIC_DOMAIN' )
		: 'deoia.com';

	ob_start();
	?>
	<section class="deoia-subscription-portal" aria-labelledby="deoia-subscription-portal-title">
		<div class="deoia-subscription-portal__surface">
			<header class="deoia-subscription-portal__brand">
				<div class="deoia-subscription-portal__mark">
					<img
						class="deoia-subscription-portal__logo"
						src="<?php echo esc_url( $logo_url ); ?>"
						width="40"
						height="40"
						alt=""
						decoding="async"
					>
					<h2 id="deoia-subscription-portal-title" class="deoia-subscription-portal__title"><?php echo esc_html__( 'DEOIA Citas', 'deoia-subscriptions' ); ?></h2>
				</div>
				<p class="deoia-subscription-portal__headline"><?php echo esc_html__( 'Activa tu agenda profesional en minutos', 'deoia-subscriptions' ); ?></p>
				<p class="deoia-subscription-portal__description"><?php echo esc_html__( 'Crea tu agenda profesional con instalación lista para usar, acceso tipo app y funciones premium para gestionar tus citas desde el plan freemium sin costo para ti.', 'deoia-subscriptions' ); ?></p>
			</header>

			<div class="deoia-subscription-portal__card">
				<form class="deoia-subscription-form" id="deoia-subscription-form" novalidate>
				<p class="deoia-subscription-form__field">
					<label for="deoia-agenda-name"><?php echo esc_html__( 'Nombre de tu agenda', 'deoia-subscriptions' ); ?></label>
					<input type="text" id="deoia-agenda-name" name="agenda_name" required autocomplete="organization">
				</p>
				<p class="deoia-subscription-form__slug-status" id="deoia-slug-status" hidden></p>
				<p class="deoia-subscription-form__slug-field" id="deoia-slug-field-wrap" hidden>
					<label for="deoia-desired-slug"><?php echo esc_html__( 'Tu dirección de acceso', 'deoia-subscriptions' ); ?></label>
					<span class="deoia-subscription-form__slug-row">
						<input type="text" id="deoia-desired-slug" name="desired_slug_display" autocomplete="off" inputmode="url">
						<span class="deoia-subscription-form__slug-suffix">.<?php echo esc_html( $public_domain ); ?></span>
					</span>
					<small class="deoia-subscription-form__slug-hint"><?php echo esc_html__( 'Elige cómo se verá la dirección de tu agenda.', 'deoia-subscriptions' ); ?></small>
				</p>
				<p class="deoia-subscription-form__slug-suggestions" id="deoia-slug-suggestions" hidden></p>
				<input type="hidden" id="deoia-desired-slug-hidden" name="desired_slug" value="">
				<p class="deoia-subscription-form__field">
					<label for="deoia-email"><?php echo esc_html__( 'Correo electrónico', 'deoia-subscriptions' ); ?></label>
					<input type="email" id="deoia-email" name="email" required autocomplete="email">
				</p>
				<p class="deoia-subscription-form__field">
					<label for="deoia-owner-name"><?php echo esc_html__( 'Tu nombre', 'deoia-subscriptions' ); ?></label>
					<input type="text" id="deoia-owner-name" name="owner_name" required autocomplete="name">
				</p>
				<p class="deoia-subscription-form__errors" id="deoia-subscription-errors" hidden></p>
				<p class="deoia-subscription-form__actions">
					<button type="submit" class="deoia-subscription-form__submit" id="deoia-subscription-submit"><?php echo esc_html__( 'Crear mi agenda', 'deoia-subscriptions' ); ?></button>
				</p>
				<p class="deoia-subscription-form__trust"><?php echo esc_html__( 'Después de elegir tu suscripción recibirás el acceso a tu agenda.', 'deoia-subscriptions' ); ?></p>

				<div class="deoia-subscription-plans" id="deoia-subscription-plans" hidden>
					<p class="deoia-subscription-plans__heading"><?php echo esc_html__( 'Elige tu suscripción', 'deoia-subscriptions' ); ?></p>

					<div class="deoia-subscription-plan deoia-subscription-plan--freemium is-selected" id="deoia-plan-freemium">
						<div class="deoia-subscription-plan__head">
							<span class="deoia-subscription-plan__name"><?php echo esc_html__( 'Freemium', 'deoia-subscriptions' ); ?></span>
							<span class="deoia-subscription-plan__price"><?php echo esc_html__( '$0 / mes', 'deoia-subscriptions' ); ?></span>
						</div>
						<p class="deoia-subscription-plan__desc"><?php echo esc_html__( 'Tu agenda lista para usar, sin costo. Empieza hoy mismo.', 'deoia-subscriptions' ); ?></p>
						<button type="button" class="deoia-subscription-plan__cta" id="deoia-plan-freemium-cta"><?php echo esc_html__( 'Elegir Freemium', 'deoia-subscriptions' ); ?></button>
					</div>

					<div class="deoia-subscription-plan deoia-subscription-plan--pro" id="deoia-plan-pro">
						<div class="deoia-subscription-plan__head">
							<span class="deoia-subscription-plan__name"><?php echo esc_html__( 'PRO', 'deoia-subscriptions' ); ?></span>
							<span class="deoia-subscription-plan__price"><?php echo esc_html__( '$100 MXN / mes', 'deoia-subscriptions' ); ?></span>
						</div>
						<p class="deoia-subscription-plan__desc"><?php echo esc_html__( 'Funciones premium completas y soporte prioritario para tu agenda.', 'deoia-subscriptions' ); ?></p>
						<button type="button" class="deoia-subscription-plan__cta deoia-subscription-plan__cta--pro" id="deoia-plan-pro-cta"><?php echo esc_html__( 'Continuar con PRO', 'deoia-subscriptions' ); ?></button>
					</div>

					<button type="button" class="deoia-subscription-plans__back" id="deoia-plans-back"><?php echo esc_html__( 'Editar mis datos', 'deoia-subscriptions' ); ?></button>
				</div>
				</form>
			</div>

			<ul class="deoia-subscription-portal__features" aria-label="<?php echo esc_attr__( 'Incluye', 'deoia-subscriptions' ); ?>">
				<li><?php echo esc_html__( 'Agenda lista para usar', 'deoia-subscriptions' ); ?></li>
				<li><?php echo esc_html__( 'App instalable en tu celular', 'deoia-subscriptions' ); ?></li>
				<li><?php echo esc_html__( 'Funciones PRO activas', 'deoia-subscriptions' ); ?></li>
			</ul>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'deoia_subscription_form', 'deoia_subscriptions_render_form_shortcode' );
add_shortcode( 'deoia_account_portal', 'deoia_subscriptions_render_account_portal_shortcode' );

/**
 * Respuesta de error REST uniforme.
 *
 * @param string $message Mensaje para el cliente.
 * @param int    $status  Código HTTP.
 * @return WP_REST_Response
 */
function deoia_subscriptions_rest_error( string $message, int $status = 400 ): WP_REST_Response {
	return new WP_REST_Response(
		array( 'error' => $message ),
		$status
	);
}

/**
 * Comprueba que la URL del backend Node para POST /subscriptions/start esté definida.
 */
function deoia_subscriptions_backend_start_url_is_configured(): bool {
	return defined( 'DEOIA_SUBSCRIPTIONS_BACKEND_START_URL' )
		&& is_string( constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_START_URL' ) )
		&& constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_START_URL' ) !== '';
}

/**
 * Resuelve la URL del backend Node para GET /subscriptions/slug-availability.
 */
function deoia_subscriptions_backend_slug_availability_url(): ?string {
	if (
		defined( 'DEOIA_SUBSCRIPTIONS_BACKEND_SLUG_AVAILABILITY_URL' )
		&& is_string( constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_SLUG_AVAILABILITY_URL' ) )
		&& constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_SLUG_AVAILABILITY_URL' ) !== ''
	) {
		return (string) constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_SLUG_AVAILABILITY_URL' );
	}

	if ( ! deoia_subscriptions_backend_start_url_is_configured() ) {
		return null;
	}

	$start_url            = (string) constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_START_URL' );
	$normalized_start_url = rtrim( $start_url, '/' );
	if ( substr( $normalized_start_url, - strlen( '/subscriptions/start' ) ) !== '/subscriptions/start' ) {
		return null;
	}

	return substr( $normalized_start_url, 0, - strlen( '/subscriptions/start' ) ) . '/subscriptions/slug-availability';
}

/**
 * Resuelve la URL del backend Node para POST /subscriptions/start-freemium.
 *
 * Honra un override explícito y, si no existe, la deriva de la URL de start
 * reemplazando el sufijo /subscriptions/start por /subscriptions/start-freemium.
 */
function deoia_subscriptions_backend_freemium_start_url(): ?string {
	if (
		defined( 'DEOIA_SUBSCRIPTIONS_BACKEND_FREEMIUM_START_URL' )
		&& is_string( constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_FREEMIUM_START_URL' ) )
		&& constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_FREEMIUM_START_URL' ) !== ''
	) {
		return (string) constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_FREEMIUM_START_URL' );
	}

	if ( ! deoia_subscriptions_backend_start_url_is_configured() ) {
		return null;
	}

	$start_url            = (string) constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_START_URL' );
	$normalized_start_url = rtrim( $start_url, '/' );
	if ( substr( $normalized_start_url, - strlen( '/subscriptions/start' ) ) !== '/subscriptions/start' ) {
		return null;
	}

	return substr( $normalized_start_url, 0, - strlen( '/subscriptions/start' ) ) . '/subscriptions/start-freemium';
}

/**
 * Comprueba que DEOIA_STRIPE_SUCCESS_URL esté definida (misma fuente que el return URL de PRO).
 */
function deoia_subscriptions_stripe_success_url_is_configured(): bool {
	return defined( 'DEOIA_STRIPE_SUCCESS_URL' )
		&& is_string( constant( 'DEOIA_STRIPE_SUCCESS_URL' ) )
		&& constant( 'DEOIA_STRIPE_SUCCESS_URL' ) !== '';
}

/**
 * URL canónica de la página de gracias para redirects post-suscripción (Freemium).
 *
 * Prioridad:
 * 1. Base de DEOIA_STRIPE_SUCCESS_URL (sin query), alineada con el return URL de Stripe/PRO.
 * 2. get_permalink() de la página con slug "gracias" (respeta index.php y permalinks).
 * 3. home_url() sobre el slug como último recurso.
 *
 * @return string
 */
function deoia_subscriptions_resolve_thank_you_page_url(): string {
	if ( deoia_subscriptions_stripe_success_url_is_configured() ) {
		$success_url = (string) constant( 'DEOIA_STRIPE_SUCCESS_URL' );
		$base        = strtok( $success_url, '?' );
		if ( is_string( $base ) && $base !== '' ) {
			return trailingslashit( $base );
		}
	}

	$page = get_page_by_path( 'gracias', OBJECT, 'page' );
	if ( $page instanceof WP_Post ) {
		$permalink = get_permalink( $page );
		if ( is_string( $permalink ) && $permalink !== '' ) {
			return $permalink;
		}
	}

	return trailingslashit( home_url( '/gracias/' ) );
}

/**
 * Comprueba que las constantes de Stripe estén definidas y no vacías.
 *
 * No usado por start-subscription (el checkout lo crea el backend Node). Se conserva por compatibilidad.
 */
function deoia_subscriptions_stripe_is_configured(): bool {
	return defined( 'DEOIA_STRIPE_SECRET_KEY' )
		&& is_string( constant( 'DEOIA_STRIPE_SECRET_KEY' ) )
		&& constant( 'DEOIA_STRIPE_SECRET_KEY' ) !== ''
		&& defined( 'DEOIA_STRIPE_PRICE_ID' )
		&& is_string( constant( 'DEOIA_STRIPE_PRICE_ID' ) )
		&& constant( 'DEOIA_STRIPE_PRICE_ID' ) !== ''
		&& defined( 'DEOIA_STRIPE_SUCCESS_URL' )
		&& is_string( constant( 'DEOIA_STRIPE_SUCCESS_URL' ) )
		&& constant( 'DEOIA_STRIPE_SUCCESS_URL' ) !== ''
		&& defined( 'DEOIA_STRIPE_CANCEL_URL' )
		&& is_string( constant( 'DEOIA_STRIPE_CANCEL_URL' ) )
		&& constant( 'DEOIA_STRIPE_CANCEL_URL' ) !== '';
}

/**
 * Crea una Checkout Session de Stripe (modo suscripción) vía API HTTP.
 *
 * No usado por start-subscription (el checkout lo crea el backend Node). Se conserva por compatibilidad.
 *
 * @param string $agenda_name Nombre de la agenda (metadata).
 * @param string $email       Correo (customer_email + metadata).
 * @param string $owner_name  Nombre del titular (metadata).
 * @return string|null URL de checkout o null si falla.
 */
function deoia_subscriptions_stripe_create_checkout_session( string $agenda_name, string $email, string $owner_name ): ?string {
	$secret_key   = constant( 'DEOIA_STRIPE_SECRET_KEY' );
	$price_id     = constant( 'DEOIA_STRIPE_PRICE_ID' );
	$success_url  = constant( 'DEOIA_STRIPE_SUCCESS_URL' );
	$cancel_url   = constant( 'DEOIA_STRIPE_CANCEL_URL' );

	$body = array(
		'mode'             => 'subscription',
		'customer_email'   => $email,
		'success_url'      => $success_url,
		'cancel_url'       => $cancel_url,
		'line_items'       => array(
			array(
				'price'    => $price_id,
				'quantity' => 1,
			),
		),
		'metadata'         => array(
			'agenda_name' => $agenda_name,
			'owner_name'  => $owner_name,
			'email'       => $email,
		),
	);

	$response = wp_remote_post(
		'https://api.stripe.com/v1/checkout/sessions',
		array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $secret_key . ':' ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'    => http_build_query( $body ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return null;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$raw  = (string) wp_remote_retrieve_body( $response );
	$data = json_decode( $raw, true );

	if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
		return null;
	}

	if ( empty( $data['url'] ) || ! is_string( $data['url'] ) ) {
		return null;
	}

	return $data['url'];
}

/**
 * POST /wp-json/deoia/v1/start-subscription
 *
 * @param WP_REST_Request $request Solicitud.
 * @return WP_REST_Response
 */
function deoia_subscriptions_rest_start_subscription( WP_REST_Request $request ): WP_REST_Response {
	$agenda_name = sanitize_text_field( trim( (string) $request->get_param( 'agenda_name' ) ) );
	$email       = sanitize_email( trim( (string) $request->get_param( 'email' ) ) );
	$owner_name  = sanitize_text_field( trim( (string) $request->get_param( 'owner_name' ) ) );
	$desired_slug = sanitize_text_field( trim( (string) $request->get_param( 'desired_slug' ) ) );

	if ( $agenda_name === '' || $owner_name === '' ) {
		return deoia_subscriptions_rest_error( __( 'Todos los campos son obligatorios.', 'deoia-subscriptions' ), 400 );
	}

	if ( $email === '' || ! is_email( $email ) ) {
		return deoia_subscriptions_rest_error( __( 'Correo electrónico no válido.', 'deoia-subscriptions' ), 400 );
	}

	if ( ! deoia_subscriptions_backend_start_url_is_configured() ) {
		return deoia_subscriptions_rest_error( 'Backend de suscripciones no configurado.', 500 );
	}

	$backend_url = (string) constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_START_URL' );

	$response = wp_remote_post(
		$backend_url,
		array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'agenda_name' => $agenda_name,
					'owner_name'  => $owner_name,
					'email'       => $email,
					'desired_slug' => $desired_slug,
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return deoia_subscriptions_rest_error( 'No se pudo iniciar la suscripción.', 500 );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$raw  = (string) wp_remote_retrieve_body( $response );
	$data = json_decode( $raw, true );

	if ( ! is_array( $data ) ) {
		return deoia_subscriptions_rest_error( 'No se pudo iniciar la suscripción.', 500 );
	}

	if ( $code < 200 || $code >= 300 ) {
		return new WP_REST_Response( $data, $code );
	}

	if ( empty( $data['checkout_url'] ) || ! is_string( $data['checkout_url'] ) ) {
		return deoia_subscriptions_rest_error( 'No se pudo iniciar la suscripción.', 500 );
	}

	return new WP_REST_Response(
		array(
			'checkout_url' => $data['checkout_url'],
		),
		200
	);
}

/**
 * POST /wp-json/deoia/v1/start-freemium-subscription
 *
 * Proxy same-origin hacia el backend Node POST /subscriptions/start-freemium.
 * No pasa por Stripe: el backend reserva el slug, crea la cuenta/suscripción
 * Freemium y encola el provisioning.
 *
 * @param WP_REST_Request $request Solicitud.
 * @return WP_REST_Response
 */
function deoia_subscriptions_rest_start_freemium_subscription( WP_REST_Request $request ): WP_REST_Response {
	$agenda_name  = sanitize_text_field( trim( (string) $request->get_param( 'agenda_name' ) ) );
	$email        = sanitize_email( trim( (string) $request->get_param( 'email' ) ) );
	$owner_name   = sanitize_text_field( trim( (string) $request->get_param( 'owner_name' ) ) );
	$desired_slug = sanitize_text_field( trim( (string) $request->get_param( 'desired_slug' ) ) );

	if ( $agenda_name === '' || $owner_name === '' ) {
		return deoia_subscriptions_rest_error( __( 'Todos los campos son obligatorios.', 'deoia-subscriptions' ), 400 );
	}

	if ( $email === '' || ! is_email( $email ) ) {
		return deoia_subscriptions_rest_error( __( 'Correo electrónico no válido.', 'deoia-subscriptions' ), 400 );
	}

	$backend_url = deoia_subscriptions_backend_freemium_start_url();
	if ( $backend_url === null ) {
		return deoia_subscriptions_rest_error( 'Backend de suscripciones no configurado.', 500 );
	}

	$response = wp_remote_post(
		$backend_url,
		array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'agenda_name'  => $agenda_name,
					'owner_name'   => $owner_name,
					'email'        => $email,
					'desired_slug' => $desired_slug,
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return deoia_subscriptions_rest_error( 'No se pudo crear tu agenda Freemium.', 500 );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$raw  = (string) wp_remote_retrieve_body( $response );
	$data = json_decode( $raw, true );

	if ( ! is_array( $data ) ) {
		return deoia_subscriptions_rest_error( 'No se pudo crear tu agenda Freemium.', 500 );
	}

	if ( $code < 200 || $code >= 300 ) {
		return new WP_REST_Response( $data, $code );
	}

	return new WP_REST_Response( $data, 200 );
}

/**
 * GET /wp-json/deoia/v1/slug-availability
 *
 * Proxy same-origin hacia el backend Node. No reserva el slug.
 *
 * @param WP_REST_Request $request Solicitud.
 * @return WP_REST_Response
 */
function deoia_subscriptions_rest_slug_availability( WP_REST_Request $request ): WP_REST_Response {
	$slug = sanitize_text_field( trim( (string) $request->get_param( 'slug' ) ) );

	$backend_url = deoia_subscriptions_backend_slug_availability_url();
	if ( $backend_url === null ) {
		return deoia_subscriptions_rest_error( 'Backend de disponibilidad no configurado.', 500 );
	}

	$url = add_query_arg(
		array(
			'slug' => $slug,
		),
		$backend_url
	);

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 15,
			'headers' => array(
				'Accept' => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return deoia_subscriptions_rest_error( 'No pudimos comprobar el subdominio ahora.', 500 );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$raw  = (string) wp_remote_retrieve_body( $response );
	$data = json_decode( $raw, true );

	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
		return deoia_subscriptions_rest_error( 'Respuesta inválida del backend de disponibilidad.', 500 );
	}

	return new WP_REST_Response( $data, $code );
}

/**
 * Comprueba que exista el secreto del webhook de Stripe.
 */
function deoia_subscriptions_webhook_secret_is_configured(): bool {
	return defined( 'DEOIA_STRIPE_WEBHOOK_SECRET' )
		&& is_string( constant( 'DEOIA_STRIPE_WEBHOOK_SECRET' ) )
		&& constant( 'DEOIA_STRIPE_WEBHOOK_SECRET' ) !== '';
}

/**
 * Verifica la cabecera Stripe-Signature (esquema v1) sin SDK.
 *
 * @param string $raw_body   Cuerpo crudo de la petición.
 * @param string $sig_header Valor del header stripe-signature.
 * @param string $secret     DEOIA_STRIPE_WEBHOOK_SECRET.
 */
function deoia_subscriptions_verify_stripe_webhook_signature( string $raw_body, string $sig_header, string $secret ): bool {
	$timestamp = null;
	$v1_list     = array();

	$chunks = explode( ',', $sig_header );
	foreach ( $chunks as $chunk ) {
		$chunk = trim( $chunk );
		if ( $chunk === '' ) {
			continue;
		}
		$pair = explode( '=', $chunk, 2 );
		if ( count( $pair ) !== 2 ) {
			continue;
		}
		$key = trim( $pair[0] );
		$val = $pair[1];
		if ( $key === 't' ) {
			$timestamp = $val;
		} elseif ( $key === 'v1' ) {
			$v1_list[] = $val;
		}
	}

	if ( $timestamp === null || $timestamp === '' || $v1_list === array() ) {
		return false;
	}

	$signed_payload = $timestamp . '.' . $raw_body;
	$expected       = hash_hmac( 'sha256', $signed_payload, $secret );

	foreach ( $v1_list as $v1 ) {
		if ( hash_equals( $expected, $v1 ) ) {
			return true;
		}
	}

	return false;
}

/**
 * POST /wp-json/deoia/v1/stripe-webhook
 *
 * @param WP_REST_Request $request Solicitud.
 * @return WP_REST_Response
 */
function deoia_subscriptions_rest_stripe_webhook( WP_REST_Request $request ): WP_REST_Response {
	if ( ! deoia_subscriptions_webhook_secret_is_configured() ) {
		return deoia_subscriptions_rest_error( 'Stripe webhook no está configurado.', 500 );
	}

	$raw_body   = $request->get_body();
	$sig_header = $request->get_header( 'stripe_signature' );
	if ( ! is_string( $sig_header ) ) {
		$sig_header = $request->get_header( 'stripe-signature' );
	}
	$sig_header = is_string( $sig_header ) ? $sig_header : '';

	$secret = constant( 'DEOIA_STRIPE_WEBHOOK_SECRET' );

	if ( $sig_header === '' || ! deoia_subscriptions_verify_stripe_webhook_signature( $raw_body, $sig_header, $secret ) ) {
		return deoia_subscriptions_rest_error( 'Firma inválida.', 400 );
	}

	$data = json_decode( $raw_body, true );
	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
		return deoia_subscriptions_rest_error( 'Evento inválido.', 400 );
	}

	$event_type = isset( $data['type'] ) && is_string( $data['type'] ) ? $data['type'] : '';

	if ( $event_type === 'checkout.session.completed' ) {
		$event_id = isset( $data['id'] ) && is_string( $data['id'] ) ? $data['id'] : '';
		$session  = isset( $data['data']['object'] ) && is_array( $data['data']['object'] ) ? $data['data']['object'] : array();

		$session_id     = isset( $session['id'] ) && is_string( $session['id'] ) ? $session['id'] : '';
		$customer       = isset( $session['customer'] ) && is_string( $session['customer'] ) ? $session['customer'] : '';
		$subscription   = isset( $session['subscription'] ) && is_string( $session['subscription'] ) ? $session['subscription'] : '';
		$customer_email = isset( $session['customer_email'] ) && is_string( $session['customer_email'] ) ? $session['customer_email'] : '';
		$metadata       = isset( $session['metadata'] ) && is_array( $session['metadata'] ) ? $session['metadata'] : array();
		$meta_agenda    = isset( $metadata['agenda_name'] ) && is_string( $metadata['agenda_name'] ) ? $metadata['agenda_name'] : '';
		$meta_owner     = isset( $metadata['owner_name'] ) && is_string( $metadata['owner_name'] ) ? $metadata['owner_name'] : '';
		$meta_email     = isset( $metadata['email'] ) && is_string( $metadata['email'] ) ? $metadata['email'] : '';

		error_log(
			sprintf(
				'DEOIA Stripe webhook checkout.session.completed: session=%s, email=%s, agenda=%s, event=%s, type=%s, customer=%s, subscription=%s, owner_name=%s, metadata.email=%s',
				$session_id,
				$customer_email,
				$meta_agenda,
				$event_id,
				$event_type,
				$customer,
				$subscription,
				$meta_owner,
				$meta_email
			)
		);
	}

	return new WP_REST_Response(
		array( 'received' => true ),
		200
	);
}

/**
 * Registra rutas REST.
 */
function deoia_subscriptions_register_rest_routes(): void {
	register_rest_route(
		'deoia/v1',
		'/start-subscription',
		array(
			'methods'             => 'POST',
			'callback'            => 'deoia_subscriptions_rest_start_subscription',
			'permission_callback' => '__return_true',
			'args'                => array(
				'agenda_name' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'email'       => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_email',
				),
				'owner_name'  => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'desired_slug' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	register_rest_route(
		'deoia/v1',
		'/start-freemium-subscription',
		array(
			'methods'             => 'POST',
			'callback'            => 'deoia_subscriptions_rest_start_freemium_subscription',
			'permission_callback' => '__return_true',
			'args'                => array(
				'agenda_name'  => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'email'        => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_email',
				),
				'owner_name'   => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'desired_slug' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	register_rest_route(
		'deoia/v1',
		'/stripe-webhook',
		array(
			'methods'             => 'POST',
			'callback'            => 'deoia_subscriptions_rest_stripe_webhook',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		'deoia/v1',
		'/slug-availability',
		array(
			'methods'             => 'GET',
			'callback'            => 'deoia_subscriptions_rest_slug_availability',
			'permission_callback' => '__return_true',
			'args'                => array(
				'slug' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'deoia_subscriptions_register_rest_routes' );
