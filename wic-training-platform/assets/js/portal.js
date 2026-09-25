/**
 * Portal enhancements: status filters, sortable tables, confirmations.
 * Every page works without this script; it only makes it quicker to use.
 */
(function () {
	'use strict';

	// Multi-select status filters. Buttons announce their state; an empty result says so.
	document.querySelectorAll('[data-wic-filters]').forEach(function (group) {
		var target = document.querySelector('[data-wic-filter-target]');
		var empty = document.querySelector('[data-wic-empty]');
		var status = document.querySelector('[data-wic-filter-status]');
		if (!target) { return; }
		function apply() {
			var on = [];
			group.querySelectorAll('[data-filter]').forEach(function (b) {
				if (b.getAttribute('aria-pressed') === 'true') { on.push(b.getAttribute('data-filter')); }
			});
			var shown = 0;
			target.querySelectorAll('[data-status]').forEach(function (card) {
				var st = card.getAttribute('data-status');
				if (st === 'expired') { st = 'overdue'; }
				var visible = on.indexOf(st) !== -1;
				card.hidden = !visible;
				if (visible) { shown++; }
			});
			if (empty) { empty.hidden = shown !== 0; }
			if (status) { status.textContent = shown + (shown === 1 ? ' course shown' : ' courses shown'); }
		}
		group.addEventListener('click', function (e) {
			var b = e.target.closest('[data-filter]');
			if (!b) { return; }
			b.setAttribute('aria-pressed', b.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
			apply();
		});
		apply();
	});

	// Sortable tables: click a column heading.
	document.querySelectorAll('[data-wic-sortable]').forEach(function (table) {
		var heads = table.querySelectorAll('thead th');
		heads.forEach(function (th, col) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'wic-sort';
			while (th.firstChild) { btn.appendChild(th.firstChild); }
			th.appendChild(btn);
			btn.addEventListener('click', function () {
				var dir = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
				heads.forEach(function (x) { x.removeAttribute('aria-sort'); });
				th.setAttribute('aria-sort', dir);
				var body = table.tBodies[0];
				var rows = Array.prototype.slice.call(body.rows);
				rows.sort(function (a, b) {
					var av = key(a.cells[col]), bv = key(b.cells[col]);
					var r = (typeof av === 'number' && typeof bv === 'number') ? av - bv : String(av).localeCompare(String(bv));
					return dir === 'ascending' ? r : -r;
				});
				rows.forEach(function (r) { body.appendChild(r); });
			});
		});
		function key(cell) {
			if (!cell) { return ''; }
			var v = cell.hasAttribute('data-sort') ? cell.getAttribute('data-sort') : cell.textContent.trim();
			return v !== '' && !isNaN(v) ? parseFloat(v) : v.toLowerCase();
		}
	});

	document.addEventListener('click', function (e) {
		var b = e.target.closest('[data-wic-confirm]');
		if (b && !window.confirm(b.getAttribute('data-wic-confirm'))) { e.preventDefault(); }
	});

	// Sidebar drawer on narrow screens: opens from the Menu button, closes on Escape,
	// the close button or the scrim, and returns focus to the button.
	document.querySelectorAll('[data-wic-shell]').forEach(function (shell) {
		var side = shell.querySelector('.wic-side');
		var openBtn = shell.querySelector('[data-wic-side-open]');
		var scrim = shell.querySelector('.wic-side__scrim');
		if (!side || !openBtn) { return; }
		shell.classList.add('has-js');
		var narrow = window.matchMedia('(max-width: 960px)');
		function setOpen(open) {
			shell.classList.toggle('is-open', open);
			openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
			if (scrim) { scrim.hidden = !open; }
			if (narrow.matches) {
				if (open) { side.setAttribute('role', 'dialog'); side.setAttribute('aria-modal', 'true'); } else { side.removeAttribute('role'); side.removeAttribute('aria-modal'); }
			}
			if (open) {
				var target = side.querySelector('[aria-current="page"]') || side.querySelector('a, button');
				if (target) { target.focus(); }
			} else if (narrow.matches) {
				openBtn.focus();
			}
		}
		openBtn.addEventListener('click', function () { setOpen(true); });
		shell.querySelectorAll('[data-wic-side-close]').forEach(function (c) {
			c.addEventListener('click', function () { setOpen(false); });
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && shell.classList.contains('is-open')) { setOpen(false); }
		});
		var onChange = function () { if (!narrow.matches && shell.classList.contains('is-open')) { setOpen(false); } };
		if (narrow.addEventListener) { narrow.addEventListener('change', onChange); } else if (narrow.addListener) { narrow.addListener(onChange); }
		// Keep the current item in view in a long sidebar.
		var current = side.querySelector('[aria-current="page"]');
		var nav = side.querySelector('.wic-side__nav');
		if (current && nav && current.offsetTop > nav.clientHeight - 40) { nav.scrollTop = current.offsetTop - nav.clientHeight / 2; }
	});
})();
