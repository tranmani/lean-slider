<?php
/**
 * Settings and small helpers.
 *
 * @package LeanSlider
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-wide settings.
 *
 * takeover: 1 = imported sliders replace their originals — Lean Slider answers the
 *   [rev_slider] / [rev_slider_vc] shortcodes and Elementor's "Slider Revolution" widget. 0 = off.
 */
function lsl_default_settings() {
	return [
		'takeover' => 0,
		'updates'  => 1,
	];
}

function lsl_settings( $refresh = false ) {
	static $settings = null;
	if ( null === $settings || $refresh ) {
		$saved    = get_option( 'lsl_settings', [] );
		$settings = array_merge( lsl_default_settings(), is_array( $saved ) ? $saved : [] );
		// 1.0.0 stored 'auto' / 'always' / 'never'; only 'always' meant replacing.
		$settings['takeover'] = ( 1 === (int) $settings['takeover'] || 'always' === $settings['takeover'] ) ? 1 : 0;
	}
	return $settings;
}

function lsl_update_settings( array $settings ) {
	$clean = [
		'takeover' => empty( $settings['takeover'] ) ? 0 : 1,
		'updates'  => empty( $settings['updates'] ) ? 0 : 1,
	];
	update_option( 'lsl_settings', $clean, true );
	lsl_settings( true );
}

/** Should Lean Slider answer the original slider shortcodes and widget? */
function lsl_takeover() {
	return 1 === lsl_settings()['takeover'];
}

/** Number with at most 4 decimals, no trailing zeros (for compact inline styles). */
function lsl_num( $n ) {
	$s = rtrim( rtrim( number_format( (float) $n, 4, '.', '' ), '0' ), '.' );
	return '-0' === $s ? '0' : $s;
}

/** CSS colour / gradient value or ''. Keeps only characters a colour or gradient can contain. */
function lsl_css_color( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}
	return preg_match( '/^[#a-zA-Z0-9(),.%\s-]+$/', $value ) ? $value : '';
}
