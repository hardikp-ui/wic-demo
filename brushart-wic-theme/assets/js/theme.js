/**
 * Mobile navigation toggle. Without this script the menu simply stays open.
 */
(function () {
	'use strict';

	var toggle = document.querySelector('.ba-nav-toggle');
	var nav = document.getElementById('ba-nav');
	if (!toggle || !nav) { return; }

	function setOpen(open) {
		toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		nav.classList.toggle('is-open', open);
	}

	toggle.addEventListener('click', function () {
		var open = toggle.getAttribute('aria-expanded') !== 'true';
		setOpen(open);
		if (open) {
			var first = nav.querySelector('a');
			if (first) { first.focus(); }
		}
	});

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
			setOpen(false);
			toggle.focus();
		}
	});

	// Returning to a wide screen leaves the menu in its normal, always-visible state.
	var wide = window.matchMedia('(min-width: 960px)');
	var onChange = function () { if (wide.matches) { setOpen(false); } };
	if (wide.addEventListener) { wide.addEventListener('change', onChange); } else if (wide.addListener) { wide.addListener(onChange); }
})();
