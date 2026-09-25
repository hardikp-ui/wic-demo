/**
 * Look-and-feel preview: re-renders a sample of the portal from the form's values as they
 * change, and checks the contrast of white text on the primary colour and of body text on
 * the background (WCAG AA needs 4.5:1). Nothing is saved until the form is submitted.
 */
(function () {
	'use strict';

	function lum(hex) {
		var m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(hex || '');
		if (!m) { return null; }
		var h = m[1].length === 3 ? m[1].replace(/./g, '$&$&') : m[1];
		var c = [0, 2, 4].map(function (i) {
			var v = parseInt(h.substr(i, 2), 16) / 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
		});
		return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
	}

	function ratio(a, b) {
		var la = lum(a), lb = lum(b);
		if (la === null || lb === null) { return null; }
		var hi = Math.max(la, lb), lo = Math.min(la, lb);
		return (hi + 0.05) / (lo + 0.05);
	}

	document.querySelectorAll('[data-wic-brand-form]').forEach(function (form) {
		var box = form.querySelector('[data-wic-brand-preview]');
		if (!box) { return; }
		var frame = box.querySelector('[data-preview-frame]');
		var nameEl = box.querySelector('[data-preview-name]');
		var logo = box.querySelector('[data-preview-logo]');
		var out = box.querySelector('[data-preview-contrast]');

		function val(key) {
			var f = form.querySelector('[data-brand="' + key + '"]');
			return f ? f.value.trim() : '';
		}

		function update() {
			var p = val('color_primary') || '#1f5f8b';
			var a = val('color_accent') || '#e0a100';
			var ink = val('color_ink') || '#1d2430';
			var bg = val('color_surface') || '#ffffff';
			frame.style.setProperty('--pv-primary', p);
			frame.style.setProperty('--pv-accent', a);
			frame.style.setProperty('--pv-ink', ink);
			frame.style.setProperty('--pv-surface', bg);
			if (val('name')) { nameEl.textContent = val('name'); }
			var url = val('logo_url');
			if (url && /^https?:\/\//i.test(url)) {
				logo.src = url;
				logo.alt = val('name') || '';
				logo.hidden = false;
			} else {
				logo.hidden = true;
				logo.removeAttribute('src');
			}
			var r1 = ratio('#ffffff', p);
			var r2 = ratio(ink, bg);
			var r3 = ratio(p, bg);
			var parts = [];
			function say(label, r) {
				if (r === null) { return; }
				parts.push(label + ' ' + r.toFixed(1) + ':1 — ' + (r >= 4.5 ? 'passes AA' : 'FAILS AA (needs 4.5:1)'));
			}
			say('White text on the primary colour:', r1);
			say('Text on the background:', r2);
			say('Links (primary colour) on the background:', r3);
			out.textContent = parts.join(' · ');
			var fail = [r1, r2, r3].some(function (r) { return r !== null && r < 4.5; });
			out.classList.toggle('is-fail', fail);
		}

		form.addEventListener('input', update);
		form.addEventListener('change', update);
		update();
	});
})();
