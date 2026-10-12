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
 * takeover: who answers [rev_slider] / [rev_slider_vc] shortcodes and Elementor's
 *   "Slider Revolution" widget — 'auto' (Lean Slider once Slider Revolution is
 *   switched off), 'always' (Lean Slider even while it is on, for testing), 'never'.
 */
function lsl_default_settings() {
	return [
		'takeover' => 'auto',
		'updates'  => 1,
	];
}

function lsl_settings( $refresh = false ) {
	static $settings = null;
	if ( null === $settings || $refresh ) {
		$saved    = get_option( 'lsl_settings', [] );
		$settings = array_merge( lsl_default_settings(), is_array( $saved ) ? $saved : [] );
	}
	return $settings;
}

function lsl_update_settings( array $settings ) {
	$clean = [
		'takeover' => in_array( $settings['takeover'] ?? '', [ 'auto', 'always', 'never' ], true ) ? $settings['takeover'] : 'auto',
		'updates'  => empty( $settings['updates'] ) ? 0 : 1,
	];
	update_option( 'lsl_settings', $clean, true );
	lsl_settings( true );
}

/** Is Slider Revolution itself loaded? */
function lsl_revslider_active() {
	return class_exists( 'RevSliderFront' ) || class_exists( 'RevSliderGlobals' ) || defined( 'RS_REVISION' );
}

/** Should Lean Slider answer Slider Revolution's shortcodes and widget? */
function lsl_takeover() {
	$mode = lsl_settings()['takeover'];
	return 'always' === $mode || ( 'auto' === $mode && ! lsl_revslider_active() );
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
