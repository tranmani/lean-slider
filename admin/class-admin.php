<?php
/**
 * Admin screens: Sliders (list + editor), Import, Settings, About.
 *
 * The editor is a small script working on the slider's JSON; saving posts that
 * JSON back and the server normalises it. The live preview is a real front-end
 * page (theme fonts and styles) that receives freshly rendered HTML by message,
 * so typing doesn't reload anything.
 *
 * @package LeanSlider
 */

namespace LeanSlider\Admin;

use LeanSlider\Importer;
use LeanSlider\Render;
use LeanSlider\Sliders;
use LeanSlider\Updater;

defined( 'ABSPATH' ) || exit;

class Admin {

	const SLUG = 'lean-slider';
	const CAP  = 'edit_theme_options';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_post' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'wp_ajax_lsl_render', [ __CLASS__, 'ajax_render' ] );
		add_action( 'wp_ajax_lsl_check_updates', [ __CLASS__, 'ajax_check_updates' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( LSL_FILE ), [ __CLASS__, 'action_links' ] );
		add_filter( 'plugin_row_meta', [ __CLASS__, 'row_meta' ], 10, 2 );
		add_action( 'admin_notices', [ __CLASS__, 'notices' ] );
	}

	public static function url( $args = [] ) {
		return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'admin.php' ) );
	}

	public static function menu() {
		add_menu_page( __( 'Lean Slider', 'lean-slider' ), __( 'Sliders', 'lean-slider' ), self::CAP, self::SLUG, [ __CLASS__, 'page' ], 'dashicons-images-alt2', 59 );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Sliders', 'lean-slider' ) . '</a>' );
		return $links;
	}

	public static function row_meta( $links, $file ) {
		if ( plugin_basename( LSL_FILE ) === $file ) {
			$links[] = '<a href="' . esc_url( 'https://github.com/' . Updater::repository() ) . '" target="_blank" rel="noopener">' . esc_html__( 'View on GitHub', 'lean-slider' ) . '</a>';
			$links[] = '<a href="' . esc_url( 'https://github.com/' . Updater::repository() . '/issues' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Report an issue', 'lean-slider' ) . '</a>';
		}
		return $links;
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'lean-slider-admin', LSL_URL . 'assets/admin.css', [], LSL_VERSION );
		wp_enqueue_script( 'lean-slider-admin', LSL_URL . 'assets/admin.js', [], LSL_VERSION, true );
		$editing = isset( $_GET['edit'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $editing ) {
			wp_enqueue_media();
		}
		wp_localize_script( 'lean-slider-admin', 'LSL', [
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'lsl_admin' ),
			'preview'   => add_query_arg( [ 'lsl_preview' => 1, '_lsl' => wp_create_nonce( 'lsl_preview' ) ], home_url( '/' ) ),
			'origin'    => untrailingslashit( home_url() ),
			'defaults'  => [
				'slide'    => Sliders::default_slide(),
				'text'     => Sliders::default_layer( 'text' ),
				'button'   => Sliders::default_layer( 'button' ),
				'image'    => Sliders::default_layer( 'image' ),
				'settings' => Sliders::default_settings(),
			],
			'i18n'      => [
				'confirmDelete' => __( 'Delete this slide?', 'lean-slider' ),
				'confirmLayer'  => __( 'Delete this layer?', 'lean-slider' ),
				'unsaved'       => __( 'You have unsaved changes.', 'lean-slider' ),
				'chooseImage'   => __( 'Choose image', 'lean-slider' ),
				'useImage'      => __( 'Use this image', 'lean-slider' ),
				'checking'      => __( 'Checking…', 'lean-slider' ),
				'copied'        => __( 'Copied', 'lean-slider' ),
			],
		] );
	}

	/* ---------- Actions ---------- */

	public static function handle_post() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- each branch verifies its own nonce.
		$action = sanitize_key( $_REQUEST['lsl_action'] ?? '' );
		if ( ! $action ) {
			return;
		}
		switch ( $action ) {
			case 'save':
				check_admin_referer( 'lsl_save' );
				$data = json_decode( wp_unslash( (string) ( $_POST['lsl_data'] ?? '' ) ), true );
				if ( ! is_array( $data ) ) {
					self::redirect( [ 'edit' => absint( $_POST['id'] ?? 0 ), 'msg' => 'invalid' ] );
				}
				$id = Sliders::save( $data, absint( $_POST['id'] ?? 0 ) );
				if ( is_wp_error( $id ) ) {
					self::redirect( [ 'edit' => absint( $_POST['id'] ?? 0 ), 'msg' => 'error' ] );
				}
				self::redirect( [ 'edit' => $id, 'msg' => 'saved' ] );
				break;
			case 'delete':
				check_admin_referer( 'lsl_delete_' . absint( $_GET['id'] ?? 0 ) );
				Sliders::delete( absint( $_GET['id'] ?? 0 ) );
				self::redirect( [ 'msg' => 'deleted' ] );
				break;
			case 'duplicate':
				check_admin_referer( 'lsl_duplicate_' . absint( $_GET['id'] ?? 0 ) );
				$id = Sliders::duplicate( absint( $_GET['id'] ?? 0 ) );
				self::redirect( is_wp_error( $id ) ? [ 'msg' => 'error' ] : [ 'edit' => $id, 'msg' => 'duplicated' ] );
				break;
			case 'import':
				check_admin_referer( 'lsl_import' );
				$ids  = array_map( 'absint', (array) ( $_POST['rs'] ?? [] ) );
				$done = 0;
				$log  = [];
				foreach ( $ids as $rs_id ) {
					$result = Importer::import( $rs_id );
					if ( is_wp_error( $result ) ) {
						$log[] = $result->get_error_message();
						continue;
					}
					++$done;
					$log = array_merge( $log, $result['notes'] );
				}
				set_transient( 'lsl_import_log_' . get_current_user_id(), $log, 10 * MINUTE_IN_SECONDS );
				self::redirect( [ 'tab' => 'import', 'msg' => 'imported', 'n' => $done ] );
				break;
			case 'settings':
				check_admin_referer( 'lsl_settings' );
				lsl_update_settings( [
					'takeover' => ! empty( $_POST['takeover'] ),
					'updates'  => ! empty( $_POST['updates'] ),
				] );
				self::redirect( [ 'tab' => 'settings', 'msg' => 'settings' ] );
				break;
		}
		// phpcs:enable
	}

	private static function redirect( $args ) {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/** Rendered slider HTML for the live preview. */
	public static function ajax_render() {
		check_ajax_referer( 'lsl_admin' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		$data = json_decode( wp_unslash( (string) ( $_POST['data'] ?? '' ) ), true );
		if ( ! is_array( $data ) ) {
			wp_send_json_error( [ 'message' => 'Invalid data' ], 400 );
		}
		$slider       = Sliders::normalize( $data );
		$slider['id'] = absint( $_POST['id'] ?? 0 );
		Render::reset();
		wp_send_json_success( [
			'html' => Render::slider( $slider, [ 'preview' => true, 'slide' => absint( $_POST['slide'] ?? 0 ) ] ),
		] );
	}

	public static function ajax_check_updates() {
		check_ajax_referer( 'lsl_admin' );
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( null, 403 );
		}
		$latest = Updater::latest( true );
		delete_site_transient( 'update_plugins' );
		wp_send_json_success( [
			'latest'  => $latest['version'] ?? '',
			'current' => LSL_VERSION,
			'update'  => $latest && version_compare( $latest['version'], LSL_VERSION, '>' ),
			'url'     => Updater::update_url(),
		] );
	}

	public static function notices() {
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_' . self::SLUG !== $screen->id ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		$msg = sanitize_key( $_GET['msg'] ?? '' );
		$map = [
			'saved'      => [ 'success', __( 'Slider saved.', 'lean-slider' ) ],
			'deleted'    => [ 'success', __( 'Slider deleted.', 'lean-slider' ) ],
			'duplicated' => [ 'success', __( 'Slider duplicated — this is the copy.', 'lean-slider' ) ],
			'settings'   => [ 'success', __( 'Settings saved.', 'lean-slider' ) ],
			'invalid'    => [ 'error', __( 'The slider could not be read. Nothing was saved.', 'lean-slider' ) ],
			'error'      => [ 'error', __( 'Something went wrong. Nothing was saved.', 'lean-slider' ) ],
			/* translators: %d: number of sliders */
			'imported'   => [ 'success', sprintf( _n( '%d slider imported.', '%d sliders imported.', absint( $_GET['n'] ?? 0 ), 'lean-slider' ), absint( $_GET['n'] ?? 0 ) ) ], // phpcs:ignore WordPress.Security.NonceVerification
		];
		if ( isset( $map[ $msg ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $map[ $msg ][0] ), esc_html( $map[ $msg ][1] ) );
		}
	}

	/* ---------- Pages ---------- */

	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['edit'] ) ) {
			self::editor( 'new' === $_GET['edit'] ? 0 : absint( $_GET['edit'] ) );
			return;
		}
		$tab  = sanitize_key( $_GET['tab'] ?? 'sliders' );
		// phpcs:enable
		$tabs = [
			'sliders'  => __( 'Sliders', 'lean-slider' ),
			'import'   => __( 'Import', 'lean-slider' ),
			'settings' => __( 'Settings', 'lean-slider' ),
			'about'    => __( 'About', 'lean-slider' ),
		];
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'sliders';
		echo '<div class="wrap lsl">';
		echo '<div class="lsl-header"><h1>' . esc_html__( 'Lean Slider', 'lean-slider' ) . '</h1>';
		if ( 'sliders' === $tab ) {
			echo '<a class="button button-primary" href="' . esc_url( self::url( [ 'edit' => 'new' ] ) ) . '">' . esc_html__( 'Add slider', 'lean-slider' ) . '</a>';
		}
		echo '</div>';
		echo '<p class="lsl-sub">' . esc_html__( 'Fast, search-friendly sliders: real images, headings and buttons in the page, built for phones first.', 'lean-slider' ) . '</p>';
		echo '<nav class="lsl-tabs">';
		foreach ( $tabs as $key => $label ) {
			printf( '<a href="%s" class="%s">%s</a>', esc_url( self::url( [ 'tab' => $key ] ) ), $key === $tab ? 'is-active' : '', esc_html( $label ) );
		}
		echo '</nav>';
		call_user_func( [ __CLASS__, 'tab_' . $tab ] );
		echo '</div>';
	}

	private static function tab_sliders() {
		$sliders = Sliders::all();
		self::takeover_callout();
		if ( ! $sliders ) {
			echo '<div class="lsl-card lsl-empty"><strong>' . esc_html__( 'No sliders yet', 'lean-slider' ) . '</strong>';
			echo '<p>' . esc_html__( 'Create one, or bring your Slider Revolution sliders over in one click.', 'lean-slider' ) . '</p>';
			echo '<p class="lsl-actions" style="justify-content:center"><a class="button button-primary" href="' . esc_url( self::url( [ 'edit' => 'new' ] ) ) . '">' . esc_html__( 'Add slider', 'lean-slider' ) . '</a>';
			if ( Importer::available() ) {
				echo '<a class="button" href="' . esc_url( self::url( [ 'tab' => 'import' ] ) ) . '">' . esc_html__( 'Import from Slider Revolution', 'lean-slider' ) . '</a>';
			}
			echo '</p></div>';
			return;
		}
		echo '<table class="lsl-table"><thead><tr><th>' . esc_html__( 'Slider', 'lean-slider' ) . '</th><th>' . esc_html__( 'Shortcode', 'lean-slider' ) . '</th><th>' . esc_html__( 'Slides', 'lean-slider' ) . '</th><th>' . esc_html__( 'Shown on', 'lean-slider' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $sliders as $s ) {
			$used = Importer::used_on( $s['alias'] );
			$code = '[lean_slider alias="' . $s['alias'] . '"]';
			echo '<tr>';
			printf( '<td><a class="lsl-strong" href="%s">%s</a><div class="lsl-muted">%s</div></td>', esc_url( self::url( [ 'edit' => $s['id'] ] ) ), esc_html( $s['title'] ), esc_html( sprintf( /* translators: %s: date */ __( 'Updated %s', 'lean-slider' ), mysql2date( get_option( 'date_format' ), $s['updated'] ) ) ) );
			printf( '<td><button type="button" class="lsl-code" data-lsl-copy="%1$s" title="%2$s"><code>%1$s</code></button></td>', esc_attr( $code ), esc_attr__( 'Copy', 'lean-slider' ) );
			printf( '<td>%d</td>', (int) $s['slides'] );
			echo '<td>' . self::used_links( $used ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			printf(
				'<td class="lsl-row-actions"><a href="%s">%s</a><a href="%s">%s</a><a class="lsl-danger" href="%s" onclick="return confirm(\'%s\')">%s</a></td>',
				esc_url( self::url( [ 'edit' => $s['id'] ] ) ),
				esc_html__( 'Edit', 'lean-slider' ),
				esc_url( wp_nonce_url( self::url( [ 'lsl_action' => 'duplicate', 'id' => $s['id'] ] ), 'lsl_duplicate_' . $s['id'] ) ),
				esc_html__( 'Duplicate', 'lean-slider' ),
				esc_url( wp_nonce_url( self::url( [ 'lsl_action' => 'delete', 'id' => $s['id'] ] ), 'lsl_delete_' . $s['id'] ) ),
				esc_js( __( 'Delete this slider? Pages showing it will show nothing.', 'lean-slider' ) ),
				esc_html__( 'Delete', 'lean-slider' )
			);
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function used_links( array $ids ) {
		if ( ! $ids ) {
			return '<span class="lsl-muted">—</span>';
		}
		$out = [];
		foreach ( array_slice( $ids, 0, 4 ) as $id ) {
			$url   = get_post_status( $id ) === 'publish' && is_post_type_viewable( get_post_type( $id ) ) ? get_permalink( $id ) : get_edit_post_link( $id );
			$out[] = '<a href="' . esc_url( (string) $url ) . '" target="_blank">' . esc_html( get_the_title( $id ) ?: '#' . $id ) . '</a>';
		}
		if ( count( $ids ) > 4 ) {
			/* translators: %d: number of further pages */
			$out[] = esc_html( sprintf( __( '+%d more', 'lean-slider' ), count( $ids ) - 4 ) );
		}
		return implode( ', ', $out );
	}

	/** Reminds that imported sliders are not on the pages until the switch is on. */
	private static function takeover_callout() {
		if ( lsl_takeover() || ! Importer::available() ) {
			return;
		}
		echo '<div class="lsl-callout">' . wp_kses_post( sprintf(
			/* translators: %s: settings URL */
			__( 'Imported sliders appear on your pages once you <a href="%s">switch them on</a>.', 'lean-slider' ),
			esc_url( self::url( [ 'tab' => 'settings' ] ) )
		) ) . '</div>';
	}

	private static function tab_import() {
		$rows = Importer::available();
		$log  = get_transient( 'lsl_import_log_' . get_current_user_id() );
		if ( $log ) {
			delete_transient( 'lsl_import_log_' . get_current_user_id() );
			echo '<div class="lsl-card"><h2>' . esc_html__( 'Import notes', 'lean-slider' ) . '</h2><ul class="lsl-list">';
			foreach ( (array) $log as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul></div>';
		}
		if ( ! $rows ) {
			echo '<div class="lsl-card lsl-empty"><strong>' . esc_html__( 'No Slider Revolution sliders found', 'lean-slider' ) . '</strong><p>' . esc_html__( 'There is nothing to import on this site.', 'lean-slider' ) . '</p></div>';
			return;
		}
		echo '<div class="lsl-card"><h2>' . esc_html__( 'Import from Slider Revolution', 'lean-slider' ) . '</h2>';
		echo '<p class="lsl-hint">' . esc_html__( 'Sliders keep their alias, so their [rev_slider] shortcodes and Elementor widgets show the Lean Slider version once you switch over. Importing again replaces the Lean Slider copy (your changes to it are lost). Slider Revolution itself is not changed.', 'lean-slider' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'lsl_import' );
		echo '<input type="hidden" name="lsl_action" value="import">';
		echo '<table class="lsl-table"><thead><tr><th class="check-column"><input type="checkbox" data-lsl-check-all></th><th>' . esc_html__( 'Slider', 'lean-slider' ) . '</th><th>' . esc_html__( 'Alias', 'lean-slider' ) . '</th><th>' . esc_html__( 'Slides', 'lean-slider' ) . '</th><th>' . esc_html__( 'Shown on', 'lean-slider' ) . '</th><th>' . esc_html__( 'Status', 'lean-slider' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr>';
			printf( '<td><input type="checkbox" name="rs[]" value="%d"%s></td>', (int) $r['id'], $r['used'] && ! $r['imported'] ? ' checked' : '' );
			printf( '<td class="lsl-strong">%s</td><td><code>%s</code></td><td>%d</td>', esc_html( $r['title'] ), esc_html( $r['alias'] ), (int) $r['slides'] );
			echo '<td>' . self::used_links( $r['used'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . ( $r['imported']
				? '<span class="lsl-pill lsl-pill--ok">' . esc_html__( 'Imported', 'lean-slider' ) . '</span> <a href="' . esc_url( self::url( [ 'edit' => $r['imported'] ] ) ) . '">' . esc_html__( 'Edit', 'lean-slider' ) . '</a>'
				: '<span class="lsl-pill lsl-pill--off">' . esc_html__( 'Not imported', 'lean-slider' ) . '</span>' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table><p class="lsl-actions"><button class="button button-primary">' . esc_html__( 'Import selected', 'lean-slider' ) . '</button></p></form></div>';
	}

	private static function tab_settings() {
		$s = lsl_settings();
		echo '<form method="post" class="lsl-card">';
		wp_nonce_field( 'lsl_settings' );
		echo '<input type="hidden" name="lsl_action" value="settings">';
		echo '<h2>' . esc_html__( 'Use imported sliders on your pages', 'lean-slider' ) . '</h2>';
		echo '<p class="lsl-hint">' . esc_html__( 'Imported sliders keep their alias. When this is on, pages that still contain the old slider shortcode or Elementor widget show the Lean Slider version instead — no page needs editing.', 'lean-slider' ) . '</p>';
		printf( '<div class="lsl-field lsl-switches"><label><input type="checkbox" name="takeover" value="1"%s><span><strong>%s</strong></span></label></div>', checked( $s['takeover'], 1, false ), esc_html__( 'On', 'lean-slider' ) );
		echo '<p class="lsl-hint">' . ( lsl_takeover() ? '<span class="lsl-pill lsl-pill--ok">' . esc_html__( 'On — your pages show Lean Slider', 'lean-slider' ) . '</span>' : '<span class="lsl-pill lsl-pill--off">' . esc_html__( 'Off', 'lean-slider' ) . '</span>' ) . '</p>';
		echo '<h2>' . esc_html__( 'Updates', 'lean-slider' ) . '</h2>';
		printf( '<div class="lsl-field lsl-switches"><label><input type="checkbox" name="updates" value="1"%s><span>%s</span></label></div>', checked( $s['updates'], 1, false ), esc_html__( 'Check GitHub for new versions (shown on Dashboard → Updates).', 'lean-slider' ) );
		echo '<p class="lsl-actions"><button class="button button-primary">' . esc_html__( 'Save settings', 'lean-slider' ) . '</button></p></form>';
	}

	private static function tab_about() {
		$cached = Updater::cached();
		echo '<div class="lsl-card"><div class="lsl-about-hero"><span class="dashicons dashicons-images-alt2 lsl-about-icon"></span><div>';
		echo '<h2>' . esc_html__( 'Lean Slider', 'lean-slider' ) . ' ' . esc_html( LSL_VERSION ) . '</h2>';
		echo '<p class="lsl-hint--inline">' . esc_html__( 'Open source, GPL-2.0-or-later.', 'lean-slider' ) . ' <a href="https://github.com/' . esc_attr( Updater::repository() ) . '" target="_blank">GitHub</a></p></div></div>';
		echo '<ul class="lsl-list">';
		foreach ( [
			__( 'Everything is in the page HTML: images with alt text and real sizes, headings, text and links — search engines and screen readers see it all.', 'lean-slider' ),
			__( 'Phones first: phone images are the default, desktop images only load on large screens.', 'lean-slider' ),
			__( 'About 4.5 KB of CSS (inlined, no extra request) and 6 KB of script (2 KB compressed), only on pages that show a slider. The first image loads with high priority; other slides wait until the page has loaded.', 'lean-slider' ),
			__( 'No layout shift, respects “reduce motion”, keyboard and swipe navigation, pauses when off screen.', 'lean-slider' ),
		] as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul></div>';
		echo '<div class="lsl-card lsl-card-row"><div><h2>' . esc_html__( 'Updates', 'lean-slider' ) . '</h2><p class="lsl-hint--inline" data-lsl-update-status>';
		echo $cached ? esc_html( sprintf( /* translators: %s: version */ __( 'Latest release: %s', 'lean-slider' ), $cached['version'] ) ) : esc_html__( 'Not checked yet.', 'lean-slider' );
		echo '</p></div><button type="button" class="button" data-lsl-check-updates>' . esc_html__( 'Check for updates', 'lean-slider' ) . '</button></div>';
	}

	/* ---------- Editor ---------- */

	private static function editor( $id ) {
		$slider = $id ? Sliders::get( $id ) : null;
		if ( $id && ! $slider ) {
			echo '<div class="wrap lsl"><p>' . esc_html__( 'Slider not found.', 'lean-slider' ) . '</p></div>';
			return;
		}
		if ( ! $slider ) {
			$slider = Sliders::normalize( [ 'title' => __( 'New slider', 'lean-slider' ), 'slides' => [ Sliders::default_slide() ] ] );
		}
		unset( $slider['id'] );
		echo '<div class="wrap lsl lsl-editor-wrap">';
		echo '<p class="lsl-back"><a href="' . esc_url( self::url() ) . '">← ' . esc_html__( 'All sliders', 'lean-slider' ) . '</a></p>';
		echo '<form method="post" id="lsl-form">';
		wp_nonce_field( 'lsl_save' );
		echo '<input type="hidden" name="lsl_action" value="save"><input type="hidden" name="id" value="' . (int) $id . '"><input type="hidden" name="lsl_data" id="lsl-data">';
		echo '<div class="lsl-editor-bar"><h1 data-lsl-title>' . esc_html( $slider['title'] ) . '</h1><span class="lsl-dirty" data-lsl-dirty hidden>' . esc_html__( 'Unsaved changes', 'lean-slider' ) . '</span><span class="spacer"></span>';
		if ( $id ) {
			printf( '<button type="button" class="lsl-code" data-lsl-copy="%1$s"><code>%1$s</code></button>', esc_attr( '[lean_slider alias="' . $slider['alias'] . '"]' ) );
		}
		echo '<button class="button button-primary">' . esc_html__( 'Save slider', 'lean-slider' ) . '</button></div>';
		echo '<div class="lsl-editor"><div class="lsl-editor__main" id="lsl-app"></div>';
		echo '<div class="lsl-editor__side"><div class="lsl-preview" data-lsl-preview-box>';
		echo '<div class="lsl-preview__bar"><div class="lsl-segmented lsl-segmented--small" data-lsl-device>';
		echo '<label><input type="radio" name="lsl_device" value="m" checked><span>' . esc_html__( 'Phone', 'lean-slider' ) . '</span></label>';
		echo '<label><input type="radio" name="lsl_device" value="d"><span>' . esc_html__( 'Desktop', 'lean-slider' ) . '</span></label></div>';
		echo '<div class="lsl-preview__nav"><button type="button" class="button button-small" data-lsl-pv-prev aria-label="' . esc_attr__( 'Previous slide', 'lean-slider' ) . '">‹</button><span data-lsl-pv-label></span><button type="button" class="button button-small" data-lsl-pv-next aria-label="' . esc_attr__( 'Next slide', 'lean-slider' ) . '">›</button>';
		echo '<button type="button" class="button button-small" data-lsl-pv-replay>' . esc_html__( 'Replay', 'lean-slider' ) . '</button></div></div>';
		echo '<div class="lsl-preview__stage" data-lsl-stage><iframe title="' . esc_attr__( 'Preview', 'lean-slider' ) . '" data-lsl-frame></iframe></div>';
		echo '<p class="lsl-preview__hint">' . esc_html__( 'Drag text, buttons and images to move them. Click one to edit it.', 'lean-slider' ) . '</p>';
		echo '</div></div></div></form>';
		echo '<script type="application/json" id="lsl-initial">' . wp_json_encode( $slider, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>';
		echo '</div>';
	}
}
