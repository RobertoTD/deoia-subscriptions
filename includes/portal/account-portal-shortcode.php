<?php
/**
 * Shortcode [deoia_account_portal] — dashboard placeholder del Portal DEOIA (MVP).
 *
 * Sin auth, sin backend, sin datos reales. Solo PHP + CSS del plugin.
 *
 * @package DeoiaSubscriptions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dominio público para copy de demostración (no enlaces productivos a tenants reales).
 */
function deoia_subscriptions_portal_public_domain_label(): string {
	if (
		defined( 'DEOIA_SUBSCRIPTIONS_PUBLIC_DOMAIN' )
		&& is_string( constant( 'DEOIA_SUBSCRIPTIONS_PUBLIC_DOMAIN' ) )
		&& constant( 'DEOIA_SUBSCRIPTIONS_PUBLIC_DOMAIN' ) !== ''
	) {
		return sanitize_text_field( (string) constant( 'DEOIA_SUBSCRIPTIONS_PUBLIC_DOMAIN' ) );
	}
	return 'deoia.com';
}

/**
 * Lee y sanitiza ?installation= desde la query string.
 *
 * @return string Slug vacío si no viene o no es string usable.
 */
function deoia_subscriptions_portal_get_installation_slug_from_request(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Vista pública placeholder sin mutación.
	if ( ! isset( $_GET['installation'] ) ) {
		return '';
	}
	$raw = wp_unslash( $_GET['installation'] );
	if ( ! is_string( $raw ) ) {
		return '';
	}
	return sanitize_text_field( trim( $raw ) );
}

/**
 * Validación ligera de formato de slug (solo UI; no reserva ni consulta backend).
 *
 * @param string $slug
 * @return bool
 */
function deoia_subscriptions_portal_is_slug_format_valid( string $slug ): bool {
	if ( $slug === '' ) {
		return false;
	}
	$length = strlen( $slug );
	if ( $length < 3 || $length > 40 ) {
		return false;
	}
	return (bool) preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug );
}

/**
 * Datos placeholder para una instalación en contexto (demostración).
 *
 * @param string $slug Slug canónico mostrado en UI.
 * @return array<string, mixed>
 */
function deoia_subscriptions_portal_build_placeholder_context( string $slug ): array {
	$domain = deoia_subscriptions_portal_public_domain_label();

	return array(
		'installation_slug'      => $slug,
		'agenda_name'            => sprintf(
			/* translators: %s: installation slug */
			__( 'Agenda demo (%s)', 'deoia-subscriptions' ),
			$slug
		),
		'installation_status'    => __( 'Activa (demo)', 'deoia-subscriptions' ),
		'installation_type'      => __( 'Sitio gestionado (demo)', 'deoia-subscriptions' ),
		'subscription_status'    => __( 'Activa (demo)', 'deoia-subscriptions' ),
		'plan_label'             => __( 'Pro (demo)', 'deoia-subscriptions' ),
		'account_email'          => __( 'cliente@ejemplo.demo', 'deoia-subscriptions' ),
		'billing_summary'        => __( 'Facturación gestionada por Stripe — próximamente en el portal.', 'deoia-subscriptions' ),
		'support_summary'        => __( 'Soporte: atencion@deoia.com', 'deoia-subscriptions' ),
		'messages'               => array(
			__( 'Esta es una vista inicial del portal con datos de demostración.', 'deoia-subscriptions' ),
			__( 'El acceso con correo y enlace seguro se habilitará en una etapa posterior.', 'deoia-subscriptions' ),
			sprintf(
				/* translators: %s: public domain label */
				__( 'Contexto de instalación: %s (solo referencia visual).', 'deoia-subscriptions' ),
				$slug
			),
		),
		'agenda_url_hint'        => sprintf( '%s/%s/', $domain, $slug ),
	);
}

/**
 * @param string $status_label
 * @return string
 */
function deoia_subscriptions_portal_status_modifier( string $status_label ): string {
	$lower = strtolower( $status_label );
	if ( str_contains( $lower, 'activ' ) ) {
		return 'deoia-account-portal__badge--ok';
	}
	if ( str_contains( $lower, 'pend' ) ) {
		return 'deoia-account-portal__badge--warn';
	}
	return 'deoia-account-portal__badge--neutral';
}

/**
 * Shortcode: dashboard mínimo del portal (placeholder).
 *
 * @return string
 */
function deoia_subscriptions_render_account_portal_shortcode(): string {
	wp_enqueue_style( 'deoia-account-portal' );

	$slug_raw      = deoia_subscriptions_portal_get_installation_slug_from_request();
	$has_slug      = $slug_raw !== '';
	$slug_valid    = $has_slug && deoia_subscriptions_portal_is_slug_format_valid( $slug_raw );
	$context       = $slug_valid ? deoia_subscriptions_portal_build_placeholder_context( $slug_raw ) : null;
	$portal_error  = function_exists( 'deoia_subscriptions_portal_get_portal_error_from_request' )
		? deoia_subscriptions_portal_get_portal_error_from_request()
		: '';

	ob_start();
	?>
	<div class="deoia-account-portal" id="deoia-account-portal">
		<?php if ( $portal_error === 'invalid_link' ) : ?>
			<section class="deoia-account-portal__notice deoia-account-portal__notice--warn" role="alert">
				<p><?php echo esc_html__( 'El enlace no es válido o expiró. Solicita uno nuevo.', 'deoia-subscriptions' ); ?></p>
			</section>
		<?php endif; ?>

		<aside class="deoia-account-portal__banner" role="note">
			<strong><?php echo esc_html__( 'Vista inicial del portal — datos de demostración', 'deoia-subscriptions' ); ?></strong>
			<p><?php echo esc_html__( 'Nada de lo que ves aquí proviene de tu cuenta real. El acceso seguro y los datos en vivo llegarán en etapas posteriores.', 'deoia-subscriptions' ); ?></p>
		</aside>

		<header class="deoia-account-portal__header">
			<h2 class="deoia-account-portal__title"><?php echo esc_html__( 'Portal DEOIA', 'deoia-subscriptions' ); ?></h2>
			<p class="deoia-account-portal__subtitle"><?php echo esc_html__( 'Cuenta, suscripción y acceso a tu agenda', 'deoia-subscriptions' ); ?></p>
		</header>

		<?php if ( ! $has_slug ) : ?>
			<section class="deoia-account-portal__empty" aria-labelledby="deoia-portal-empty-heading">
				<h3 id="deoia-portal-empty-heading" class="deoia-account-portal__section-title">
					<?php echo esc_html__( 'Sin instalación en contexto', 'deoia-subscriptions' ); ?>
				</h3>
				<p><?php echo esc_html__( 'Selecciona o accede a una agenda para ver el panel. En desarrollo puedes usar el parámetro de URL:', 'deoia-subscriptions' ); ?></p>
				<p class="deoia-account-portal__hint">
					<code>?installation=tu-slug</code>
				</p>
				<p class="deoia-account-portal__hint">
					<?php echo esc_html__( 'Ejemplo:', 'deoia-subscriptions' ); ?>
					<code>/account/access/?installation=zorro8</code>
				</p>
			</section>
		<?php else : ?>
			<?php if ( ! $slug_valid ) : ?>
				<section class="deoia-account-portal__notice deoia-account-portal__notice--warn" role="alert">
					<p><?php echo esc_html__( 'El valor de instalación no tiene un formato de slug válido. Usa solo letras minúsculas, números y guiones (3–40 caracteres).', 'deoia-subscriptions' ); ?></p>
				</section>
			<?php endif; ?>

			<?php if ( $context !== null ) : ?>
				<section class="deoia-account-portal__grid" aria-label="<?php echo esc_attr__( 'Resumen de la instalación', 'deoia-subscriptions' ); ?>">
					<article class="deoia-account-portal__card deoia-account-portal__card--primary">
						<h3 class="deoia-account-portal__card-title"><?php echo esc_html__( 'Agenda', 'deoia-subscriptions' ); ?></h3>
						<p class="deoia-account-portal__value"><?php echo esc_html( (string) $context['agenda_name'] ); ?></p>
						<dl class="deoia-account-portal__meta">
							<div>
								<dt><?php echo esc_html__( 'Slug de instalación', 'deoia-subscriptions' ); ?></dt>
								<dd><code><?php echo esc_html( (string) $context['installation_slug'] ); ?></code></dd>
							</div>
							<div>
								<dt><?php echo esc_html__( 'Tipo', 'deoia-subscriptions' ); ?></dt>
								<dd><?php echo esc_html( (string) $context['installation_type'] ); ?></dd>
							</div>
						</dl>
						<p class="deoia-account-portal__actions">
							<span class="deoia-account-portal__btn deoia-account-portal__btn--demo" aria-disabled="true">
								<?php echo esc_html__( 'Abrir agenda (demostración)', 'deoia-subscriptions' ); ?>
							</span>
							<small class="deoia-account-portal__btn-note">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: example URL path, not a live link */
										__( 'Enlace no activo. Ejemplo futuro: %s', 'deoia-subscriptions' ),
										(string) $context['agenda_url_hint']
									)
								);
								?>
							</small>
						</p>
					</article>

					<article class="deoia-account-portal__card">
						<h3 class="deoia-account-portal__card-title"><?php echo esc_html__( 'Estado de instalación', 'deoia-subscriptions' ); ?></h3>
						<p>
							<span class="deoia-account-portal__badge <?php echo esc_attr( deoia_subscriptions_portal_status_modifier( (string) $context['installation_status'] ) ); ?>">
								<?php echo esc_html( (string) $context['installation_status'] ); ?>
							</span>
						</p>
					</article>

					<article class="deoia-account-portal__card">
						<h3 class="deoia-account-portal__card-title"><?php echo esc_html__( 'Suscripción', 'deoia-subscriptions' ); ?></h3>
						<p>
							<span class="deoia-account-portal__badge <?php echo esc_attr( deoia_subscriptions_portal_status_modifier( (string) $context['subscription_status'] ) ); ?>">
								<?php echo esc_html( (string) $context['subscription_status'] ); ?>
							</span>
						</p>
						<p class="deoia-account-portal__label"><?php echo esc_html__( 'Plan', 'deoia-subscriptions' ); ?></p>
						<p class="deoia-account-portal__value"><?php echo esc_html( (string) $context['plan_label'] ); ?></p>
					</article>

					<article class="deoia-account-portal__card">
						<h3 class="deoia-account-portal__card-title"><?php echo esc_html__( 'Cuenta', 'deoia-subscriptions' ); ?></h3>
						<p class="deoia-account-portal__label"><?php echo esc_html__( 'Correo (demo)', 'deoia-subscriptions' ); ?></p>
						<p class="deoia-account-portal__value"><?php echo esc_html( (string) $context['account_email'] ); ?></p>
					</article>

					<article class="deoia-account-portal__card deoia-account-portal__card--wide">
						<h3 class="deoia-account-portal__card-title"><?php echo esc_html__( 'Facturación', 'deoia-subscriptions' ); ?></h3>
						<p><?php echo esc_html( (string) $context['billing_summary'] ); ?></p>
					</article>

					<article class="deoia-account-portal__card deoia-account-portal__card--wide">
						<h3 class="deoia-account-portal__card-title"><?php echo esc_html__( 'Soporte', 'deoia-subscriptions' ); ?></h3>
						<p><?php echo esc_html( (string) $context['support_summary'] ); ?></p>
					</article>

					<article class="deoia-account-portal__card deoia-account-portal__card--wide">
						<h3 class="deoia-account-portal__card-title"><?php echo esc_html__( 'Avisos', 'deoia-subscriptions' ); ?></h3>
						<ul class="deoia-account-portal__messages">
							<?php foreach ( $context['messages'] as $message ) : ?>
								<li><?php echo esc_html( (string) $message ); ?></li>
							<?php endforeach; ?>
						</ul>
					</article>
				</section>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}
