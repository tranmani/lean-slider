<?php
/**
 * Import from Slider Revolution (6.x data, which 6.7 keeps current).
 *
 * Reads Slider Revolution's tables directly, so it works whether or not that
 * plugin is still active. What is converted:
 *  - canvas sizes (desktop + mobile), slide duration, transition speed, arrows, dots, autoplay;
 *  - slide background colour / gradient / image;
 *  - an image layer that covers the canvas on desktop and/or mobile becomes the
 *    slide's main picture (desktop + mobile source) and its link the slide link;
 *  - text, button and image layers: position, alignment, width, font, colours,
 *    hover colours, padding, radius, visibility per device, link;
 *  - entrance animation (fade / from a direction / zoom, delay, duration) and
 *    looping "float" movement.
 * Shapes, videos and effects without an equivalent are skipped and reported.
 *
 * @package LeanSlider
 */

namespace LeanSlider;

defined( 'ABSPATH' ) || exit;

class Importer {

	/** Slider Revolution sliders found in the database. */
	public static function available() {
		global $wpdb;
		$table = $wpdb->prefix . 'revslider_sliders';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return [];
		}
		$out = [];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( "SELECT id, title, alias, type FROM {$table} ORDER BY id" ) as $row ) {
			if ( 'folder' === $row->type || 'template' === $row->type ) {
				continue;
			}
			$slides = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}revslider_slides WHERE slider_id = %d", $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $slides ) {
				continue;
			}
			$existing = Sliders::find( $row->alias );
			$out[]    = [
				'id'       => (int) $row->id,
				'title'    => $row->title,
				'alias'    => $row->alias,
				'slides'   => $slides,
				'used'     => self::used_on( $row->alias ),
				'imported' => $existing ? $existing['id'] : 0,
			];
		}
		return $out;
	}

	/** Published posts/pages/templates that show this alias. */
	public static function used_on( $alias ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( 'alias="' . $alias . '"' ) . '%';
		$like_json = '%' . $wpdb->esc_like( 'alias=\\"' . $alias . '\\"' ) . '%';
		$ids  = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
			 WHERE p.post_status IN ('publish','private') AND p.post_type NOT IN ('revision') AND (p.post_content LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s)",
			$like,
			$like_json,
			'%' . $wpdb->esc_like( '"alias":"' . $alias . '"' ) . '%'
		) );
		return array_map( 'intval', $ids );
	}

	/** Import one slider by Slider Revolution ID; replaces a Lean Slider with the same alias. */
	public static function import( $rs_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}revslider_sliders WHERE id = %d", $rs_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return new \WP_Error( 'lsl_missing', __( 'Slider Revolution slider not found.', 'lean-slider' ) );
		}
		$slides = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}revslider_slides WHERE slider_id = %d ORDER BY slide_order, id", $rs_id ) ) as $s ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$slides[] = [ 'params' => json_decode( (string) $s->params, true ), 'layers' => json_decode( (string) $s->layers, true ) ];
		}
		$result   = self::convert( [ 'title' => $row->title, 'alias' => $row->alias, 'params' => json_decode( (string) $row->params, true ) ], $slides );
		$existing = Sliders::find( $row->alias );
		$id       = Sliders::save( $result['slider'], $existing ? $existing['id'] : 0 );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return [ 'id' => $id, 'notes' => $result['notes'], 'replaced' => (bool) $existing ];
	}

	/**
	 * Convert decoded Slider Revolution data.
	 *
	 * @param array $slider ['title', 'alias', 'params' => slider params]
	 * @param array $slides [['params' => …, 'layers' => …], …] in order
	 * @return array{slider: array, notes: string[]}
	 */
	public static function convert( array $slider, array $slides ) {
		$p     = (array) ( $slider['params'] ?? [] );
		$notes = [];
		$size  = (array) ( $p['size'] ?? [] );
		$cd    = [ 'w' => self::num( $size['width']['d'] ?? 1240 ) ?: 1240, 'h' => self::num( $size['height']['d'] ?? 680 ) ?: 680 ];
		$cm    = [ 'w' => self::num( $size['width']['m'] ?? 480 ) ?: 480, 'h' => self::num( $size['height']['m'] ?? 460 ) ?: 460 ];
		$tw    = self::num( $size['width']['t'] ?? 778 ) ?: 778;
		$show  = (array) ( $p['general']['slideshow'] ?? [] );
		$arrow = (array) ( $p['nav']['arrows'] ?? [] );
		$pre   = (array) ( $arrow['presets'] ?? [] );

		$settings = [
			'canvas'      => [ 'd' => $cd, 'm' => $cm ],
			'breakpoint'  => (int) $tw - 1,
			'autoplay'    => ! isset( $show['slideShow'] ) || ! empty( $show['slideShow'] ),
			'delay'       => self::num( $p['def']['delay'] ?? 6000 ) ?: 6000,
			'speed'       => max( 300, (int) self::num( $p['def']['transitionDuration'] ?? 300 ) ),
			'transition'  => 'fade',
			'pause_hover' => ! empty( $show['stopOnHover'] ),
			'loop'        => empty( $show['stopSlider'] ),
			'arrows'      => ! empty( $arrow['set'] ),
			'dots'        => ! empty( $p['nav']['bullets']['set'] ),
			'arrow_color' => self::color( $pre['arrow-color'] ?? '' ) ?: '#ffffff',
			'arrow_bg'    => self::color( $pre['bg-color'] ?? '' ) ?: 'rgba(0,0,0,0.5)',
		];

		$out = [];
		foreach ( $slides as $n => $s ) {
			$sp = (array) ( $s['params'] ?? [] );
			if ( 'unpublished' === ( $sp['publish']['state'] ?? '' ) ) {
				continue;
			}
			$slide = Sliders::default_slide();
			$title = trim( (string) ( $sp['title'] ?? '' ) );
			$slide['title'] = in_array( strtolower( $title ), [ '', 'slide', 'new slide' ], true ) ? '' : $title;
			$slide['delay'] = (int) self::num( $sp['timeline']['delay'] ?? 0 );

			$bg = (array) ( $sp['bg'] ?? [] );
			if ( 'image' === ( $bg['type'] ?? '' ) && ! empty( $bg['image'] ) ) {
				$slide['bg']['image'] = Sliders::image_from_url( $bg['image'] );
			} elseif ( in_array( $bg['type'] ?? '', [ 'solid', 'color' ], true ) ) {
				$slide['bg']['color'] = self::color( $bg['color'] ?? '' );
			}

			$layers = array_filter( (array) ( $s['layers'] ?? [] ), static fn( $l, $k ) => is_array( $l ) && ! in_array( $k, [ 'top', 'middle', 'bottom' ], true ), ARRAY_FILTER_USE_BOTH );
			uasort( $layers, static fn( $a, $b ) => (int) ( $a['position']['zIndex'] ?? 0 ) <=> (int) ( $b['position']['zIndex'] ?? 0 ) );

			// Canvas-covering images become the slide's main picture.
			foreach ( [ 'd' => $cd, 'm' => $cm ] as $bp => $canvas ) {
				foreach ( $layers as $k => $l ) {
					if ( 'image' !== ( $l['type'] ?? '' ) || ! self::visible( $l, $bp ) ) {
						continue;
					}
					$w = self::num( self::v( $l['size']['width'] ?? [], $bp ) );
					if ( $w < $canvas['w'] * 0.9 ) {
						continue;
					}
					$url = (string) ( $l['media']['imageUrl'] ?? '' );
					if ( ! $url ) {
						continue;
					}
					$slide['image'][ $bp ] = Sliders::image_from_url( $url );
					$link                  = self::link( $l );
					if ( $link && ! $slide['link'] ) {
						$slide['link']    = $link[0];
						$slide['new_tab'] = $link[1];
					}
					// One image visible everywhere fills both: the desktop picture is used on mobile too.
					unset( $layers[ $k ] );
					break;
				}
			}

			// Remaining layers.
			$max_fs = 0;
			foreach ( $layers as $l ) {
				$layer = self::layer( $l, $cd, $cm, $notes, $n + 1 );
				if ( $layer ) {
					$slide['layers'][] = $layer;
					if ( 'text' === $layer['type'] ) {
						$max_fs = max( $max_fs, $layer['bp']['d']['fs'] );
					}
				}
			}
			// The largest text on a slide is its heading.
			foreach ( $slide['layers'] as &$layer ) {
				if ( 'text' === $layer['type'] && $layer['bp']['d']['fs'] === $max_fs && $max_fs >= 30 ) {
					$layer['tag'] = 'h2';
					break;
				}
			}
			unset( $layer );

			// Names and alt text from the linked product or page.
			$target = $slide['link'] ?: self::first_link( $slide['layers'] );
			$name   = $target ? self::title_for_url( $target ) : '';
			if ( ! $slide['title'] ) {
				$slide['title'] = $name ?: self::first_text( $slide['layers'] );
			}
			foreach ( [ 'd', 'm' ] as $bp ) {
				$img = $slide['image'][ $bp ];
				if ( $img['url'] && ( ! $img['alt'] || self::is_file_name( $img['alt'], $img['url'] ) ) ) {
					$slide['image'][ $bp ]['alt'] = $name ?: ( $slide['title'] ?: self::pretty_file_name( $img['url'] ) );
				}
			}
			foreach ( $slide['layers'] as &$layer ) {
				if ( 'image' === $layer['type'] && self::is_file_name( $layer['image']['alt'], $layer['image']['url'] ) ) {
					$layer['image']['alt'] = ''; // decorative unless someone writes a real description
				}
			}
			unset( $layer );
			$out[] = $slide;
		}

		return [
			'slider' => [ 'title' => $slider['title'], 'alias' => $slider['alias'], 'settings' => $settings, 'slides' => $out ],
			'notes'  => array_values( array_unique( $notes ) ),
		];
	}

	private static function layer( array $l, $cd, $cm, array &$notes, $slide_no ) {
		$type = (string) ( $l['type'] ?? '' );
		if ( ! in_array( $type, [ 'text', 'button', 'image' ], true ) ) {
			/* translators: 1: layer type, 2: slide number */
			$notes[] = sprintf( __( 'Skipped a "%1$s" layer on slide %2$d (no equivalent).', 'lean-slider' ), $type, $slide_no );
			return null;
		}
		if ( 'image' === $type && empty( $l['media']['imageUrl'] ) ) {
			return null;
		}
		// Shown only on Slider Revolution's in-between sizes (laptop/tablet): Lean Slider has desktop and phone.
		if ( ! self::visible( $l, 'd' ) && ! self::visible( $l, 'm' ) ) {
			return null;
		}
		$layer         = Sliders::default_layer( $type );
		$layer['name'] = (string) ( $l['alias'] ?? '' );
		$idle          = (array) ( $l['idle'] ?? [] );

		foreach ( [ 'd' => $cd, 'm' => $cm ] as $bp => $canvas ) {
			$pos = (array) ( $l['position'] ?? [] );
			$fs  = self::num( self::v( $idle['fontSize'] ?? [], $bp ) ) ?: 20;
			$lh  = self::num( self::v( $idle['lineHeight'] ?? [], $bp ) );
			$w   = self::v( $l['size']['width'] ?? [], $bp );
			$layer['bp'][ $bp ] = [
				'show' => self::visible( $l, $bp ) ? 1 : 0,
				'x'    => self::px( self::v( $pos['x'] ?? [], $bp ), $canvas['w'] ),
				'y'    => self::px( self::v( $pos['y'] ?? [], $bp ), $canvas['h'] ),
				'w'    => 'image' === $type || ( is_string( $w ) && preg_match( '/^\d/', $w ) && 'text' === $type && self::has_break_need( $l ) ) ? self::px( $w, $canvas['w'] ) : 0,
				'ha'   => self::align( self::v( $pos['horizontal'] ?? [], $bp ), [ 'left', 'center', 'right' ] ),
				'va'   => self::align( self::v( $pos['vertical'] ?? [], $bp ), [ 'top', 'middle', 'bottom' ] ),
				'ta'   => self::align( self::v( $idle['textAlign'] ?? [], $bp ), [ 'left', 'center', 'right' ] ),
				'fs'   => $fs,
				// Slider Revolution's default line height is 25px.
				'lh'   => $fs ? round( ( $lh ?: 25 ) / $fs, 3 ) : 1.2,
			];
		}

		if ( 'image' === $type ) {
			$layer['image'] = Sliders::image_from_url( (string) $l['media']['imageUrl'] );
		} else {
			$text          = str_replace( [ "\r", "\n" ], ' ', (string) ( $l['text'] ?? '' ) );
			$layer['text'] = trim( preg_replace( '/\s+/', ' ', wp_kses( $text, Sliders::text_html() ) ) );
			$pad           = self::v( $idle['padding'] ?? [], 'd' );
			$radius        = (array) ( $idle['borderRadius']['v'] ?? [] );
			$hover         = (array) ( $l['hover'] ?? [] );
			// Slider Revolution draws text white unless told otherwise.
			$color         = self::color( self::v( $idle['color'] ?? [], 'd' ) ) ?: '#ffffff';
			$bg            = self::color( $idle['backgroundColor'] ?? '' );
			$h_color       = self::color( $hover['color'] ?? '' );
			$h_bg          = self::color( $hover['backgroundColor'] ?? '' );
			$layer['style'] = array_merge( $layer['style'], [
				'color'          => $color,
				'bg'             => 'transparent' === $bg ? '' : $bg,
				'hover_color'    => 'button' === $type && $h_color && $h_color !== $color ? $h_color : '',
				'hover_bg'       => 'button' === $type && $h_bg && $h_bg !== $bg ? $h_bg : '',
				'font_family'    => (string) ( $idle['fontFamily'] ?? '' ),
				'font_weight'    => (string) ( self::v( $idle['fontWeight'] ?? [], 'd' ) ?: '400' ), // Slider Revolution's default

				'letter_spacing' => self::num( self::v( $idle['letterSpacing'] ?? [], 'd' ) ),
				'radius'         => self::num( $radius[0] ?? 0 ),
				'padding'        => is_array( $pad ) ? array_map( [ __CLASS__, 'num' ], array_pad( $pad, 4, 0 ) ) : [ 0, 0, 0, 0 ],
				'uppercase'      => 'uppercase' === ( $idle['textTransform'] ?? '' ) ? 1 : 0,
				'italic'         => 'italic' === ( $idle['fontStyle'] ?? '' ) || true === ( $idle['fontStyle'] ?? false ) ? 1 : 0,
			] );
		}
		$link = self::link( $l );
		if ( $link ) {
			$layer['link']    = $link[0];
			$layer['new_tab'] = $link[1];
		}
		$layer['anim'] = self::anim( $l );
		return Sliders::normalize_layer( $layer );
	}

	/** Entrance animation and loop from Slider Revolution's timeline. */
	private static function anim( array $l ) {
		$frames = (array) ( $l['timeline']['frames'] ?? [] );
		$f0     = (array) ( $frames['frame_0']['transform'] ?? [] );
		$f1     = (array) ( $frames['frame_1'] ?? [] );
		$x      = self::num( self::v( $f0['x'] ?? [], 'd' ) );
		$y      = self::num( self::v( $f0['y'] ?? [], 'd' ) );
		$scale  = isset( $f0['scaleX'] ) ? (float) self::v( $f0['scaleX'], 'd' ) : 1;
		$opac   = isset( $f0['opacity'] ) ? (float) $f0['opacity'] : 0;

		$in = 'fade';
		if ( abs( $y ) >= abs( $x ) && abs( $y ) >= 2 ) {
			$in = $y > 0 ? 'up' : 'down';
		} elseif ( abs( $x ) >= 2 ) {
			$in = $x > 0 ? 'left' : 'right';
		} elseif ( $scale && $scale < 0.98 ) {
			$in = 'zoom';
		} elseif ( $opac >= 1 ) {
			$in = 'none';
		}
		$start = 0;
		foreach ( (array) ( $l['timeline']['frameOrder'] ?? [] ) as $fo ) {
			if ( 'frame_1' === ( $fo['id'] ?? '' ) ) {
				$start = (int) ( $fo['start'] ?? 0 );
			}
		}
		$start = $start ?: (int) ( $f1['timeline']['start'] ?? 0 );
		$dur   = (int) ( $f1['timeline']['speed'] ?? ( $f1['timeline']['frameLength'] ?? 300 ) );

		$anim = [
			'in'         => $in,
			'delay'      => max( 0, $start ),
			'duration'   => $dur ?: 300,
			'distance'   => max( abs( $x ), abs( $y ) ) ?: 50,
			'loop'       => 'none',
			'loop_x'     => 0,
			'loop_y'     => 0,
			'loop_speed' => 4000,
		];
		$loop = (array) ( $l['timeline']['loop'] ?? [] );
		if ( ! empty( $loop['use'] ) ) {
			$lx = ( self::num( $loop['frame_999']['x'] ?? 0 ) - self::num( $loop['frame_0']['x'] ?? 0 ) ) / 2;
			$ly = ( self::num( $loop['frame_999']['y'] ?? 0 ) - self::num( $loop['frame_0']['y'] ?? 0 ) ) / 2;
			if ( $lx || $ly ) {
				$anim['loop']       = 'float';
				$anim['loop_x']     = $lx;
				$anim['loop_y']     = $ly;
				$anim['loop_speed'] = (int) self::num( $loop['speed'] ?? 4000 ) ?: 4000;
			}
		}
		return $anim;
	}

	/* ---------- Helpers ---------- */

	/** Value for a breakpoint, inheriting downwards the way Slider Revolution does (d → n → t → m). */
	private static function v( $field, $bp ) {
		if ( ! is_array( $field ) ) {
			return $field;
		}
		$chain = 'm' === $bp ? [ 'm', 't', 'n', 'd' ] : [ 'd' ];
		foreach ( $chain as $k ) {
			if ( isset( $field[ $k ] ) && is_array( $field[ $k ] ) && array_key_exists( 'v', $field[ $k ] ) && '' !== $field[ $k ]['v'] && 'inherit' !== $field[ $k ]['v'] ) {
				return $field[ $k ]['v'];
			}
		}
		return isset( $field['v'] ) ? $field['v'] : null;
	}

	private static function visible( array $l, $bp ) {
		$vis = (array) ( $l['visibility'] ?? [] );
		return ! array_key_exists( $bp, $vis ) || false !== $vis[ $bp ];
	}

	/** Text with explicit width that should wrap (more than one word and no manual breaks). */
	private static function has_break_need( array $l ) {
		$text = (string) ( $l['text'] ?? '' );
		return false === stripos( $text, '<br' ) && str_word_count( wp_strip_all_tags( $text ) ) > 3;
	}

	public static function num( $v ) {
		if ( is_array( $v ) ) {
			$v = $v['v'] ?? ( $v[0] ?? 0 );
		}
		return is_numeric( $v ) ? (float) $v : (float) preg_replace( '/[^0-9.\-]/', '', (string) $v );
	}

	/** Pixels from "123px", "50%" (of the canvas side) or a number. */
	private static function px( $v, $side ) {
		if ( is_string( $v ) && str_ends_with( trim( $v ), '%' ) ) {
			return round( self::num( $v ) / 100 * $side, 2 );
		}
		return 'auto' === $v ? 0 : self::num( $v );
	}

	private static function align( $v, array $allowed ) {
		return in_array( $v, $allowed, true ) ? $v : $allowed[0];
	}

	/** [url, new_tab] of a layer's first link action. */
	private static function link( array $l ) {
		foreach ( (array) ( $l['actions']['action'] ?? [] ) as $a ) {
			if ( 'link' === ( $a['action'] ?? '' ) ) {
				$url = (string) ( $a['image_link'] ?? ( $a['link_url'] ?? '' ) );
				if ( $url ) {
					return [ $url, '_blank' === ( $a['link_open_in'] ?? '' ) ? 1 : 0 ];
				}
			}
		}
		return null;
	}

	/** Colour from Slider Revolution: plain value or its gradient JSON (with & for quotes). */
	public static function color( $v ) {
		if ( is_array( $v ) ) {
			$v = $v['v'] ?? '';
		}
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return '';
		}
		if ( '{' === $v[0] ) {
			$g = json_decode( str_replace( '&', '"', $v ), true );
			if ( ! is_array( $g ) || empty( $g['colors'] ) ) {
				return '';
			}
			$stops = [];
			foreach ( $g['colors'] as $c ) {
				$stop = sprintf( 'rgba(%d,%d,%d,%s) %s%%', $c['r'] ?? 0, $c['g'] ?? 0, $c['b'] ?? 0, lsl_num( $c['a'] ?? 1 ), lsl_num( $c['position'] ?? 0 ) );
				if ( ! in_array( $stop, $stops, true ) ) {
					$stops[] = $stop;
				}
			}
			if ( 1 === count( $stops ) ) {
				return preg_replace( '/ [\d.]+%$/', '', $stops[0] );
			}
			return 'radial' === ( $g['type'] ?? '' )
				? 'radial-gradient(circle,' . implode( ',', $stops ) . ')'
				: 'linear-gradient(' . (int) ( $g['angle'] ?? 180 ) . 'deg,' . implode( ',', $stops ) . ')';
		}
		return lsl_css_color( $v );
	}

	/** Product / page title for an internal URL (used for alt text and slide names). */
	private static function title_for_url( $url ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== $host ) {
			return '';
		}
		$id = url_to_postid( strtok( $url, '?' ) );
		if ( ! $id ) {
			$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
			$slug = basename( $path );
			if ( $slug ) {
				$post = get_page_by_path( $slug, OBJECT, [ 'product', 'page', 'post' ] );
				$id   = $post ? $post->ID : 0;
				if ( ! $id ) {
					$term = get_term_by( 'slug', $slug, 'product_cat' );
					if ( $term ) {
						return html_entity_decode( $term->name, ENT_QUOTES );
					}
				}
			}
		}
		return $id ? html_entity_decode( get_the_title( $id ), ENT_QUOTES ) : '';
	}

	/** Alt text that is really just the file name (e.g. "freelift_noemi-brow-lash-world"). */
	private static function is_file_name( $alt, $url ) {
		$norm = static fn( $s ) => preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $s ) );
		$file = pathinfo( (string) wp_parse_url( (string) $url, PHP_URL_PATH ), PATHINFO_FILENAME );
		$file = preg_replace( '/-(scaled|\d+x\d+|\d)$/', '', $file );
		return '' !== $norm( $alt ) && ( $norm( $alt ) === $norm( $file ) || str_starts_with( $norm( $file ), $norm( $alt ) ) || preg_match( '/^[a-z0-9]+(?:[_-][a-z0-9]+)+$/i', trim( (string) $alt ) ) );
	}

	private static function pretty_file_name( $url ) {
		$file = pathinfo( (string) wp_parse_url( (string) $url, PHP_URL_PATH ), PATHINFO_FILENAME );
		$file = preg_replace( '/-(scaled|\d+x\d+|\d)$/', '', $file );
		return ucfirst( trim( preg_replace( '/[_-]+/', ' ', $file ) ) );
	}

	private static function first_link( array $layers ) {
		foreach ( $layers as $l ) {
			if ( $l['link'] ) {
				return $l['link'];
			}
		}
		return '';
	}

	private static function first_text( array $layers ) {
		foreach ( $layers as $l ) {
			if ( 'text' === $l['type'] && '' !== trim( wp_strip_all_tags( $l['text'] ) ) ) {
				return trim( wp_strip_all_tags( $l['text'] ) );
			}
		}
		return '';
	}
}
