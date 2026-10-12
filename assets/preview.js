/* Lean Slider — editor preview page: shows HTML sent by the editor, lets layers be dragged and selected. */
(function () {
	'use strict';
	var box = document.getElementById('lsl-pv');
	var parentOrigin = (window.LSLPreview && LSLPreview.origin) ? new URL(LSLPreview.origin).origin : location.origin;
	var design = 0; // design canvas width for the current device
	var selected = '';

	function send(msg) { window.parent.postMessage(msg, parentOrigin); }

	function height() { send({ type: 'lsl-height', height: Math.ceil(box.getBoundingClientRect().height) }); }

	function mark() {
		box.querySelectorAll('.lsl-l').forEach(function (l) { l.classList.toggle('is-selected', l.getAttribute('data-uid') === selected); });
	}

	window.addEventListener('message', function (e) {
		if (e.origin !== parentOrigin || !e.data || typeof e.data !== 'object') return;
		if (e.data.type === 'lsl-html') {
			design = e.data.design || 0;
			selected = e.data.selected || '';
			box.innerHTML = e.data.html;
			var root = box.querySelector('.lsl');
			if (root) {
				root.classList.add('lsl-ready');
				if (window.LeanSlider) LeanSlider.init(box);
			}
			mark();
			requestAnimationFrame(height);
			box.querySelectorAll('img').forEach(function (img) { if (!img.complete) img.addEventListener('load', height, { once: true }); });
		}
		if (e.data.type === 'lsl-select') { selected = e.data.uid || ''; mark(); }
	});
	window.addEventListener('resize', height);

	// Links don't navigate inside the preview.
	document.addEventListener('click', function (e) {
		if (e.target.closest('#lsl-pv a')) e.preventDefault();
	}, true);

	var drag = null;
	box.addEventListener('pointerdown', function (e) {
		var layer = e.target.closest('.lsl-l');
		if (!layer || e.button > 0) return;
		e.preventDefault();
		var canvas = layer.closest('.lsl-canvas');
		drag = { el: layer, x: e.clientX, y: e.clientY, scale: canvas && design ? canvas.getBoundingClientRect().width / design : 1, moved: false };
		layer.setPointerCapture(e.pointerId);
		layer.classList.add('is-dragging');
	});
	box.addEventListener('pointermove', function (e) {
		if (!drag) return;
		var dx = e.clientX - drag.x, dy = e.clientY - drag.y;
		if (Math.abs(dx) + Math.abs(dy) > 3) drag.moved = true;
		drag.el.style.translate = dx + 'px ' + dy + 'px';
	});
	box.addEventListener('pointerup', function (e) {
		if (!drag) return;
		var d = drag;
		drag = null;
		d.el.classList.remove('is-dragging');
		var uid = d.el.getAttribute('data-uid');
		if (!d.moved) {
			d.el.style.translate = '';
			selected = uid; mark();
			send({ type: 'lsl-select', uid: uid });
			return;
		}
		send({ type: 'lsl-move', uid: uid, dx: (e.clientX - d.x) / d.scale, dy: (e.clientY - d.y) / d.scale });
	});

	send({ type: 'lsl-ready' });
})();
