<?php
/**
 * Elementor widget classes (loaded only when Elementor registers widgets).
 *
 * @package LeanSlider
 */

namespace LeanSlider\Elementor;

use LeanSlider\Render;
use LeanSlider\Shortcodes;
use LeanSlider\Sliders;

defined( 'ABSPATH' ) || exit;

class Lean_Slider_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'lean_slider';
	}

	public function get_title() {
		return __( 'Lean Slider', 'lean-slider' );
	}

	public function get_icon() {
		return 'eicon-slides';
	}

	public function get_categories() {
		return [ 'general' ];
	}

	public function get_keywords() {
		return [ 'slider', 'carousel', 'hero', 'banner' ];
	}

	public function get_script_depends() {
		return [ Render::HANDLE ];
	}

	protected function register_controls() {
		$options = [ '' => __( '— Choose a slider —', 'lean-slider' ) ];
		foreach ( Sliders::all() as $slider ) {
			$options[ $slider['alias'] ] = $slider['title'];
		}
		$this->start_controls_section( 'content', [ 'label' => __( 'Slider', 'lean-slider' ) ] );
		$this->add_control( 'alias', [
			'label'   => __( 'Slider', 'lean-slider' ),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'options' => $options,
			'default' => '',
		] );
		$this->add_control( 'manage', [
			'type' => \Elementor\Controls_Manager::RAW_HTML,
			'raw'  => '<a href="' . esc_url( admin_url( 'admin.php?page=lean-slider' ) ) . '" target="_blank">' . esc_html__( 'Edit sliders', 'lean-slider' ) . '</a>',
		] );
		$this->end_controls_section();
	}

	protected function render() {
		$alias = (string) $this->get_settings_for_display( 'alias' );
		echo Shortcodes::output( Sliders::find( $alias ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built with escaping in Render.
	}
}

/**
 * Same name and settings as Slider Revolution's Elementor widget ("slider_revolution",
 * with the [rev_slider alias="…"] shortcode in its "shortcode" setting).
 */
class Rev_Slider_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'slider_revolution';
	}

	public function get_title() {
		return __( 'Slider Revolution (Lean Slider)', 'lean-slider' );
	}

	public function get_icon() {
		return 'eicon-slides';
	}

	public function get_categories() {
		return [ 'general' ];
	}

	public function get_script_depends() {
		return [ Render::HANDLE ];
	}

	protected function register_controls() {
		$this->start_controls_section( 'content', [ 'label' => __( 'Slider', 'lean-slider' ) ] );
		$this->add_control( 'shortcode', [
			'label'       => __( 'Shortcode', 'lean-slider' ),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'description' => __( 'Shown by the Lean Slider with the same alias.', 'lean-slider' ),
		] );
		$this->add_control( 'revslidertitle', [ 'type' => \Elementor\Controls_Manager::HIDDEN ] );
		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$alias    = Shortcodes::alias_from_shortcode( $settings['shortcode'] ?? '' );
		echo Shortcodes::output( '' !== $alias ? Sliders::find( $alias ) : null ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
