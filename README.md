# Lean Slider

A fast, search-friendly WordPress slider built for phones first. Everything a visitor or a search engine needs is in the page HTML; a few kilobytes of CSS and script add autoplay, arrows, dots, keyboard and swipe.

It also imports Slider Revolution sliders and answers their `[rev_slider]` shortcodes and Elementor widget, so a site can drop a ~550 KB slider engine without editing a single page.

## Why

| | Slider Revolution 6.7 (SR7) | Lean Slider |
|---|---|---|
| Script on every slider page | ~550 KB, 170 KB gzipped (`sr7.js` + `tptools.js`) | 6 KB, 2 KB gzipped, deferred |
| CSS | separate requests | 4.5 KB inlined once (2 KB gzipped) |
| Slides in the HTML | no — built in the browser | yes: `<picture>`, alt text, headings, links |
| Phone image | chosen by script | default `<img>`; desktop is a `min-width` `<source>` |
| First image | waits for the script | `fetchpriority="high"`, real width/height |

## Features

- Phone and desktop designs on a scaling canvas (percentages + container query units)
- Image slides (phone + desktop image, link) and layered slides (text, buttons, images)
- CSS entrance animations and a floating loop; respects `prefers-reduced-motion`
- Fade or slide transitions, autoplay with pause on hover/focus/off-screen, keyboard, swipe
- Live editor with your theme's fonts, drag-to-position
- Import from Slider Revolution 6 / 6.7, with alt text from linked products
- `[lean_slider alias="…"]`, Elementor widget, takeover of `[rev_slider]`, `[rev_slider_vc]` and Elementor's Slider Revolution widget
- Updates from GitHub releases

## Install

Download `lean-slider.zip` from the [latest release](https://github.com/tranmani/lean-slider/releases/latest) and upload it under Plugins → Add New → Upload.

## Switching from Slider Revolution

1. Sliders → Import → import the sliders your pages use (they keep their alias).
2. Sliders → Settings → Slider Revolution shortcodes → **Now**, and check your pages.
3. Deactivate Slider Revolution; set the option back to **Automatic**.

## License

GPL-2.0-or-later.
