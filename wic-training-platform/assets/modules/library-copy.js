/**
 * Copy a ready-written message to the clipboard, and say so to screen readers.
 * Without this script the text is still there to select by hand.
 */
(function () {
	'use strict';

	var status = document.querySelector('[data-wic-copy-status]');

	function say(msg) {
		if (!status) { return; }
		status.textContent = '';
		window.setTimeout(function () { status.textContent = msg; }, 30);
	}

	function fallback(text) {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.setAttribute('readonly', '');
		ta.style.position = 'absolute';
		ta.style.left = '-9999px';
		document.body.appendChild(ta);
		ta.select();
		var ok = false;
		try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
		document.body.removeChild(ta);
		return ok;
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('[data-wic-copy]') : null;
		if (!btn) { return; }
		var src = document.getElementById(btn.getAttribute('data-wic-copy'));
		if (!src) { return; }
		var text = src.innerText || src.textContent || '';
		var label = btn.firstChild;
		function done(ok) {
			if (ok) {
				if (label && label.nodeType === 3) {
					var was = label.nodeValue;
					label.nodeValue = 'Copied';
					window.setTimeout(function () { label.nodeValue = was; }, 2000);
				}
				say('Message copied to the clipboard.');
			} else {
				say('Could not copy automatically. Select the text and copy it by hand.');
			}
		}
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(fallback(text)); });
		} else {
			done(fallback(text));
		}
	});
})();
