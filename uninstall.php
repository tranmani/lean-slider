<?php
/**
 * Remove Lean Slider's sliders and settings when the plugin is deleted
 * (deactivating keeps everything).
 *
 * @package LeanSlider
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$lsl_ids = get_posts( [ 'post_type' => 'lean_slider', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] );
foreach ( $lsl_ids as $lsl_id ) {
	wp_delete_post( $lsl_id, true );
}
delete_option( 'lsl_settings' );
delete_site_transient( 'lsl_update_release' );
