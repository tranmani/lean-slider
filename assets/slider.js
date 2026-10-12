/* Lean Slider — autoplay, arrows, dots, keyboard and swipe. No dependencies. */
(function () {
	'use strict';

	var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function init(root) {
		if (!root || root.lslInit) return;
		root.lslInit = true;

		var cfg = {};
		try { cfg = JSON.parse(root.getAttribute('data-lsl') || '{}'); } catch (e) {}
		var slides = Array.prototype.slice.call(root.querySelectorAll('.lsl-slide'));
		var dots = Array.prototype.slice.call(root.querySelectorAll('[data-lsl-go]'));
		var n = slides.length;
		var index = Math.max(0, slides.findIndex(function (s) { return s.classList.contains('is-active'); }));
		var timer = null, paused = false, hover = false, visible = true;
		var slideMode = cfg.mode === 'slide';

		function focusables(slide, on) {
			slide.querySelectorAll('a, button').forEach(function (el) {
				if (on) el.removeAttribute('tabindex'); else el.setAttribute('tabindex', '-1');
			});
		}

		function go(to, dir) {
			if (n < 2) return;
			if (!cfg.loop && (to < 0 || to >= n)) return;
			to = (to + n) % n;
			if (to === index) return;
			dir = dir || (to > index ? 1 : -1);
			var from = slides[index], next = slides[to];

			if (slideMode) {
				next.style.transition = 'none';
				next.style.transform = 'translateX(' + (dir * 100) + '%)';
				void next.offsetWidth; // apply the start position before animating
				next.style.transition = '';
				next.style.transform = '';
				from.classList.add('is-leaving');
				from.style.transform = 'translateX(' + (-dir * 100) + '%)';
				setTimeout(function () { from.classList.remove('is-leaving'); from.style.transform = ''; }, (cfg.speed || 0) + 50);
			}

			from.classList.remove('is-active');
			from.setAttribute('aria-hidden', 'true');
			focusables(from, false);
			next.classList.add('is-active');
			next.removeAttribute('aria-hidden');
			focusables(next, true);
			dots.forEach(function (d, i) { if (i === to) d.setAttribute('aria-current', 'true'); else d.removeAttribute('aria-current'); });
			index = to;
			schedule();
		}

		function schedule() {
			clearTimeout(timer);
			if (!cfg.autoplay || reduced || paused || hover || !visible || n < 2) return;
			if (!cfg.loop && index === n - 1) return;
			var d = parseInt(slides[index].getAttribute('data-delay'), 10) || cfg.delay || 6000;
			timer = setTimeout(function () { go(index + 1, 1); }, d);
		}

		root.addEventListener('click', function (e) {
			var t = e.target.closest('[data-lsl-prev],[data-lsl-next],[data-lsl-go]');
			if (!t || !root.contains(t)) return;
			if (t.hasAttribute('data-lsl-prev')) go(index - 1, -1);
			else if (t.hasAttribute('data-lsl-next')) go(index + 1, 1);
			else go(parseInt(t.getAttribute('data-lsl-go'), 10));
		});

		root.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowLeft') { go(index - 1, -1); e.preventDefault(); }
			if (e.key === 'ArrowRight') { go(index + 1, 1); e.preventDefault(); }
		});

		if (cfg.pause) {
			root.addEventListener('mouseenter', function () { hover = true; schedule(); });
			root.addEventListener('mouseleave', function () { hover = false; schedule(); });
		}
		root.addEventListener('focusin', function () { paused = true; schedule(); });
		root.addEventListener('focusout', function (e) { if (!root.contains(e.relatedTarget)) { paused = false; schedule(); } });

		// Swipe (touch and pen; a mouse drag is left alone so images can still be dragged out).
		var sx = 0, sy = 0, swiping = false, swiped = false;
		root.addEventListener('pointerdown', function (e) {
			if (e.pointerType === 'mouse') return;
			sx = e.clientX; sy = e.clientY; swiping = true; swiped = false;
		}, { passive: true });
		root.addEventListener('pointerup', function (e) {
			if (!swiping) return;
			swiping = false;
			var dx = e.clientX - sx, dy = e.clientY - sy;
			if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy) * 1.2) {
				swiped = true;
				go(dx < 0 ? index + 1 : index - 1, dx < 0 ? 1 : -1);
			}
		}, { passive: true });
		root.addEventListener('pointercancel', function () { swiping = false; });
		root.addEventListener('click', function (e) { if (swiped) { e.preventDefault(); swiped = false; } }, true);

		if ('IntersectionObserver' in window) {
			new IntersectionObserver(function (entries) {
				visible = entries[0].isIntersecting;
				schedule();
			}, { threshold: 0.2 }).observe(root);
		}
		document.addEventListener('visibilitychange', function () { visible = !document.hidden; schedule(); });

		root.lsl = { go: go, next: function () { go(index + 1, 1); }, prev: function () { go(index - 1, -1); } };
		schedule();
	}

	// Other slides (and their images) appear only once the page itself has loaded.
	function ready(root) {
		var reveal = function () { root.classList.add('lsl-ready'); };
		if (window.requestIdleCallback) requestIdleCallback(reveal, { timeout: 1500 }); else setTimeout(reveal, 200);
	}

	function boot(scope) {
		(scope || document).querySelectorAll('.lsl[data-lsl]').forEach(function (root) {
			init(root);
			if (document.readyState === 'complete') ready(root);
			else window.addEventListener('load', function () { ready(root); }, { once: true });
		});
	}

	window.LeanSlider = { init: boot };
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { boot(); });
	else boot();

	// Elementor editor: widgets are re-rendered without a page load.
	window.addEventListener('elementor/frontend/init', function () {
		if (window.elementorFrontend && elementorFrontend.hooks) {
			elementorFrontend.hooks.addAction('frontend/element_ready/global', function ($el) { boot($el[0] || $el); });
		}
	});
})();
