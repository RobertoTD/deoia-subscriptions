<?php
/**
 * Portal access request: form UI + server-side POST to backend (M3.6).
 *
 * @package DeoiaSubscriptions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DEOIA_PORTAL_ACCESS_REQUEST_NEUTRAL_MESSAGE = 'Si los datos son correctos, enviaremos un enlace de acceso.';

const DEOIA_PORTAL_ACCESS_REQUEST_ACTION = 'deoia_portal_access_request';

/**
 * Resolves backend POST /portal/access/request URL.
 */
function deoia_subscriptions_backend_portal_request_url(): ?string {
	if (
		defined( 'DEOIA_SUBSCRIPTIONS_BACKEND_PORTAL_REQUEST_URL' )
		&& is_string( constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_PORTAL_REQUEST_URL' ) )
		&& constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_PORTAL_REQUEST_URL' ) !== ''
	) {
		return (string) constant( 'DEOIA_SUBSCRIPTIONS_BACKEND_PORTAL_REQUEST_URL' );
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

	return substr( $start_url, 0, -$suffix_length ) . '/portal/access/request';
}

/**
 * Whether the current request is the portal access page (not verify).
 */
function deoia_subscriptions_portal_is_access_page_view(): bool {
	if ( function_exists( 'deoia_subscriptions_portal_is_verify_request' )
		&& deoia_subscriptions_portal_is_verify_request() ) {
		return false;
	}

	if ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ) {
		$uri = wp_unslash( $_SERVER['REQUEST_URI'] );
		if ( is_string( $uri ) ) {
			if ( strpos( $uri, '/account/access/verify' ) !== false ) {
				return false;
			}
			if ( strpos( $uri, '/account/access' ) !== false ) {
				return true;
			}
		}
	}

	if ( ! is_page() ) {
		return false;
	}

	$page = get_queried_object();
	if ( ! $page instanceof WP_Post || $page->post_name !== 'access' ) {
		return false;
	}

	if ( ! $page->post_parent ) {
		return true;
	}

	$parent = get_post( (int) $page->post_parent );
	return $parent instanceof WP_Post && $parent->post_name === 'account';
}

/**
 * Whether PRG flag portal_access_sent=1 is present.
 */
function deoia_subscriptions_portal_get_portal_access_sent_from_request(): bool {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only success flag.
	if ( ! isset( $_GET['portal_access_sent'] ) ) {
		return false;
	}

	$raw = wp_unslash( $_GET['portal_access_sent'] );
	return $raw === '1' || $raw === 1;
}

/**
 * Whether PRG flag portal_access_unavailable=1 is present.
 */
function deoia_subscriptions_portal_get_portal_access_unavailable_from_request(): bool {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only error flag.
	if ( ! isset( $_GET['portal_access_unavailable'] ) ) {
		return false;
	}

	$raw = wp_unslash( $_GET['portal_access_unavailable'] );
	return $raw === '1' || $raw === 1;
}

/**
 * Submits access request to backend (server-side only). Does not log PII.
 *
 * @param string $installation_slug
 * @param string $email
 * @return bool True when backend responds HTTP 200.
 */
function deoia_subscriptions_portal_submit_access_request( string $installation_slug, string $email ): bool {
	$backend_url = deoia_subscriptions_backend_portal_request_url();
	if ( $backend_url === null || $backend_url === '' ) {
		return false;
	}

	$response = wp_remote_post(
		$backend_url,
		array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'installation' => $installation_slug,
					'email'        => $email,
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return false;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	return $code === 200;
}

/**
 * Redirects after POST (PRG). Preserves installation query when provided.
 *
 * @param string $installation_slug
 * @param bool   $success
 */
function deoia_subscriptions_portal_redirect_after_access_request( string $installation_slug, bool $success ): void {
	$url = deoia_subscriptions_portal_access_url();

	if ( $installation_slug !== '' ) {
		$url = add_query_arg( 'installation', $installation_slug, $url );
	}

	if ( $success ) {
		$url = add_query_arg( 'portal_access_sent', '1', $url );
	} else {
		$url = add_query_arg( 'portal_access_unavailable', '1', $url );
	}

	wp_safe_redirect( $url );
	exit;
}

/**
 * template_redirect: handle access form POST before output (priority 2).
 */
function deoia_subscriptions_portal_handle_access_request_post(): void {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}

	if ( ! deoia_subscriptions_portal_is_access_page_view() ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) !== 'POST' ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.
	if ( ! isset( $_POST['deoia_portal_access_action'] ) || sanitize_key( wp_unslash( (string) $_POST['deoia_portal_access_action'] ) ) !== 'request' ) {
		return;
	}

	$nonce = isset( $_POST['deoia_portal_access_nonce'] )
		? sanitize_text_field( wp_unslash( (string) $_POST['deoia_portal_access_nonce'] ) )
		: '';

	if ( $nonce === '' || ! wp_verify_nonce( $nonce, DEOIA_PORTAL_ACCESS_REQUEST_ACTION ) ) {
		deoia_subscriptions_portal_redirect_after_access_request( '', false );
	}

	$installation = isset( $_POST['deoia_portal_installation'] )
		? sanitize_text_field( trim( wp_unslash( (string) $_POST['deoia_portal_installation'] ) ) )
		: '';

	$email = isset( $_POST['deoia_portal_email'] )
		? sanitize_email( trim( wp_unslash( (string) $_POST['deoia_portal_email'] ) ) )
		: '';

	$success = deoia_subscriptions_portal_submit_access_request( $installation, $email );
	deoia_subscriptions_portal_redirect_after_access_request( $installation, $success );
}

/**
 * Renders the access-request page (form + notices). Used from account portal shortcode.
 *
 * @return string
 */
function deoia_subscriptions_render_portal_access_request_view(): string {
	$slug_from_query = deoia_subscriptions_portal_get_installation_slug_from_request();
	$slug_locked     = $slug_from_query !== '' && deoia_subscriptions_portal_is_slug_format_valid( $slug_from_query );
	$slug_prefill    = $slug_locked ? $slug_from_query : '';

	$portal_error = function_exists( 'deoia_subscriptions_portal_get_portal_error_from_request' )
		? deoia_subscriptions_portal_get_portal_error_from_request()
		: '';

	$access_sent         = deoia_subscriptions_portal_get_portal_access_sent_from_request();
	$access_unavailable  = deoia_subscriptions_portal_get_portal_access_unavailable_from_request();

	$form_action = deoia_subscriptions_portal_access_url();
	if ( $slug_prefill !== '' ) {
		$form_action = add_query_arg( 'installation', $slug_prefill, $form_action );
	}

	ob_start();
	?>
	<div class="deoia-account-portal deoia-account-portal--access" id="deoia-account-portal">
		<?php if ( $portal_error === 'invalid_link' ) : ?>
			<section class="deoia-account-portal__notice deoia-account-portal__notice--warn" role="alert">
				<p><?php echo esc_html__( 'El enlace no es válido o expiró. Solicita uno nuevo.', 'deoia-subscriptions' ); ?></p>
			</section>
		<?php endif; ?>

		<?php if ( $access_sent ) : ?>
			<section class="deoia-account-portal__notice deoia-account-portal__notice--ok" role="status">
				<p><?php echo esc_html( DEOIA_PORTAL_ACCESS_REQUEST_NEUTRAL_MESSAGE ); ?></p>
			</section>
		<?php endif; ?>

		<?php if ( $access_unavailable ) : ?>
			<section class="deoia-account-portal__notice deoia-account-portal__notice--warn" role="alert">
				<p><?php echo esc_html__( 'No pudimos procesar tu solicitud en este momento. Inténtalo más tarde.', 'deoia-subscriptions' ); ?></p>
			</section>
		<?php endif; ?>

		<header class="deoia-account-portal__header">
			<h2 class="deoia-account-portal__title"><?php echo esc_html__( 'Acceso al portal', 'deoia-subscriptions' ); ?></h2>
			<p class="deoia-account-portal__subtitle">
				<?php echo esc_html__( 'Introduce el correo autorizado para tu agenda. Te enviaremos un enlace de acceso.', 'deoia-subscriptions' ); ?>
			</p>
		</header>

		<section class="deoia-account-portal__access-form-wrap" aria-labelledby="deoia-portal-access-form-heading">
			<h3 id="deoia-portal-access-form-heading" class="deoia-account-portal__section-title">
				<?php echo esc_html__( 'Solicitar enlace de acceso', 'deoia-subscriptions' ); ?>
			</h3>

			<form class="deoia-account-portal__form" method="post" action="<?php echo esc_url( $form_action ); ?>">
				<?php wp_nonce_field( DEOIA_PORTAL_ACCESS_REQUEST_ACTION, 'deoia_portal_access_nonce' ); ?>
				<input type="hidden" name="deoia_portal_access_action" value="request" />

				<?php if ( $slug_locked ) : ?>
					<input type="hidden" name="deoia_portal_installation" value="<?php echo esc_attr( $slug_prefill ); ?>" />
					<p class="deoia-account-portal__field">
						<span class="deoia-account-portal__label"><?php echo esc_html__( 'Agenda', 'deoia-subscriptions' ); ?></span>
						<code class="deoia-account-portal__slug-readonly"><?php echo esc_html( $slug_prefill ); ?></code>
					</p>
				<?php else : ?>
					<p class="deoia-account-portal__field">
						<label class="deoia-account-portal__label" for="deoia-portal-installation">
							<?php echo esc_html__( 'Slug de tu agenda', 'deoia-subscriptions' ); ?>
						</label>
						<input
							class="deoia-account-portal__input"
							type="text"
							id="deoia-portal-installation"
							name="deoia_portal_installation"
							value="<?php echo esc_attr( $slug_from_query ); ?>"
							autocomplete="off"
							spellcheck="false"
							required
						/>
					</p>
				<?php endif; ?>

				<p class="deoia-account-portal__field">
					<label class="deoia-account-portal__label" for="deoia-portal-email">
						<?php echo esc_html__( 'Correo electrónico', 'deoia-subscriptions' ); ?>
					</label>
					<input
						class="deoia-account-portal__input"
						type="email"
						id="deoia-portal-email"
						name="deoia_portal_email"
						value=""
						autocomplete="email"
						required
					/>
				</p>

				<p class="deoia-account-portal__form-actions">
					<button type="submit" class="deoia-account-portal__submit">
						<?php echo esc_html__( 'Enviar enlace de acceso', 'deoia-subscriptions' ); ?>
					</button>
				</p>
			</form>
		</section>
	</div>
	<?php
	return (string) ob_get_clean();
}
