<?php
/**
 * Shortcodes.
 *
 * [lean_slider alias="home"] or [lean_slider id="12"] shows a slider.
 *
 * Takeover: when enabled (Settings), Slider Revolution's own [rev_slider] and
 * [rev_slider_vc] shortcodes are answered by the Lean Slider with the same alias,
 * so pages built with Slider Revolution switch over without being edited. If no
 * Lean Slider has that alias and Slider Revolution is still running, it is left
 * to render the slider itself.
 *
 * @package LeanSlider
 */

namespace LeanSlider;

defined( 'ABSPATH' ) || exit;

class Shortcodes {

	// nasa_rev_slider: NasaTheme (Elessi) wrapper, used by its Elementor widget; it prints nothing without Slider Revolution.
	const REV_TAGS = [ 'rev_slider', 'rev_slider_vc', 'nasa_rev_slider' ];

	/** Slider Revolution's original callbacks, kept as a fallback. */
	private static $original = [];

	public static function init() {
		add_shortcode( 'lean_slider', [ __CLASS__, 'lean_slider' ] );
		// After every plugin has registered its shortcodes.
		add_action( 'wp_loaded', [ __CLASS__, 'takeover' ] );
	}

	public static function lean_slider( $atts ) {
		$atts = shortcode_atts( [ 'id' => '', 'alias' => '', 'slide' => 0 ], $atts, 'lean_slider' );
		$key  = '' !== $atts['alias'] ? $atts['alias'] : $atts['id'];
		return self::output( Sliders::find( $key ), (int) $atts['slide'] );
	}

	public static function takeover() {
		if ( ! lsl_takeover() ) {
			return;
		}
		global $shortcode_tags;
		foreach ( self::REV_TAGS as $tag ) {
			if ( isset( $shortcode_tags[ $tag ] ) ) {
				self::$original[ $tag ] = $shortcode_tags[ $tag ];
			}
			add_shortcode( $tag, [ __CLASS__, 'rev_slider' ] );
		}
	}

	public static function rev_slider( $atts, $content = '', $tag = 'rev_slider' ) {
		$atts   = is_array( $atts ) ? $atts : [];
		$alias  = (string) ( $atts['alias'] ?? ( $atts[0] ?? '' ) );
		$slider = '' !== $alias ? Sliders::find( $alias ) : null;
		if ( ! $slider && ! empty( $atts['alias_m'] ) ) {
			$slider = Sliders::find( (string) $atts['alias_m'] );
		}
		if ( $slider ) {
			$html = self::output( $slider );
			return empty( $atts['el_class'] ) ? $html : '<div class="' . esc_attr( $atts['el_class'] ) . '">' . $html . '</div>';
		}
		if ( isset( self::$original[ $tag ] ) && is_callable( self::$original[ $tag ] ) ) {
			return call_user_func( self::$original[ $tag ], $atts, $content, $tag );
		}
		return current_user_can( 'edit_posts' ) && '' !== $alias
			/* translators: %s: slider alias */
			? '<!-- ' . esc_html( sprintf( __( 'Lean Slider: no slider with the alias "%s". Import it under Sliders → Import.', 'lean-slider' ), $alias ) ) . ' -->'
			: '';
	}

	public static function output( $slider, $start = 0 ) {
		if ( ! $slider ) {
			return '';
		}
		return Render::slider( $slider, [ 'slide' => $start ] );
	}

	/** Alias inside a Slider Revolution shortcode string, e.g. [rev_slider alias="home" slidertitle="Home"]. */
	public static function alias_from_shortcode( $shortcode ) {
		return preg_match( '/alias\s*=\s*["\']?([^"\'\s\]]+)/', (string) $shortcode, $m ) ? $m[1] : '';
	}
}
