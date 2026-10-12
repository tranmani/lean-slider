<?php
/**
 * Slider storage.
 *
 * Each slider is a private `lean_slider` post: title = name, slug = alias (what
 * shortcodes use), and the whole design as JSON in the `_lsl_data` meta. Data is
 * normalised on every read and write, so templates never have to guess.
 *
 * Positions and sizes are stored in canvas pixels per breakpoint ('d' desktop,
 * 'm' mobile) exactly as an editor shows them; the renderer turns them into
 * percentages so the slider scales with its container.
 *
 * @package LeanSlider
 */

namespace LeanSlider;

defined( 'ABSPATH' ) || exit;

class Sliders {

	const TYPE = 'lean_slider';
	const META = '_lsl_data';

	const ANIMS = [ 'none', 'fade', 'up', 'down', 'left', 'right', 'zoom' ];
	const TAGS  = [ 'p', 'div', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ];

	public static function register() {
		register_post_type( self::TYPE, [
			'label'           => __( 'Sliders', 'lean-slider' ),
			'public'          => false,
			'show_ui'         => false,
			'show_in_rest'    => false,
			'rewrite'         => false,
			'query_var'       => false,
			'supports'        => [ 'title' ],
			'capability_type' => 'page',
		] );
	}

	/* ---------- Defaults ---------- */

	public static function default_settings() {
		return [
			'canvas'      => [ 'd' => [ 'w' => 1240, 'h' => 680 ], 'm' => [ 'w' => 480, 'h' => 460 ] ],
			'breakpoint'  => 777,
			'autoplay'    => 1,
			'delay'       => 6000,
			'speed'       => 400,
			'transition'  => 'fade',
			'pause_hover' => 1,
			'loop'        => 1,
			'arrows'      => 1,
			'dots'        => 0,
			'arrow_color' => '#ffffff',
			'arrow_bg'    => 'rgba(0,0,0,0.5)',
			'max_width'   => 0,
		];
	}

	public static function default_slide() {
		return [
			'uid'     => self::uid(),
			'title'   => '',
			'hidden'  => 0,
			'link'    => '',
			'new_tab' => 0,
			'delay'   => 0,
			'bg'      => [ 'color' => '', 'image' => self::empty_image() ],
			'image'   => [ 'd' => self::empty_image(), 'm' => self::empty_image() ],
			'layers'  => [],
		];
	}

	public static function default_layer( $type = 'text' ) {
		$pos = [ 'show' => 1, 'x' => 60, 'y' => 60, 'w' => 0, 'ha' => 'left', 'va' => 'top', 'ta' => 'left', 'fs' => 40, 'lh' => 1.2 ];
		return [
			'uid'     => self::uid(),
			'type'    => in_array( $type, [ 'text', 'button', 'image' ], true ) ? $type : 'text',
			'name'    => '',
			'text'    => 'button' === $type ? __( 'Shop now', 'lean-slider' ) : ( 'text' === $type ? __( 'Your headline', 'lean-slider' ) : '' ),
			'tag'     => 'p',
			'link'    => '',
			'new_tab' => 0,
			'image'   => self::empty_image(),
			'style'   => [
				'color'          => 'button' === $type ? '#ffffff' : '#222222',
				'bg'             => 'button' === $type ? '#222222' : '',
				'hover_color'    => '',
				'hover_bg'       => '',
				'font_family'    => '',
				'font_weight'    => 'button' === $type ? '600' : '500',
				'letter_spacing' => 0,
				'radius'         => 0,
				'padding'        => 'button' === $type ? [ 12, 32, 12, 32 ] : [ 0, 0, 0, 0 ],
				'uppercase'      => 0,
				'italic'         => 0,
			],
			'bp'      => [ 'd' => $pos, 'm' => array_merge( $pos, [ 'x' => 24, 'y' => 24, 'fs' => 28 ] ) ],
			'anim'    => [ 'in' => 'fade', 'delay' => 0, 'duration' => 800, 'distance' => 50, 'loop' => 'none', 'loop_x' => 0, 'loop_y' => 6, 'loop_speed' => 4000 ],
		];
	}

	public static function empty_image() {
		return [ 'id' => 0, 'url' => '', 'w' => 0, 'h' => 0, 'alt' => '' ];
	}

	public static function uid() {
		return substr( md5( uniqid( '', true ) ), 0, 8 );
	}

	/* ---------- Normalisation ---------- */

	public static function normalize( $data ) {
		$data     = is_array( $data ) ? $data : [];
		$defaults = self::default_settings();
		$in       = (array) ( $data['settings'] ?? [] );
		$s        = [];
		foreach ( [ 'd', 'm' ] as $bp ) {
			$s['canvas'][ $bp ] = [
				'w' => self::int( $in['canvas'][ $bp ]['w'] ?? $defaults['canvas'][ $bp ]['w'], 100, 4000 ),
				'h' => self::int( $in['canvas'][ $bp ]['h'] ?? $defaults['canvas'][ $bp ]['h'], 50, 4000 ),
			];
		}
		$s['breakpoint'] = self::int( $in['breakpoint'] ?? $defaults['breakpoint'], 320, 1600 );
		foreach ( [ 'autoplay', 'pause_hover', 'loop', 'arrows', 'dots' ] as $flag ) {
			$s[ $flag ] = empty( $in[ $flag ] ?? $defaults[ $flag ] ) ? 0 : 1;
		}
		$s['delay']       = self::int( $in['delay'] ?? $defaults['delay'], 1000, 60000 );
		$s['speed']       = self::int( $in['speed'] ?? $defaults['speed'], 0, 5000 );
		$s['transition']  = in_array( $in['transition'] ?? '', [ 'fade', 'slide' ], true ) ? $in['transition'] : 'fade';
		$s['arrow_color'] = lsl_css_color( $in['arrow_color'] ?? '' ) ?: $defaults['arrow_color'];
		$s['arrow_bg']    = lsl_css_color( $in['arrow_bg'] ?? '' ) ?: $defaults['arrow_bg'];
		$s['max_width']   = self::int( $in['max_width'] ?? 0, 0, 5000 );

		$slides = [];
		foreach ( (array) ( $data['slides'] ?? [] ) as $slide ) {
			if ( is_array( $slide ) ) {
				$slides[] = self::normalize_slide( $slide );
			}
		}

		return [
			'title'    => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'alias'    => sanitize_title( (string) ( $data['alias'] ?? '' ) ),
			'settings' => $s,
			'slides'   => $slides,
		];
	}

	public static function normalize_slide( array $in ) {
		$d      = self::default_slide();
		$layers = [];
		foreach ( (array) ( $in['layers'] ?? [] ) as $layer ) {
			if ( is_array( $layer ) ) {
				$layers[] = self::normalize_layer( $layer );
			}
		}
		return [
			'uid'     => self::clean_uid( $in['uid'] ?? '' ),
			'title'   => sanitize_text_field( (string) ( $in['title'] ?? '' ) ),
			'hidden'  => empty( $in['hidden'] ) ? 0 : 1,
			'link'    => esc_url_raw( (string) ( $in['link'] ?? '' ) ),
			'new_tab' => empty( $in['new_tab'] ) ? 0 : 1,
			'delay'   => self::int( $in['delay'] ?? 0, 0, 60000 ),
			'bg'      => [
				'color' => lsl_css_color( $in['bg']['color'] ?? $d['bg']['color'] ),
				'image' => self::normalize_image( $in['bg']['image'] ?? [] ),
			],
			'image'   => [
				'd' => self::normalize_image( $in['image']['d'] ?? [] ),
				'm' => self::normalize_image( $in['image']['m'] ?? [] ),
			],
			'layers'  => $layers,
		];
	}

	public static function normalize_layer( array $in ) {
		$type = in_array( $in['type'] ?? '', [ 'text', 'button', 'image' ], true ) ? $in['type'] : 'text';
		$d    = self::default_layer( $type );
		$st   = (array) ( $in['style'] ?? [] );
		$pad  = array_values( array_map( 'floatval', array_pad( array_slice( (array) ( $st['padding'] ?? $d['style']['padding'] ), 0, 4 ), 4, 0 ) ) );
		$bp   = [];
		foreach ( [ 'd', 'm' ] as $k ) {
			$p        = (array) ( $in['bp'][ $k ] ?? $d['bp'][ $k ] );
			$bp[ $k ] = [
				'show' => empty( $p['show'] ?? 1 ) ? 0 : 1,
				'x'    => self::float( $p['x'] ?? 0, -5000, 5000 ),
				'y'    => self::float( $p['y'] ?? 0, -5000, 5000 ),
				'w'    => self::float( $p['w'] ?? 0, 0, 8000 ),
				'ha'   => in_array( $p['ha'] ?? '', [ 'left', 'center', 'right' ], true ) ? $p['ha'] : 'left',
				'va'   => in_array( $p['va'] ?? '', [ 'top', 'middle', 'bottom' ], true ) ? $p['va'] : 'top',
				'ta'   => in_array( $p['ta'] ?? '', [ 'left', 'center', 'right' ], true ) ? $p['ta'] : 'left',
				'fs'   => self::float( $p['fs'] ?? $d['bp'][ $k ]['fs'], 1, 400 ),
				'lh'   => self::float( $p['lh'] ?? 1.2, 0.5, 5 ),
			];
		}
		$a = (array) ( $in['anim'] ?? [] );
		return [
			'uid'     => self::clean_uid( $in['uid'] ?? '' ),
			'type'    => $type,
			'name'    => sanitize_text_field( (string) ( $in['name'] ?? '' ) ),
			'text'    => wp_kses( (string) ( $in['text'] ?? '' ), self::text_html() ),
			'tag'     => in_array( $in['tag'] ?? '', self::TAGS, true ) ? $in['tag'] : 'p',
			'link'    => esc_url_raw( (string) ( $in['link'] ?? '' ) ),
			'new_tab' => empty( $in['new_tab'] ) ? 0 : 1,
			'image'   => self::normalize_image( $in['image'] ?? [] ),
			'style'   => [
				'color'          => lsl_css_color( $st['color'] ?? '' ),
				'bg'             => lsl_css_color( $st['bg'] ?? '' ),
				'hover_color'    => lsl_css_color( $st['hover_color'] ?? '' ),
				'hover_bg'       => lsl_css_color( $st['hover_bg'] ?? '' ),
				'font_family'    => preg_replace( '/[^a-zA-Z0-9 ,\'"-]/', '', (string) ( $st['font_family'] ?? '' ) ),
				'font_weight'    => in_array( (string) ( $st['font_weight'] ?? '' ), [ '', '100', '200', '300', '400', '500', '600', '700', '800', '900' ], true ) ? (string) $st['font_weight'] : '',
				'letter_spacing' => self::float( $st['letter_spacing'] ?? 0, -50, 100 ),
				'radius'         => self::float( $st['radius'] ?? 0, 0, 500 ),
				'padding'        => array_map( static fn( $v ) => max( 0, min( 500, $v ) ), $pad ),
				'uppercase'      => empty( $st['uppercase'] ) ? 0 : 1,
				'italic'         => empty( $st['italic'] ) ? 0 : 1,
			],
			'bp'      => $bp,
			'anim'    => [
				'in'         => in_array( $a['in'] ?? '', self::ANIMS, true ) ? $a['in'] : 'none',
				'delay'      => self::int( $a['delay'] ?? 0, 0, 60000 ),
				'duration'   => self::int( $a['duration'] ?? 800, 0, 20000 ),
				'distance'   => self::float( $a['distance'] ?? 50, 0, 2000 ),
				'loop'       => in_array( $a['loop'] ?? '', [ 'none', 'float' ], true ) ? $a['loop'] : 'none',
				'loop_x'     => self::float( $a['loop_x'] ?? 0, -500, 500 ),
				'loop_y'     => self::float( $a['loop_y'] ?? 0, -500, 500 ),
				'loop_speed' => self::int( $a['loop_speed'] ?? 4000, 200, 60000 ),
			],
		];
	}

	public static function normalize_image( $in ) {
		$in  = is_array( $in ) ? $in : [];
		$id  = absint( $in['id'] ?? 0 );
		$url = esc_url_raw( (string) ( $in['url'] ?? '' ) );
		$w   = absint( $in['w'] ?? 0 );
		$h   = absint( $in['h'] ?? 0 );
		if ( $id && ( ! $url || ! $w ) ) {
			$src = wp_get_attachment_image_src( $id, 'full' );
			if ( $src ) {
				$url = $url ?: $src[0];
				$w   = $w ?: (int) $src[1];
				$h   = $h ?: (int) $src[2];
			}
		}
		return [ 'id' => $id, 'url' => $url, 'w' => $w, 'h' => $h, 'alt' => sanitize_text_field( (string) ( $in['alt'] ?? '' ) ) ];
	}

	/** Image record for a URL: finds the media library item (for srcset) and its size. */
	public static function image_from_url( $url, $alt = '' ) {
		$url = esc_url_raw( (string) $url );
		if ( ! $url ) {
			return self::empty_image();
		}
		$id = attachment_url_to_postid( $url );
		// Resized copies ("-300x200") and big-image copies ("-scaled") belong to the original upload.
		foreach ( [ '/-\d+x\d+(\.[a-z0-9]+)$/i', '/-scaled(\.[a-z0-9]+)$/i' ] as $pattern ) {
			if ( ! $id && preg_match( $pattern, $url ) ) {
				$id = attachment_url_to_postid( preg_replace( $pattern, '$1', $url ) );
			}
		}
		if ( ! $id ) {
			global $wpdb;
			$file = ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
			$file = preg_replace( '#^.*?uploads/#', '', $file );
			if ( $file ) {
				$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", $file ) );
			}
			if ( ! $id && $file ) {
				$id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata' AND ( meta_value LIKE %s OR meta_value LIKE %s ) LIMIT 1",
					'%' . $wpdb->esc_like( '"' . $file . '"' ) . '%',
					'%' . $wpdb->esc_like( '"' . basename( $file ) . '"' ) . '%'
				) );
			}
		}
		$w = 0;
		$h = 0;
		if ( $id ) {
			$meta = wp_get_attachment_metadata( $id );
			$w    = (int) ( $meta['width'] ?? 0 );
			$h    = (int) ( $meta['height'] ?? 0 );
			$alt  = $alt ?: (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		}
		return self::normalize_image( [ 'id' => $id, 'url' => $url, 'w' => $w, 'h' => $h, 'alt' => $alt ] );
	}

	/** Inline HTML allowed inside text layers and buttons. */
	public static function text_html() {
		return [
			'br'     => [],
			'strong' => [],
			'b'      => [],
			'em'     => [],
			'i'      => [],
			'u'      => [],
			'small'  => [],
			'sup'    => [],
			'sub'    => [],
			'span'   => [ 'class' => true, 'style' => true ],
		];
	}

	/* ---------- CRUD ---------- */

	/** @return array{id:int,title:string,alias:string,updated:string,slides:int}[] */
	public static function all() {
		$out = [];
		foreach ( get_posts( [ 'post_type' => self::TYPE, 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] ) as $post ) {
			$data  = self::get( $post->ID );
			$out[] = [
				'id'      => $post->ID,
				'title'   => $post->post_title,
				'alias'   => $post->post_name,
				'updated' => $post->post_modified,
				'slides'  => count( array_filter( $data['slides'], static fn( $s ) => ! $s['hidden'] ) ),
			];
		}
		return $out;
	}

	public static function get( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || self::TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}
		$data          = json_decode( (string) get_post_meta( $post->ID, self::META, true ), true );
		$data          = self::normalize( is_array( $data ) ? $data : [] );
		$data['id']    = $post->ID;
		$data['title'] = $post->post_title;
		$data['alias'] = $post->post_name;
		return $data;
	}

	public static function find( $id_or_alias ) {
		if ( is_numeric( $id_or_alias ) ) {
			return self::get( (int) $id_or_alias );
		}
		$alias = sanitize_title( (string) $id_or_alias );
		if ( '' === $alias ) {
			return null;
		}
		$ids = get_posts( [ 'post_type' => self::TYPE, 'post_status' => 'publish', 'name' => $alias, 'numberposts' => 1, 'fields' => 'ids' ] );
		return $ids ? self::get( $ids[0] ) : null;
	}

	/** Create or update. Returns the post ID or WP_Error. */
	public static function save( array $data, $id = 0 ) {
		$data  = self::normalize( $data );
		$title = '' !== $data['title'] ? $data['title'] : __( 'Untitled slider', 'lean-slider' );
		$alias = '' !== $data['alias'] ? $data['alias'] : sanitize_title( $title );
		$post  = [
			'post_type'   => self::TYPE,
			'post_status' => 'publish',
			'post_title'  => $title,
			'post_name'   => $alias,
		];
		if ( $id ) {
			$post['ID'] = (int) $id;
			$result     = wp_update_post( wp_slash( $post ), true );
		} else {
			$result = wp_insert_post( wp_slash( $post ), true );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		unset( $data['title'], $data['alias'] );
		update_post_meta( $result, self::META, wp_slash( wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
		do_action( 'lsl_slider_saved', $result );
		return (int) $result;
	}

	public static function delete( $id ) {
		$post = get_post( (int) $id );
		return $post && self::TYPE === $post->post_type ? (bool) wp_delete_post( $post->ID, true ) : false;
	}

	public static function duplicate( $id ) {
		$data = self::get( $id );
		if ( ! $data ) {
			return new \WP_Error( 'lsl_missing', __( 'Slider not found.', 'lean-slider' ) );
		}
		/* translators: %s: slider name */
		$data['title'] = sprintf( __( '%s (copy)', 'lean-slider' ), $data['title'] );
		$data['alias'] = $data['alias'] . '-copy';
		return self::save( $data );
	}

	/* ---------- Helpers ---------- */

	private static function int( $v, $min, $max ) {
		return max( $min, min( $max, (int) round( (float) $v ) ) );
	}

	private static function float( $v, $min, $max ) {
		return max( $min, min( $max, round( (float) $v, 2 ) ) );
	}

	private static function clean_uid( $v ) {
		$v = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $v ) );
		return '' !== $v ? substr( $v, 0, 12 ) : self::uid();
	}
}
