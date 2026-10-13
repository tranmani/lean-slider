<?php
/**
 * Preload the first slider image from <head>.
 *
 * The first slide's image is already in the HTML with fetchpriority="high", but the
 * browser only reaches it after every stylesheet and script in <head>. On sites with a
 * big theme and a page builder that can be dozens of files, and the hero image starts
 * seconds late. When the page's first slider can be found before output starts — in an
 * Elementor layout or in shortcodes in the content — a <link rel="preload"> for its first
 * image is printed at the very top of <head>.
 *
 * Conservative on purpose: if the first slider sits in an Elementor element that is
 * hidden on some devices, nothing is preloaded (better no hint than a wasted download).
 *
 * @package LeanSlider
 */

namespace LeanSlider;

defined( 'ABSPATH' ) || exit;

class Preload {

	public static function init() {
		add_action( 'wp_head', [ __CLASS__, 'print_links' ], 1 );
	}

	public static function print_links() {
		if ( ! is_singular() || is_preview() ) {
			return;
		}
		$html = '';
		foreach ( self::first_sliders( (int) get_queried_object_id() ) as $item ) {
			$slider = Sliders::find( $item['alias'] );
			if ( $slider ) {
				$html .= Render::preload_links( $slider, $item['media'] );
			}
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- built with escaping in Render.
	}

	/**
	 * The slider(s) shown first on a post: [ [ 'alias' => …, 'media' => … ], … ].
	 * Two entries when a theme widget shows one slider on phones and another on larger screens.
	 */
	public static function first_sliders( $post_id ) {
		if ( ! $post_id ) {
			return [];
		}
		if ( 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			$data = get_post_meta( $post_id, '_elementor_data', true );
			$tree = is_string( $data ) ? json_decode( $data, true ) : $data;
			$found = is_array( $tree ) ? self::from_elements( $tree ) : null;
			return $found ?: [];
		}
		return self::from_content( (string) get_post_field( 'post_content', $post_id ) ) ?: [];
	}

	/**
	 * Walks Elementor elements in page order. Returns the first slider found, [] when the
	 * first slider must not be preloaded (hidden on some devices), or null when there is none.
	 */
	private static function from_elements( array $elements ) {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$settings = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : [];
			$found    = self::from_widget( (string) ( $el['widgetType'] ?? '' ), $settings );
			if ( null === $found && ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$found = self::from_elements( $el['elements'] );
			}
			if ( null !== $found ) {
				$hidden = ! empty( $settings['hide_desktop'] ) || ! empty( $settings['hide_tablet'] ) || ! empty( $settings['hide_mobile'] );
				return $hidden ? [] : $found;
			}
		}
		return null;
	}

	private static function from_widget( $type, array $settings ) {
		$takeover = lsl_takeover();
		switch ( $type ) {
			case 'lean_slider':
				return self::one( (string) ( $settings['alias'] ?? '' ) );
			case 'slider_revolution':
				return $takeover ? self::one( Shortcodes::alias_from_shortcode( $settings['shortcode'] ?? '' ) ) : null;
			case 'nasa-rev-slider':
				if ( ! $takeover ) {
					return null;
				}
				$alias   = sanitize_title( $settings['alias'] ?? '' );
				$alias_m = sanitize_title( $settings['alias_m'] ?? '' );
				if ( '' === $alias ) {
					$alias = $alias_m;
				}
				if ( '' === $alias_m || $alias_m === $alias ) {
					return self::one( $alias );
				}
				// Same split as the widget: phone slider up to 777px, the other one from 778px.
				return [
					[ 'alias' => $alias_m, 'media' => '(max-width: 777px)' ],
					[ 'alias' => $alias, 'media' => '(min-width: 778px)' ],
				];
			case 'shortcode':
				return self::from_content( (string) ( $settings['shortcode'] ?? '' ) );
			case 'text-editor':
				return self::from_content( (string) ( $settings['editor'] ?? '' ) );
		}
		return null;
	}

	/** First slider shortcode in a piece of content, or null. */
	private static function from_content( $content ) {
		$tags = [ 'lean_slider' ];
		if ( lsl_takeover() ) {
			$tags = array_merge( $tags, Shortcodes::REV_TAGS );
		}
		if ( false === strpos( $content, '[' ) || ! preg_match( '/' . get_shortcode_regex( $tags ) . '/', $content, $m ) ) {
			return null;
		}
		$atts = shortcode_parse_atts( $m[3] );
		$atts = is_array( $atts ) ? $atts : [];
		if ( 'lean_slider' === $m[2] ) {
			return self::one( (string) ( $atts['alias'] ?? $atts['id'] ?? '' ) );
		}
		return self::one( (string) ( $atts['alias'] ?? $atts[0] ?? $atts['alias_m'] ?? '' ) );
	}

	private static function one( $alias ) {
		return '' === $alias ? null : [ [ 'alias' => $alias, 'media' => '' ] ];
	}
}
