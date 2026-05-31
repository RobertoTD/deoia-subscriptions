<?php
/**
 * Shortcode [deoia_subscription_thank_you] — confirmación post-checkout Stripe.
 *
 * @package DeoiaSubscriptions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renderiza el portal compacto de gracias post-pago.
 *
 * @return string
 */
function deoia_subscriptions_render_thank_you_shortcode(): string {
	wp_enqueue_style( 'deoia-subscription-form' );

	$logo_url = plugins_url( 'assets/img/deoia-citas-logo.svg', DEOIA_SUBSCRIPTIONS_FILE );

	ob_start();
	?>
	<section class="deoia-subscription-portal deoia-subscription-portal--thanks" aria-labelledby="deoia-subscription-thanks-title">
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
				<span class="deoia-subscription-portal__title"><?php echo esc_html__( 'DEOIA Citas', 'deoia-subscriptions' ); ?></span>
			</div>
		</header>

		<div class="deoia-subscription-portal__card deoia-subscription-thanks__card">
			<h2 id="deoia-subscription-thanks-title" class="deoia-subscription-thanks__title"><?php echo esc_html__( 'Tu agenda se está preparando', 'deoia-subscriptions' ); ?></h2>
			<p class="deoia-subscription-thanks__lead"><?php echo esc_html__( 'Estamos configurando tu agenda DEOIA Citas. Cuando esté lista, recibirás un correo con el enlace de acceso y las instrucciones para entrar.', 'deoia-subscriptions' ); ?></p>
			<p class="deoia-subscription-thanks__hint"><?php echo esc_html__( 'Esto puede tardar unos minutos. Revisa también la carpeta de spam o promociones si no ves el correo.', 'deoia-subscriptions' ); ?></p>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}

add_shortcode( 'deoia_subscription_thank_you', 'deoia_subscriptions_render_thank_you_shortcode' );

/**
 * Inyecta el portal de gracias en /gracias/ cuando la página no tiene contenido.
 *
 * @param string $content Contenido de la página.
 * @return string
 */
function deoia_subscriptions_maybe_inject_thank_you_portal( string $content ): string {
	if ( ! is_page( 'gracias' ) ) {
		return $content;
	}

	if ( trim( wp_strip_all_tags( $content ) ) !== '' ) {
		return $content;
	}

	return deoia_subscriptions_render_thank_you_shortcode();
}
add_filter( 'the_content', 'deoia_subscriptions_maybe_inject_thank_you_portal', 9 );
