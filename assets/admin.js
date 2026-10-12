/* Lean Slider — admin: list helpers, and the slider editor (works on the slider JSON, previews live). */
(function () {
	'use strict';
	var C = window.LSL || {};
	var t = C.i18n || {};

	/* ---------- Shared helpers (all tabs) ---------- */

	document.addEventListener('click', function (e) {
		var copy = e.target.closest('[data-lsl-copy]');
		if (copy) {
			e.preventDefault();
			navigator.clipboard && navigator.clipboard.writeText(copy.getAttribute('data-lsl-copy')).then(function () {
				copy.classList.add('is-copied');
				copy.setAttribute('data-copied', t.copied || 'Copied');
				setTimeout(function () { copy.classList.remove('is-copied'); }, 1400);
			});
		}
		var check = e.target.closest('[data-lsl-check-updates]');
		if (check) {
			var status = document.querySelector('[data-lsl-update-status]');
			check.disabled = true;
			status.textContent = t.checking || 'Checking…';
			post('lsl_check_updates', {}).then(function (r) {
				check.disabled = false;
				if (!r.success) { status.textContent = 'Could not reach GitHub.'; return; }
				var d = r.data;
				status.innerHTML = d.update
					? 'Version ' + esc(d.latest) + ' is available. <a href="' + esc(d.url) + '">Update now</a>'
					: 'You have the latest version (' + esc(d.current) + ').';
			});
		}
	});
	document.addEventListener('change', function (e) {
		if (e.target.matches('[data-lsl-check-all]')) {
			e.target.closest('table').querySelectorAll('tbody input[type=checkbox]').forEach(function (c) { c.checked = e.target.checked; });
		}
	});

	function post(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('_ajax_nonce', C.nonce);
		Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
		return fetch(C.ajax, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function clone(o) { return JSON.parse(JSON.stringify(o)); }
	function uid() { return Math.random().toString(16).slice(2, 10); }

	/* ---------- Editor ---------- */

	var app = document.getElementById('lsl-app');
	if (!app) return;

	var data = JSON.parse(document.getElementById('lsl-initial').textContent);
	var ui = { slide: 0, layer: '', device: 'm' };
	var dirty = false;
	var frame = document.querySelector('[data-lsl-frame]');
	var stage = document.querySelector('[data-lsl-stage]');
	var frameReady = false, frameHeight = 0;
	var origin = new URL(C.preview).origin;

	/** Tiny element builder: h('div', {class: 'x', onclick: fn}, [children]) */
	function h(tag, attrs, kids) {
		var el = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v == null || v === false) return;
			if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
			else if (k === 'html') el.innerHTML = v;
			else if (k === 'text') el.textContent = v;
			else if (k in el && k !== 'list' && k !== 'form') { try { el[k] = v; } catch (x) { el.setAttribute(k, v); } }
			else el.setAttribute(k, v === true ? '' : v);
		});
		[].concat(kids || []).forEach(function (c) { if (c != null && c !== false) el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return el;
	}

	function changed(structural) {
		dirty = true;
		document.querySelector('[data-lsl-dirty]').hidden = false;
		if (structural) render();
		schedulePreview();
	}

	/* Inputs bound to an object key. */
	function input(obj, key, o) {
		o = o || {};
		var type = o.type || 'text';
		var el;
		if (type === 'select') {
			el = h('select', {}, o.options.map(function (opt) {
				var val = Array.isArray(opt) ? opt[0] : opt, label = Array.isArray(opt) ? opt[1] : opt;
				return h('option', { value: val, selected: String(obj[key]) === String(val) }, label);
			}));
		} else if (type === 'checkbox') {
			el = h('input', { type: 'checkbox', checked: !!obj[key] });
		} else if (type === 'textarea') {
			// The server stores "&" as "&amp;"; show it the way it was typed.
			el = h('textarea', { rows: o.rows || 2, value: String(obj[key] == null ? '' : obj[key]).replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#0?39;/g, "'") });
		} else {
			el = h('input', { type: type, value: obj[key] == null ? '' : obj[key], step: o.step || (type === 'number' ? 'any' : null), min: o.min, max: o.max, placeholder: o.placeholder || '' });
		}
		var ev = type === 'checkbox' || type === 'select' ? 'change' : 'input';
		el.addEventListener(ev, function () {
			var v = type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
			if (type === 'number') v = v === '' ? 0 : parseFloat(v);
			obj[key] = v;
			if (o.onchange) o.onchange(v);
			changed(!!o.structural);
		});
		if (o.class) el.className = o.class;
		return el;
	}

	function field(label, control, hint, cls) {
		return h('div', { class: 'lsl-field' + (cls ? ' ' + cls : '') }, [
			h('label', {}, label),
			control,
			hint ? h('p', { class: 'description' }, hint) : null,
		]);
	}
	function check(obj, key, label) {
		return h('label', { class: 'lsl-check' }, [input(obj, key, { type: 'checkbox' }), ' ', label]);
	}
	function color(obj, key, label, hint) {
		var text = input(obj, key, { placeholder: '#ffffff, rgba(), linear-gradient()' });
		var pick = h('input', { type: 'color', value: /^#[0-9a-f]{6}$/i.test(obj[key] || '') ? obj[key] : '#ffffff', title: label });
		pick.addEventListener('input', function () { obj[key] = pick.value; text.value = pick.value; changed(false); });
		return field(label, h('div', { class: 'lsl-color' }, [pick, text]), hint);
	}
	function row(kids, cls) { return h('div', { class: 'lsl-row' + (cls ? ' ' + cls : '') }, kids); }

	function mediaPicker(img, label, hint) {
		var wrap = h('div', { class: 'lsl-media' });
		function draw() {
			wrap.innerHTML = '';
			wrap.appendChild(img.url
				? h('img', { src: img.url, alt: '' })
				: h('div', { class: 'lsl-media__empty' }, '—'));
			wrap.appendChild(h('div', { class: 'lsl-media__actions' }, [
				h('button', { type: 'button', class: 'button button-small', onclick: open }, img.url ? 'Change' : (t.chooseImage || 'Choose image')),
				img.url ? h('button', { type: 'button', class: 'button-link lsl-danger', onclick: function () { Object.assign(img, { id: 0, url: '', w: 0, h: 0 }); draw(); changed(false); } }, 'Remove') : null,
			]));
		}
		function open() {
			var f = wp.media({ title: label, button: { text: t.useImage || 'Use this image' }, library: { type: 'image' }, multiple: false });
			f.on('select', function () {
				var a = f.state().get('selection').first().toJSON();
				Object.assign(img, { id: a.id, url: a.url, w: a.width || 0, h: a.height || 0, alt: img.alt || a.alt || '' });
				changed(true);
			});
			f.open();
		}
		draw();
		return h('div', { class: 'lsl-field' }, [
			h('label', {}, label),
			wrap,
			img.url ? h('input', { type: 'text', value: img.alt || '', placeholder: 'Alt text — describe the image for Google and screen readers', oninput: function (e) { img.alt = e.target.value; changed(false); } }) : null,
			hint ? h('p', { class: 'description' }, hint) : null,
		]);
	}

	function iconButton(label, icon, fn, cls) {
		return h('button', { type: 'button', class: 'lsl-icon' + (cls ? ' ' + cls : ''), title: label, 'aria-label': label, onclick: function (e) { e.preventDefault(); e.stopPropagation(); fn(); } }, h('span', { class: 'dashicons dashicons-' + icon, 'aria-hidden': 'true' }));
	}
	function move(list, i, d) {
		var j = i + d;
		if (j < 0 || j >= list.length) return false;
		var x = list[i]; list[i] = list[j]; list[j] = x;
		return true;
	}

	/* ----- Sections ----- */

	function renderSlider() {
		var s = data.settings;
		var title = input(data, 'title', { onchange: function (v) { document.querySelector('[data-lsl-title]').textContent = v; } });
		var alias = input(data, 'alias', { placeholder: 'home-hero' });
		return h('div', { class: 'lsl-card' }, [
			h('h2', {}, 'Slider'),
			row([field('Name', title), field('Alias', alias, 'Used in the shortcode. Keep it if pages already use it.')]),
			h('details', { class: 'lsl-more' }, [
				h('summary', {}, 'Size, playback and arrows'),
				h('p', { class: 'lsl-label' }, 'Design size (px) — layers are placed on this canvas and scale with the screen'),
				row([
					field('Phone width', input(s.canvas.m, 'w', { type: 'number', structural: false })),
					field('Phone height', input(s.canvas.m, 'h', { type: 'number' })),
					field('Desktop width', input(s.canvas.d, 'w', { type: 'number' })),
					field('Desktop height', input(s.canvas.d, 'h', { type: 'number' })),
				], 'lsl-row--4'),
				row([
					field('Desktop layout from (px)', input(s, 'breakpoint', { type: 'number', onchange: function (v) { s.breakpoint = parseInt(v, 10) - 1 || 777; } }), 'Screens wider than this use the desktop design.'),
					field('Maximum width (px)', input(s, 'max_width', { type: 'number' }), '0 = as wide as its container.'),
				]),
				row([
					field('Each slide shows for (ms)', input(s, 'delay', { type: 'number', step: 500 })),
					field('Transition', input(s, 'transition', { type: 'select', options: [['fade', 'Fade'], ['slide', 'Slide']] })),
					field('Transition speed (ms)', input(s, 'speed', { type: 'number', step: 50 })),
				], 'lsl-row--3'),
				h('div', { class: 'lsl-checks' }, [
					check(s, 'autoplay', 'Play automatically'),
					check(s, 'pause_hover', 'Pause on hover'),
					check(s, 'loop', 'Loop'),
					check(s, 'arrows', 'Arrows'),
					check(s, 'dots', 'Dots'),
				]),
				row([color(s, 'arrow_color', 'Arrow colour'), color(s, 'arrow_bg', 'Arrow background')]),
			]),
		]);
	}

	function renderSlides() {
		var wrap = h('div', { class: 'lsl-slides' });
		data.slides.forEach(function (slide, i) {
			var open = ui.slide === i;
			var thumb = (slide.image.m.url || slide.image.d.url || slide.bg.image.url);
			var head = h('div', { class: 'lsl-slide-head', onclick: function () { ui.slide = open ? -1 : i; ui.layer = ''; render(); schedulePreview(); } }, [
				h('span', { class: 'lsl-thumb', style: thumb ? 'background-image:url(' + JSON.stringify(thumb) + ')' : (slide.bg.color ? 'background:' + slide.bg.color : '') }),
				h('span', { class: 'lsl-slide-title' }, [h('strong', {}, (i + 1) + '. ' + (slide.title || 'Slide')), slide.hidden ? h('span', { class: 'lsl-pill lsl-pill--off' }, 'Hidden') : null]),
				h('span', { class: 'lsl-slide-tools' }, [
					iconButton('Move up', 'arrow-up-alt2', function () { if (move(data.slides, i, -1)) { ui.slide = i - 1; changed(true); } }),
					iconButton('Move down', 'arrow-down-alt2', function () { if (move(data.slides, i, 1)) { ui.slide = i + 1; changed(true); } }),
					iconButton('Duplicate', 'admin-page', function () { var c = clone(slide); c.uid = uid(); c.layers.forEach(function (l) { l.uid = uid(); }); data.slides.splice(i + 1, 0, c); ui.slide = i + 1; changed(true); }),
					iconButton(slide.hidden ? 'Show' : 'Hide', slide.hidden ? 'hidden' : 'visibility', function () { slide.hidden = slide.hidden ? 0 : 1; changed(true); }),
					iconButton('Delete', 'trash', function () { if (confirm(t.confirmDelete || 'Delete this slide?')) { data.slides.splice(i, 1); ui.slide = Math.min(i, data.slides.length - 1); changed(true); } }, 'lsl-danger'),
				]),
			]);
			var card = h('div', { class: 'lsl-card lsl-slide-card' + (open ? ' is-open' : '') }, [head]);
			if (open) card.appendChild(renderSlideBody(slide));
			wrap.appendChild(card);
		});
		wrap.appendChild(h('button', { type: 'button', class: 'button', onclick: function () { var s = clone(C.defaults.slide); s.uid = uid(); data.slides.push(s); ui.slide = data.slides.length - 1; changed(true); } }, '+ Add slide'));
		return wrap;
	}

	function renderSlideBody(slide) {
		var body = h('div', { class: 'lsl-slide-body' });
		body.appendChild(row([field('Slide name', input(slide, 'title'), 'Also used as alt text when an image has none.'), field('Shows for (ms)', input(slide, 'delay', { type: 'number', step: 500 }), '0 = slider default.')]));
		body.appendChild(row([field('Link (whole slide)', input(slide, 'link', { type: 'url', placeholder: 'https://' })), h('div', { class: 'lsl-field lsl-field--check' }, [check(slide, 'new_tab', 'Open in a new tab')])]));

		body.appendChild(h('p', { class: 'lsl-label' }, 'Images'));
		body.appendChild(row([
			mediaPicker(slide.image.m, 'Phone image', 'Shown on phones. Optional — without it the desktop image is cropped to fit.'),
			mediaPicker(slide.image.d, 'Desktop image', 'Shown on larger screens.'),
		]));
		body.appendChild(h('details', { class: 'lsl-more' }, [
			h('summary', {}, 'Background'),
			row([color(slide.bg, 'color', 'Background colour', 'Fills the sides on wide screens, and behind transparent images.'), mediaPicker(slide.bg.image, 'Background image')]),
		]));

		body.appendChild(h('p', { class: 'lsl-label' }, 'Layers (text, buttons and images on top)'));
		var list = h('div', { class: 'lsl-layers' });
		slide.layers.forEach(function (layer, i) {
			var open = ui.layer === layer.uid;
			var name = layer.name || (layer.type === 'image' ? (layer.image.url ? layer.image.url.split('/').pop() : 'Image') : (layer.text || '').replace(/<[^>]+>/g, ' ').slice(0, 40)) || layer.type;
			list.appendChild(h('div', { class: 'lsl-layer' + (open ? ' is-open' : '') }, [
				h('div', { class: 'lsl-layer-head', onclick: function () { ui.layer = open ? '' : layer.uid; render(); selectInPreview(); } }, [
					h('span', { class: 'lsl-pill lsl-pill--accent' }, layer.type),
					h('span', { class: 'lsl-layer-name' }, name),
					layer.bp[ui.device].show ? null : h('span', { class: 'lsl-pill lsl-pill--off' }, 'Hidden on ' + (ui.device === 'm' ? 'phone' : 'desktop')),
					h('span', { class: 'lsl-slide-tools' }, [
						iconButton('Move back', 'arrow-up-alt2', function () { if (move(slide.layers, i, -1)) changed(true); }),
						iconButton('Move forward', 'arrow-down-alt2', function () { if (move(slide.layers, i, 1)) changed(true); }),
						iconButton('Duplicate', 'admin-page', function () { var c = clone(layer); c.uid = uid(); c.bp.d.y += 20; c.bp.m.y += 20; slide.layers.splice(i + 1, 0, c); ui.layer = c.uid; changed(true); }),
						iconButton('Delete', 'trash', function () { if (confirm(t.confirmLayer || 'Delete this layer?')) { slide.layers.splice(i, 1); changed(true); } }, 'lsl-danger'),
					]),
				]),
				open ? renderLayer(layer) : null,
			]));
		});
		body.appendChild(list);
		body.appendChild(h('div', { class: 'lsl-actions' }, ['text', 'button', 'image'].map(function (type) {
			return h('button', { type: 'button', class: 'button button-small', onclick: function () {
				var l = clone(C.defaults[type]); l.uid = uid(); slide.layers.push(l); ui.layer = l.uid; changed(true);
			} }, '+ ' + { text: 'Text', button: 'Button', image: 'Image' }[type]);
		})));
		return body;
	}

	function renderLayer(layer) {
		var bp = ui.device, p = layer.bp[bp], st = layer.style, a = layer.anim;
		var other = bp === 'm' ? 'd' : 'm';
		var box = h('div', { class: 'lsl-layer-body' });

		if (layer.type === 'image') {
			box.appendChild(mediaPicker(layer.image, 'Image', 'Leave the alt text empty for decorative images.'));
		} else {
			box.appendChild(field(layer.type === 'button' ? 'Button text' : 'Text', input(layer, 'text', { type: 'textarea', rows: 2 }), 'Plain text; <br> for a line break, <strong>, <em> allowed.'));
			if (layer.type === 'text') {
				box.appendChild(field('HTML tag', input(layer, 'tag', { type: 'select', options: [['h1', 'Heading 1'], ['h2', 'Heading 2'], ['h3', 'Heading 3'], ['h4', 'Heading 4'], ['p', 'Paragraph'], ['div', 'Plain block'], ['span', 'Inline']] }), 'Use one heading per slide for the main message; a page should have only one Heading 1.'));
			}
		}
		box.appendChild(row([field('Link', input(layer, 'link', { type: 'url', placeholder: 'https://' })), h('div', { class: 'lsl-field lsl-field--check' }, [check(layer, 'new_tab', 'Open in a new tab')])]));

		box.appendChild(h('div', { class: 'lsl-bp-head' }, [
			h('p', { class: 'lsl-label' }, 'Position on ' + (bp === 'm' ? 'phone' : 'desktop')),
			h('button', { type: 'button', class: 'button-link', onclick: function () { copyPosition(layer, other, bp); changed(true); } }, 'Copy from ' + (bp === 'm' ? 'desktop' : 'phone')),
		]));
		box.appendChild(h('div', { class: 'lsl-checks' }, [check(p, 'show', 'Show on ' + (bp === 'm' ? 'phone' : 'desktop'))]));
		box.appendChild(row([
			field('Horizontal', input(p, 'ha', { type: 'select', options: [['left', 'From left'], ['center', 'From centre'], ['right', 'From right']] })),
			field('X offset', input(p, 'x', { type: 'number', step: 1 })),
			field('Vertical', input(p, 'va', { type: 'select', options: [['top', 'From top'], ['middle', 'From middle'], ['bottom', 'From bottom']] })),
			field('Y offset', input(p, 'y', { type: 'number', step: 1 })),
		], 'lsl-row--4'));
		var sizeRow = [field('Width', input(p, 'w', { type: 'number', step: 1, min: 0 }), layer.type === 'image' ? 'Image width on the canvas.' : '0 = fit the text (no wrapping).')];
		if (layer.type !== 'image') {
			sizeRow.push(field('Font size', input(p, 'fs', { type: 'number', step: 1, min: 1 })));
			sizeRow.push(field('Line height', input(p, 'lh', { type: 'number', step: 0.05, min: 0.5 })));
			sizeRow.push(field('Text align', input(p, 'ta', { type: 'select', options: ['left', 'center', 'right'] })));
		}
		box.appendChild(row(sizeRow, 'lsl-row--4'));

		if (layer.type !== 'image') {
			box.appendChild(h('details', { class: 'lsl-more' }, [
				h('summary', {}, 'Style'),
				row([color(st, 'color', 'Text colour'), color(st, 'bg', 'Background')]),
				layer.type === 'button' ? row([color(st, 'hover_color', 'Hover text colour'), color(st, 'hover_bg', 'Hover background')]) : null,
				row([
					field('Font', input(st, 'font_family', { placeholder: 'Theme font' })),
					field('Weight', input(st, 'font_weight', { type: 'select', options: [['', 'Default'], '300', '400', '500', '600', '700', '800'] })),
					field('Letter spacing (px)', input(st, 'letter_spacing', { type: 'number', step: 0.5 })),
					field('Corner radius (px)', input(st, 'radius', { type: 'number', step: 1, min: 0 })),
				], 'lsl-row--4'),
				h('p', { class: 'lsl-label' }, 'Padding (px): top, right, bottom, left'),
				row([0, 1, 2, 3].map(function (n) { return field(['Top', 'Right', 'Bottom', 'Left'][n], input(st.padding, n, { type: 'number', min: 0 })); }), 'lsl-row--4'),
				h('div', { class: 'lsl-checks' }, [check(st, 'uppercase', 'UPPERCASE'), check(st, 'italic', 'Italic')]),
			]));
		}

		box.appendChild(h('details', { class: 'lsl-more' }, [
			h('summary', {}, 'Animation'),
			row([
				field('Entrance', input(a, 'in', { type: 'select', options: [['none', 'None'], ['fade', 'Fade in'], ['up', 'Rise up'], ['down', 'Drop down'], ['left', 'From the right'], ['right', 'From the left'], ['zoom', 'Zoom in']] })),
				field('Delay (ms)', input(a, 'delay', { type: 'number', step: 100, min: 0 })),
				field('Duration (ms)', input(a, 'duration', { type: 'number', step: 100, min: 0 })),
				field('Distance (px)', input(a, 'distance', { type: 'number', step: 5, min: 0 })),
			], 'lsl-row--4'),
			row([
				field('Loop', input(a, 'loop', { type: 'select', options: [['none', 'None'], ['float', 'Float']] })),
				field('Float X (px)', input(a, 'loop_x', { type: 'number', step: 1 })),
				field('Float Y (px)', input(a, 'loop_y', { type: 'number', step: 1 })),
				field('Float time (ms)', input(a, 'loop_speed', { type: 'number', step: 250, min: 200 })),
			], 'lsl-row--4'),
		]));
		return box;
	}

	/** Copy a layer's position from one device to the other, scaled to the other canvas. */
	function copyPosition(layer, from, to) {
		var cf = data.settings.canvas[from], ct = data.settings.canvas[to];
		var sx = ct.w / cf.w, sy = ct.h / cf.h, s = layer.bp[from];
		layer.bp[to] = Object.assign({}, s, {
			x: Math.round(s.x * sx), y: Math.round(s.y * sy), w: Math.round(s.w * sx), fs: Math.max(1, Math.round(s.fs * sx)),
		});
	}

	function render() {
		var scroll = window.scrollY;
		app.innerHTML = '';
		app.appendChild(renderSlider());
		app.appendChild(renderSlides());
		window.scrollTo(0, scroll);
		updateNav();
	}

	/* ----- Preview ----- */

	var previewTimer = null, previewSeq = 0;
	function schedulePreview() {
		clearTimeout(previewTimer);
		previewTimer = setTimeout(refreshPreview, 250);
	}
	function currentSlideIndex() {
		// The preview shows hidden slides too, so indexes line up with the editor.
		return Math.max(0, Math.min(ui.slide < 0 ? 0 : ui.slide, data.slides.length - 1));
	}
	function refreshPreview() {
		if (!frameReady) return;
		var seq = ++previewSeq;
		var preview = clone(data);
		preview.settings.autoplay = 0;
		post('lsl_render', { data: JSON.stringify(preview), slide: currentSlideIndex(), id: document.querySelector('#lsl-form [name=id]').value }).then(function (r) {
			if (seq !== previewSeq || !r.success) return;
			frame.contentWindow.postMessage({ type: 'lsl-html', html: r.data.html, design: data.settings.canvas[ui.device].w, selected: ui.layer }, origin);
		});
	}
	function selectInPreview() {
		if (frameReady) frame.contentWindow.postMessage({ type: 'lsl-select', uid: ui.layer }, origin);
	}
	function sizeFrame() {
		var desktop = ui.device === 'd';
		var stageW = stage.clientWidth;
		var w = desktop ? Math.max(data.settings.breakpoint + 1, data.settings.canvas.d.w) : 390;
		var scale = Math.min(1, stageW / w);
		frame.style.width = w + 'px';
		frame.style.height = (frameHeight || 300) + 'px';
		frame.style.transform = 'scale(' + scale + ')';
		stage.style.height = Math.ceil((frameHeight || 300) * scale) + 'px';
		stage.classList.toggle('is-phone', !desktop);
	}
	function updateNav() {
		var label = document.querySelector('[data-lsl-pv-label]');
		if (label) label.textContent = data.slides.length ? (currentSlideIndex() + 1) + ' / ' + data.slides.length : '';
	}

	window.addEventListener('message', function (e) {
		if (e.origin !== origin || !e.data || typeof e.data !== 'object') return;
		var m = e.data;
		if (m.type === 'lsl-ready') { frameReady = true; sizeFrame(); refreshPreview(); }
		if (m.type === 'lsl-height') { frameHeight = m.height; sizeFrame(); }
		if (m.type === 'lsl-select') { ui.layer = m.uid; render(); var el = app.querySelector('.lsl-layer.is-open'); if (el) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
		if (m.type === 'lsl-move') {
			var slide = data.slides[currentSlideIndex()];
			var layer = slide && slide.layers.filter(function (l) { return l.uid === m.uid; })[0];
			if (!layer) return;
			var p = layer.bp[ui.device];
			p.x = Math.round(p.x + (p.ha === 'right' ? -m.dx : m.dx));
			p.y = Math.round(p.y + (p.va === 'bottom' ? -m.dy : m.dy));
			ui.layer = layer.uid;
			changed(true);
		}
	});

	document.querySelector('[data-lsl-device]').addEventListener('change', function (e) {
		ui.device = e.target.value;
		render();
		sizeFrame();
		refreshPreview();
	});
	document.querySelector('[data-lsl-pv-prev]').addEventListener('click', function () { ui.slide = (currentSlideIndex() - 1 + data.slides.length) % data.slides.length; ui.layer = ''; render(); refreshPreview(); });
	document.querySelector('[data-lsl-pv-next]').addEventListener('click', function () { ui.slide = (currentSlideIndex() + 1) % data.slides.length; ui.layer = ''; render(); refreshPreview(); });
	document.querySelector('[data-lsl-pv-replay]').addEventListener('click', refreshPreview);
	window.addEventListener('resize', sizeFrame);

	document.getElementById('lsl-form').addEventListener('submit', function () {
		document.getElementById('lsl-data').value = JSON.stringify(data);
		dirty = false;
	});
	window.addEventListener('beforeunload', function (e) {
		if (dirty) { e.preventDefault(); e.returnValue = t.unsaved || ''; }
	});

	render();
	frame.src = C.preview;
	sizeFrame();
})();
