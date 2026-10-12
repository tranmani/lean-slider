<?php
/**
 * Elementor: a "Lean Slider" widget, and — when takeover is on — a stand-in for
 * Slider Revolution's widget so existing Elementor pages keep their slider after
 * Slider Revolution is switched off (Elementor would otherwise drop the widget).
 *
 * @package LeanSlider
 */

namespace LeanSlider;

defined( 'ABSPATH' ) || exit;

class Elementor {

	public static function init() {
		add_action( 'elementor/widgets/register', [ __CLASS__, 'register' ], 99 );
	}

	public static function register( $manager ) {
		require_once LSL_DIR . '/includes/elementor-widgets.php';
		$manager->register( new Elementor\Lean_Slider_Widget() );
		if ( lsl_takeover() ) {
			if ( $manager->get_widget_types( 'slider_revolution' ) ) {
				$manager->unregister( 'slider_revolution' );
			}
			$manager->register( new Elementor\Rev_Slider_Widget() );
		}
	}
}
