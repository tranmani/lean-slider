=== Lean Slider ===
Contributors: leanslider
Tags: slider, carousel, hero, performance, seo
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A fast, search-friendly slider built for phones first. Imports your Slider Revolution sliders and takes over their shortcodes and Elementor widgets.

== Description ==

Big slider plugins load hundreds of kilobytes of script and build the slider in the browser, so the hero image appears late and search engines see an empty box. Lean Slider renders everything as plain HTML on the server and adds only what a slider actually needs.

* **In the page, for search engines** — images with alt text, srcset and real dimensions, headings, text and links are in the HTML. No script needed to see the first slide.
* **Phones first** — the phone image is the default `<img>`; the desktop image is a `min-width` source, so phones never download it. Thumb-sized arrows and dots, swipe.
* **Small** — about 4.5 KB of CSS (inlined once, no extra request) and 6 KB of script (2 KB gzipped, deferred), only on pages that show a slider. No jQuery.
* **Fast first paint** — the first image loads with `fetchpriority="high"`; other slides and their images wait until the page has loaded. No layout shift.
* **Layers** — text, buttons and images placed on a design canvas, separately for phone and desktop, scaling with the screen. Entrance animations (fade, rise, drop, slide in, zoom) and a gentle floating loop, all CSS.
* **Live editor** — phone and desktop preview with your theme's fonts; drag layers to move them.
* **Accessible** — keyboard, pause on hover/focus, pauses when off screen or in a background tab, respects "reduce motion".
* **Import from Slider Revolution** — one click per slider: canvas sizes, desktop and mobile images, links, text, buttons, fonts, colours, hover colours, per-device visibility, animations and floating loops. Alt text is filled from the linked product's name.
* **Switch without editing pages** — imported sliders keep their alias, and Lean Slider answers `[rev_slider alias="…"]`, `[rev_slider_vc]` and Elementor's "Slider Revolution" widget once Slider Revolution is deactivated (or right away, to check the result first).
* Shortcode `[lean_slider alias="home"]` and a "Lean Slider" Elementor widget.
* Updates from GitHub through WordPress's normal "Update now".

== Installation ==

1. Download `lean-slider.zip` from the latest GitHub release and upload it on Plugins → Add New → Upload.
2. Activate. Go to Sliders → Import to bring over Slider Revolution sliders, or Sliders → Add slider.
3. To switch an existing site: import, set Sliders → Settings → "Slider Revolution shortcodes" to **Now**, check your pages, then deactivate Slider Revolution and set it back to **Automatic**.

== Frequently Asked Questions ==

= Does importing change my Slider Revolution sliders? =

No. Lean Slider only reads them. Importing again replaces the Lean Slider copy.

= What is not imported? =

Shapes, videos, particle and other special effects, and Slider Revolution's in-between laptop/tablet layouts (Lean Slider has a phone and a desktop design; tablets use the desktop design scaled). The import notes list anything skipped.

= Which fonts are used? =

Your theme's, unless a layer names a font. Lean Slider never loads fonts itself.

= What happens when I delete the plugin? =

Deactivating keeps everything. Deleting the plugin removes its sliders and settings.

== Changelog ==

= 1.0.0 =
* First release.
