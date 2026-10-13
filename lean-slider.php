<?php
/**
 * Plugin Name:       Lean Slider
 * Plugin URI:        https://github.com/tranmani/lean-slider
 * Description:       A fast, search-friendly slider: real HTML images, headings and buttons, separate desktop and mobile images, CSS animations and a few kilobytes of script. Imports your Slider Revolution sliders and takes over their shortcodes and Elementor widgets.
 * Version:           1.0.4
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Lean Slider contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lean-slider
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'LSL_VERSION', '1.0.4' );
define( 'LSL_FILE', __FILE__ );
define( 'LSL_DIR', __DIR__ );
define( 'LSL_URL', plugin_dir_url( __FILE__ ) );

require_once LSL_DIR . '/includes/functions.php';
require_once LSL_DIR . '/includes/class-sliders.php';
require_once LSL_DIR . '/includes/class-render.php';
require_once LSL_DIR . '/includes/class-preload.php';
require_once LSL_DIR . '/includes/class-shortcodes.php';
require_once LSL_DIR . '/includes/class-elementor.php';
require_once LSL_DIR . '/includes/class-importer.php';
require_once LSL_DIR . '/includes/class-updater.php';
require_once LSL_DIR . '/includes/class-preview.php';

if ( is_admin() ) {
	require_once LSL_DIR . '/admin/class-admin.php';
}

add_action( 'init', static function () {
	load_plugin_textdomain( 'lean-slider', false, dirname( plugin_basename( LSL_FILE ) ) . '/languages' );
	LeanSlider\Sliders::register();
	LeanSlider\Render::register_assets();
	LeanSlider\Shortcodes::init();
	LeanSlider\Preload::init();
	LeanSlider\Preview::init();
}, 5 );

add_action( 'plugins_loaded', static function () {
	LeanSlider\Updater::init();
	LeanSlider\Elementor::init();
	if ( is_admin() ) {
		LeanSlider\Admin\Admin::init();
	}
} );
