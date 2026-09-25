/**
 * Text size control (#149). The choice is a per-viewer convenience kept in localStorage;
 * the page works at the standard size without it.
 */
(function () {
	'use strict';

	var KEY = 'wic-text-size';
	var SIZES = ['sm', 'md', 'lg', 'xl'];

	function get() {
		try { return window.localStorage.getItem(KEY); } catch (e) { return null; }
	}
	function set(v) {
		try { window.localStorage.setItem(KEY, v); } catch (e) { /* storage unavailable */ }
	}
	function apply(size) {
		if (SIZES.indexOf(size) === -1) { size = 'md'; }
		document.documentElement.setAttribute('data-wic-text', size);
		document.querySelectorAll('[data-wic-textsize] [data-size]').forEach(function (b) {
			b.setAttribute('aria-pressed', b.getAttribute('data-size') === size ? 'true' : 'false');
		});
	}

	apply(get() || 'md');

	document.addEventListener('click', function (e) {
		var b = e.target.closest ? e.target.closest('[data-wic-textsize] [data-size]') : null;
		if (!b) { return; }
		var size = b.getAttribute('data-size');
		set(size);
		apply(size);
	});
})();
