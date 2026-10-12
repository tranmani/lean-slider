<?php
/**
 * Live preview page for the editor: a bare front-end page (theme styles and
 * fonts, no header or footer) that the editor fills with rendered slider HTML
 * by postMessage. Only for logged-in users who can edit sliders; never indexed.
 *
 * @package LeanSlider
 */

namespace LeanSlider;

defined( 'ABSPATH' ) || exit;

class Preview {

	public static function init() {
		add_action( 'template_redirect', [ __CLASS__, 'maybe_render' ], 0 );
	}

	public static function maybe_render() {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( empty( $_GET['lsl_preview'] ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_theme_options' ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_lsl'] ?? '' ) ), 'lsl_preview' ) ) {
			wp_die( esc_html__( 'Sorry, you cannot preview sliders.', 'lean-slider' ), 403 );
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		show_admin_bar( false );
		wp_enqueue_script( Render::HANDLE );
		wp_enqueue_script( 'lean-slider-preview', LSL_URL . 'assets/preview.js', [ Render::HANDLE ], LSL_VERSION, true );
		wp_localize_script( 'lean-slider-preview', 'LSLPreview', [ 'origin' => untrailingslashit( admin_url() ) ] );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
		<?php wp_head(); ?>
<style>
html, body { margin: 0 !important; padding: 0 !important; background: #fff; overflow: hidden; }
body::before, body::after { display: none !important; }
#lsl-pv { width: 100%; }
#lsl-pv .lsl-l { pointer-events: auto; cursor: grab; outline: 1px dashed transparent; outline-offset: 2px; touch-action: none; }
#lsl-pv .lsl-l:hover { outline-color: rgba(34, 113, 177, .7); }
#lsl-pv .lsl-l.is-selected { outline: 2px solid #2271b1; }
#lsl-pv .lsl-l.is-dragging { cursor: grabbing; }
#lsl-pv .lsl-slide.is-active { transition: none; }
</style>
</head>
<body class="lsl-preview-page">
<div id="lsl-pv"></div>
		<?php wp_footer(); ?>
</body>
</html>
		<?php
		exit;
	}
}
