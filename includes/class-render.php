<?php
/**
 * Front-end output.
 *
 * Everything a visitor or a search engine needs is in the HTML the server sends:
 * images with alt text, srcset and real dimensions, headings, text and links.
 * The script only adds autoplay, arrows, dots, keyboard and swipe; without it the
 * first slide shows as a normal banner.
 *
 * Speed: the stylesheet (about 4.5 KB) is inlined once, before the first slider, so
 * there is no extra request and nothing jumps; the script is 6 KB (2 KB gzipped), deferred, and
 * only loaded on pages that show a slider. The first image is fetched with high
 * priority (and preloaded from <head> when the slider can be found early, see Preload); the other slides are not shown (and their images not requested)
 * until the page has finished loading.
 *
 * @package LeanSlider
 */

namespace LeanSlider;

defined( 'ABSPATH' ) || exit;

class Render {

	const HANDLE = 'lean-slider';

	/** Sliders printed on this page so far. */
	private static $count = 0;

	private static $css_done = false;

	public static function register_assets() {
		wp_register_script( self::HANDLE, LSL_URL . 'assets/slider.js', [], LSL_VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );
	}

	/** Stylesheet text, minified on the fly (a local file read; kept for the rest of the request). */
	public static function css() {
		static $css = null;
		if ( null === $css ) {
			$css = (string) file_get_contents( LSL_DIR . '/assets/slider.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$css = preg_replace( '#/\*.*?\*/#s', '', $css );
			$css = preg_replace( '/\s*([{};,>])\s*/', '$1', $css );
			$css = preg_replace( '/:\s+/', ':', $css );
			$css = trim( preg_replace( '/\s+/', ' ', str_replace( ';}', '}', $css ) ) );
		}
		return $css;
	}

	/**
	 * HTML for one slider.
	 *
	 * @param array $slider Normalised slider (Sliders::get()).
	 * @param array $args   preview: bool — admin preview (no lazy loading, no autoplay); slide: int — start slide.
	 */
	public static function slider( $slider, array $args = [] ) {
		if ( ! is_array( $slider ) ) {
			return '';
		}
		$preview = ! empty( $args['preview'] );
		$slides  = array_values( array_filter( $slider['slides'], static fn( $s ) => ! $s['hidden'] || $preview ) );
		if ( ! $slides ) {
			return '';
		}
		++self::$count;
		$first_on_page = 1 === self::$count;
		$s             = $slider['settings'];
		$id            = 'lsl-' . (int) ( $slider['id'] ?? 0 ) . '-' . self::$count;
		$start         = max( 0, min( count( $slides ) - 1, (int) ( $args['slide'] ?? 0 ) ) );
		$cd            = $s['canvas']['d'];
		$cm            = $s['canvas']['m'];

		$config = [
			'autoplay' => $preview ? 0 : $s['autoplay'],
			'delay'    => $s['delay'],
			'speed'    => $s['speed'],
			'mode'     => $s['transition'],
			'pause'    => $s['pause_hover'],
			'loop'     => $s['loop'],
		];
		$style = sprintf(
			'--lsl-ar:%1$s/%2$s;--lsl-cw:%1$spx;--lsl-ch:%2$spx;--lsl-mar:%3$s/%4$s;--lsl-mcw:%3$spx;--lsl-mch:%4$spx;--lsl-speed:%5$dms;--lsl-arrow:%6$s;--lsl-arrow-bg:%7$s',
			$cd['w'], $cd['h'], $cm['w'], $cm['h'], $s['speed'], $s['arrow_color'], $s['arrow_bg']
		);
		if ( $s['max_width'] ) {
			$style .= ';max-width:' . (int) $s['max_width'] . 'px';
		}

		$html = '';
		if ( ! self::$css_done || $preview ) {
			$html          .= '<style id="lean-slider-css">' . self::css() . '</style>';
			self::$css_done = true;
		}
		if ( 777 !== (int) $s['breakpoint'] ) {
			$html .= '<style>' . self::mobile_css( '#' . $id, (int) $s['breakpoint'] ) . '</style>';
		}

		$classes = [ 'lsl', 'lsl--' . $s['transition'] ];
		if ( $preview ) {
			$classes[] = 'lsl--preview';
		}
		$html .= sprintf(
			'<div id="%s" class="%s" style="%s" data-lsl="%s" role="region" aria-roledescription="%s" aria-label="%s">',
			esc_attr( $id ),
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $style ),
			esc_attr( wp_json_encode( $config ) ),
			esc_attr__( 'carousel', 'lean-slider' ),
			esc_attr( $slider['title'] ?: __( 'Slider', 'lean-slider' ) )
		);
		$html .= '<div class="lsl-track">';
		$total = count( $slides );
		foreach ( $slides as $i => $slide ) {
			$html .= self::slide( $slide, $slider, [
				'index'   => $i,
				'total'   => $total,
				'active'  => $i === $start,
				'eager'   => $i === $start || $preview,
				'lcp'     => $i === $start && $first_on_page && ! $preview,
				'preview' => $preview,
			] );
		}
		$html .= '</div>';

		if ( $total > 1 && $s['arrows'] ) {
			$html .= '<button type="button" class="lsl-arrow lsl-arrow--prev" data-lsl-prev aria-label="' . esc_attr__( 'Previous slide', 'lean-slider' ) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></button>';
			$html .= '<button type="button" class="lsl-arrow lsl-arrow--next" data-lsl-next aria-label="' . esc_attr__( 'Next slide', 'lean-slider' ) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg></button>';
		}
		if ( $total > 1 && $s['dots'] ) {
			$html .= '<div class="lsl-dots">';
			for ( $i = 0; $i < $total; $i++ ) {
				/* translators: %d: slide number */
				$html .= sprintf( '<button type="button" class="lsl-dot" data-lsl-go="%1$d" aria-label="%2$s"%3$s></button>', $i, esc_attr( sprintf( __( 'Go to slide %d', 'lean-slider' ), $i + 1 ) ), $i === $start ? ' aria-current="true"' : '' );
			}
			$html .= '</div>';
		}
		$html .= '</div>';

		if ( ! $preview ) {
			wp_enqueue_script( self::HANDLE );
		}
		return $html;
	}

	private static function slide( $slide, $slider, $o ) {
		$s     = $slider['settings'];
		$bg    = $slide['bg']['color'];
		$style = '';
		if ( $bg ) {
			$style .= ( false !== strpos( $bg, 'gradient' ) ? 'background-image:' : 'background-color:' ) . $bg . ';';
		}
		$classes = 'lsl-slide' . ( $o['active'] ? ' is-active' : '' ) . ( $slide['hidden'] ? ' is-hidden-slide' : '' );
		/* translators: 1: slide number, 2: number of slides */
		$label = sprintf( __( '%1$d of %2$d', 'lean-slider' ), $o['index'] + 1, $o['total'] );
		if ( $slide['title'] ) {
			$label .= ': ' . $slide['title'];
		}
		$html = sprintf(
			'<div class="%s" role="group" aria-roledescription="%s" aria-label="%s"%s%s%s>',
			esc_attr( $classes ),
			esc_attr__( 'slide', 'lean-slider' ),
			esc_attr( $label ),
			$style ? ' style="' . esc_attr( $style ) . '"' : '',
			$slide['delay'] ? ' data-delay="' . (int) $slide['delay'] . '"' : '',
			$o['active'] ? '' : ' aria-hidden="true"'
		);

		$loading = $o['eager'] ? 'eager' : 'lazy';
		if ( $slide['bg']['image']['url'] ) {
			$html .= '<div class="lsl-bg" aria-hidden="true">' . self::img( $slide['bg']['image'], [ 'alt' => '', 'loading' => $loading, 'sizes' => self::sizes( $s ) ] ) . '</div>';
		}

		// Main picture (desktop + optional mobile image); wrapped in the slide link when there is one.
		$picture = '';
		$alt     = $slide['image']['d']['alt'] ?: $slide['title'];
		if ( $slide['image']['d']['url'] || $slide['image']['m']['url'] ) {
			$picture = self::picture( $slide['image']['d'], $slide['image']['m'], $s, [
				'alt'      => $alt,
				'loading'  => $loading,
				'priority' => $o['lcp'],
			] );
		}
		if ( $slide['link'] ) {
			$attrs = $slide['new_tab'] ? ' target="_blank" rel="noopener"' : '';
			$aria  = $picture && '' !== $alt ? '' : ' aria-label="' . esc_attr( $slide['title'] ?: wp_parse_url( $slide['link'], PHP_URL_PATH ) ) . '"';
			$html .= '<a class="lsl-cover" href="' . esc_url( $slide['link'] ) . '"' . $attrs . $aria . ( $o['active'] ? '' : ' tabindex="-1"' ) . '>' . $picture . '</a>';
		} elseif ( $picture ) {
			$html .= '<div class="lsl-cover">' . $picture . '</div>';
		}

		if ( $slide['layers'] ) {
			$html .= '<div class="lsl-canvas">';
			foreach ( $slide['layers'] as $layer ) {
				$html .= self::layer( $layer, $s, $o );
			}
			$html .= '</div>';
		}
		return $html . '</div>';
	}

	private static function layer( $layer, $s, $o ) {
		$vars = self::position_vars( $layer['bp']['d'], $s['canvas']['d'], '' ) . self::position_vars( $layer['bp']['m'], $s['canvas']['m'], 'm' );
		$html = '<div class="lsl-l lsl-l--' . esc_attr( $layer['type'] ) . '" style="' . esc_attr( $vars ) . '" data-uid="' . esc_attr( $layer['uid'] ) . '">';

		$a        = $layer['anim'];
		$w        = $s['canvas']['d']['w'];
		$anim_cls = 'lsl-a';
		$anim_var = '';
		if ( 'none' !== $a['in'] ) {
			$dist      = lsl_num( $a['distance'] / $w * 100 ) . 'cqw';
			$from      = [
				'fade'  => [ '0', '0', '1' ],
				'up'    => [ '0', $dist, '1' ],
				'down'  => [ '0', '-' . $dist, '1' ],
				'left'  => [ $dist, '0', '1' ],
				'right' => [ '-' . $dist, '0', '1' ],
				'zoom'  => [ '0', '0', '0.85' ],
			][ $a['in'] ];
			$anim_cls .= ' lsl-in';
			$anim_var  = sprintf( '--ad:%dms;--adur:%dms;--ax:%s;--ay:%s;--as:%s', $a['delay'], $a['duration'], $from[0], $from[1], $from[2] );
		}
		$html .= '<div class="' . $anim_cls . '"' . ( $anim_var ? ' style="' . esc_attr( $anim_var ) . '"' : '' ) . '>';

		$float = 'float' === $a['loop'] && ( $a['loop_x'] || $a['loop_y'] );
		if ( $float ) {
			$html .= '<div class="lsl-f" style="' . esc_attr( sprintf(
				'--fx:%scqw;--fy:%scqw;--fdur:%dms;--fdel:%dms',
				lsl_num( $a['loop_x'] / $w * 100 ),
				lsl_num( $a['loop_y'] / $w * 100 ),
				$a['loop_speed'],
				'none' !== $a['in'] ? $a['delay'] + $a['duration'] : 0
			) ) . '">';
		}

		$html .= self::layer_content( $layer, $o );

		if ( $float ) {
			$html .= '</div>';
		}
		return $html . '</div></div>';
	}

	private static function layer_content( $layer, $o ) {
		$tab = $o['active'] ? '' : ' tabindex="-1"';
		if ( 'image' === $layer['type'] ) {
			if ( ! $layer['image']['url'] ) {
				return '';
			}
			$img = self::img( $layer['image'], [ 'alt' => $layer['image']['alt'], 'loading' => $o['eager'] ? 'eager' : 'lazy', 'class' => 'lsl-li', 'sizes' => '(max-width: 777px) 50vw, 40vw' ] );
			if ( $layer['link'] ) {
				return '<a href="' . esc_url( $layer['link'] ) . '"' . ( $layer['new_tab'] ? ' target="_blank" rel="noopener"' : '' ) . $tab . '>' . $img . '</a>';
			}
			return $img;
		}

		$st     = $layer['style'];
		$fs_ref = max( 1, $layer['bp']['d']['fs'] );
		$css    = [];
		// Colours go through variables so the stylesheet's :hover rule can still change them.
		if ( $st['color'] ) {
			$css[] = '--c:' . $st['color'];
		}
		if ( $st['bg'] ) {
			$css[] = '--bgc:' . $st['bg'];
		}
		if ( $st['font_family'] ) {
			$css[] = 'font-family:' . $st['font_family'];
		}
		if ( $st['font_weight'] ) {
			$css[] = 'font-weight:' . $st['font_weight'];
		}
		if ( $st['letter_spacing'] ) {
			$css[] = 'letter-spacing:' . lsl_num( $st['letter_spacing'] / $fs_ref ) . 'em';
		}
		if ( $st['radius'] ) {
			$css[] = 'border-radius:' . lsl_num( $st['radius'] / $fs_ref ) . 'em';
		}
		if ( array_filter( $st['padding'] ) ) {
			$css[] = 'padding:' . implode( ' ', array_map( static fn( $p ) => $p ? lsl_num( $p / $fs_ref ) . 'em' : '0', $st['padding'] ) );
		}
		if ( $st['uppercase'] ) {
			$css[] = 'text-transform:uppercase';
		}
		if ( $st['italic'] ) {
			$css[] = 'font-style:italic';
		}
		if ( $st['hover_color'] ) {
			$css[] = '--hc:' . $st['hover_color'];
		}
		if ( $st['hover_bg'] ) {
			$css[] = '--hbg:' . $st['hover_bg'];
		}
		$style = $css ? ' style="' . esc_attr( implode( ';', $css ) ) . '"' : '';
		$text  = wp_kses( $layer['text'], Sliders::text_html() );

		if ( 'button' === $layer['type'] || $layer['link'] ) {
			$cls = 'button' === $layer['type'] ? 'lsl-t lsl-btn' : 'lsl-t lsl-tl';
			if ( ! $layer['link'] ) {
				return '<span class="' . $cls . '"' . $style . '>' . $text . '</span>';
			}
			$link = '<a class="' . $cls . '" href="' . esc_url( $layer['link'] ) . '"' . ( $layer['new_tab'] ? ' target="_blank" rel="noopener"' : '' ) . $tab . $style . '>' . $text . '</a>';
			// A linked heading stays a heading for search engines.
			return 'button' !== $layer['type'] && 'p' !== $layer['tag'] ? '<' . $layer['tag'] . ' class="lsl-t">' . $link . '</' . $layer['tag'] . '>' : $link;
		}
		return '<' . $layer['tag'] . ' class="lsl-t"' . $style . '>' . $text . '</' . $layer['tag'] . '>';
	}

	/** CSS custom properties that place a layer for one breakpoint ('' desktop, 'm' mobile). */
	private static function position_vars( $p, $canvas, $prefix ) {
		$w   = max( 1, $canvas['w'] );
		$h   = max( 1, $canvas['h'] );
		$x   = lsl_num( $p['x'] / $w * 100 ) . '%';
		$y   = lsl_num( $p['y'] / $h * 100 ) . '%';
		$v   = [];
		$pre = '--' . $prefix;
		switch ( $p['ha'] ) {
			case 'center':
				$v[ $pre . 'l' ]  = 'calc(50% + ' . $x . ')';
				$v[ $pre . 'tx' ] = '-50%';
				break;
			case 'right':
				$v[ $pre . 'r' ] = $x;
				break;
			default:
				$v[ $pre . 'l' ] = $x;
		}
		switch ( $p['va'] ) {
			case 'middle':
				$v[ $pre . 't' ]  = 'calc(50% + ' . $y . ')';
				$v[ $pre . 'ty' ] = '-50%';
				break;
			case 'bottom':
				$v[ $pre . 'b' ] = $y;
				break;
			default:
				$v[ $pre . 't' ] = $y;
		}
		if ( $p['w'] > 0 ) {
			$v[ $pre . 'w' ] = lsl_num( $p['w'] / $w * 100 ) . '%';
		} else {
			$v[ $pre . 'ws' ] = 'nowrap';
		}
		$v[ $pre . 'fs' ] = lsl_num( $p['fs'] / $w * 100 ) . 'cqw';
		$v[ $pre . 'lh' ] = lsl_num( $p['lh'] );
		if ( 'left' !== $p['ta'] ) {
			$v[ $pre . 'ta' ] = $p['ta'];
		}
		if ( ! $p['show'] ) {
			$v[ $pre . 'dsp' ] = 'none';
		}
		$out = '';
		foreach ( $v as $k => $val ) {
			$out .= $k . ':' . $val . ';';
		}
		return $out;
	}

	private static function sizes( $s ) {
		return $s['max_width'] ? sprintf( '(max-width: %1$dpx) 100vw, %1$dpx', $s['max_width'] ) : '100vw';
	}

	/**
	 * <picture>, mobile first: the phone image is the <img> itself and the desktop
	 * image a min-width <source>, so phones never consider the desktop file.
	 * With a single image, that image is used everywhere.
	 */
	private static function picture( $desk, $mob, $s, $o ) {
		$main = $mob['url'] ? $mob : $desk;
		$html = '<picture class="lsl-pic">';
		if ( $desk['url'] && $mob['url'] && $mob['url'] !== $desk['url'] ) {
			$srcset = $desk['id'] ? wp_get_attachment_image_srcset( $desk['id'], 'full' ) : '';
			$html  .= sprintf(
				'<source media="(min-width: %dpx)" srcset="%s" sizes="%s"%s>',
				(int) $s['breakpoint'] + 1,
				esc_attr( $srcset ?: $desk['url'] ),
				esc_attr( self::sizes( $s ) ),
				$desk['w'] && $desk['h'] ? ' width="' . (int) $desk['w'] . '" height="' . (int) $desk['h'] . '"' : ''
			);
		}
		$html .= self::img( $main, [
			'alt'      => $o['alt'],
			'loading'  => $o['loading'],
			'priority' => $o['priority'],
			'sizes'    => $main === $mob ? '100vw' : self::sizes( $s ),
			'class'    => 'lsl-img',
		] );
		return $html . '</picture>';
	}

	/**
	 * <link rel="preload"> tags for the first image of a slider (see Preload), matching
	 * what picture() shows: the phone image up to the breakpoint, the desktop image after it.
	 *
	 * @param array  $slider Normalised slider.
	 * @param string $media  Extra media condition, e.g. when a theme widget shows this slider on phones only.
	 */
	public static function preload_links( $slider, $media = '' ) {
		$slides = array_values( array_filter( $slider['slides'], static fn( $s ) => ! $s['hidden'] ) );
		if ( ! $slides ) {
			return '';
		}
		$slide = $slides[0];
		$s     = $slider['settings'];
		$desk  = $slide['image']['d'];
		$mob   = $slide['image']['m'];
		$and   = static fn( $q ) => implode( ' and ', array_filter( [ $media, $q ] ) );
		if ( $desk['url'] && $mob['url'] && $mob['url'] !== $desk['url'] ) {
			return self::preload_link( $mob, '100vw', $and( '(max-width: ' . (int) $s['breakpoint'] . 'px)' ) )
				. self::preload_link( $desk, self::sizes( $s ), $and( '(min-width: ' . ( (int) $s['breakpoint'] + 1 ) . 'px)' ) );
		}
		$main = $mob['url'] ? $mob : $desk;
		if ( $main['url'] ) {
			return self::preload_link( $main, $main === $mob ? '100vw' : self::sizes( $s ), $media );
		}
		if ( $slide['bg']['image']['url'] ) {
			return self::preload_link( $slide['bg']['image'], self::sizes( $s ), $media );
		}
		return '';
	}

	private static function preload_link( $image, $sizes, $media ) {
		$srcset = $image['id'] ? wp_get_attachment_image_srcset( $image['id'], 'full' ) : '';
		$html   = '<link rel="preload" as="image" href="' . esc_url( $image['url'] ) . '" fetchpriority="high"';
		if ( $srcset ) {
			$html .= ' imagesrcset="' . esc_attr( $srcset ) . '" imagesizes="' . esc_attr( $sizes ) . '"';
		}
		if ( '' !== $media ) {
			$html .= ' media="' . esc_attr( $media ) . '"';
		}
		return $html . ">\n";
	}

	private static function img( $image, $o ) {
		$srcset = $image['id'] ? wp_get_attachment_image_srcset( $image['id'], 'full' ) : '';
		$attrs  = [
			'src'    => esc_url( $image['url'] ),
			'alt'    => esc_attr( (string) ( $o['alt'] ?? '' ) ),
			'width'  => $image['w'] ? (int) $image['w'] : null,
			'height' => $image['h'] ? (int) $image['h'] : null,
			'class'  => isset( $o['class'] ) ? esc_attr( $o['class'] ) : null,
		];
		if ( $srcset ) {
			$attrs['srcset'] = esc_attr( $srcset );
			$attrs['sizes']  = esc_attr( $o['sizes'] ?? '100vw' );
		}
		if ( ! empty( $o['priority'] ) ) {
			$attrs['fetchpriority'] = 'high';
			$attrs['loading']       = 'eager';
		} else {
			$attrs['loading']  = 'lazy' === ( $o['loading'] ?? '' ) ? 'lazy' : 'eager';
			$attrs['decoding'] = 'async';
		}
		$html = '<img';
		foreach ( $attrs as $k => $v ) {
			if ( null !== $v ) {
				$html .= ' ' . $k . '="' . $v . '"';
			}
		}
		return $html . '>';
	}

	/**
	 * Rules for a slider whose desktop layout starts somewhere other than the
	 * stylesheet's 778px: phone values up to the breakpoint, desktop after it.
	 */
	public static function mobile_css( $scope, $bp ) {
		$s = $scope;
		return "@media (max-width:{$bp}px){{$s} .lsl-track{aspect-ratio:var(--lsl-mar);max-height:var(--lsl-mch)}{$s} .lsl-cover,{$s} .lsl-canvas{max-width:var(--lsl-mcw)}{$s} .lsl-l{left:var(--ml,auto);right:var(--mr,auto);top:var(--mt,auto);bottom:var(--mb,auto);width:var(--mw,auto);transform:translate(var(--mtx,0),var(--mty,0));font-size:var(--mfs);line-height:var(--mlh);white-space:var(--mws,normal);display:var(--mdsp,block);text-align:var(--mta,left)}}"
			. '@media (min-width:' . ( $bp + 1 ) . "px){{$s} .lsl-track{aspect-ratio:var(--lsl-ar);max-height:var(--lsl-ch)}{$s} .lsl-cover,{$s} .lsl-canvas{max-width:var(--lsl-cw)}{$s} .lsl-l{left:var(--l,auto);right:var(--r,auto);top:var(--t,auto);bottom:var(--b,auto);width:var(--w,auto);transform:translate(var(--tx,0),var(--ty,0));font-size:var(--fs);line-height:var(--lh);white-space:var(--ws,normal);display:var(--dsp,block);text-align:var(--ta,left)}}";
	}

	/** Reset the per-page counters (tests, previews). */
	public static function reset() {
		self::$count    = 0;
		self::$css_done = false;
	}
}
