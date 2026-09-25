/**
 * Portal slide editor: repeatable rows, the question builder showing only the fields for
 * the chosen type, and category suggestions for sorting questions.
 * The form works without this script — every row is already on the page.
 */
(function () {
	'use strict';

	var editor = document.querySelector('[data-wic-slide-editor]');
	if (!editor) { return; }

	// Add a row from its <template>, renumbering the __i__ placeholder.
	editor.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-wic-add-row]');
		if (!btn) { return; }
		var key = btn.getAttribute('data-wic-add-row');
		var list = editor.querySelector('[data-wic-rows="' + key + '"]');
		var tpl = editor.querySelector('[data-wic-row-template="' + key + '"]');
		if (!list || !tpl) { return; }
		// Row keys only need to be unique; the server reads rows in order and ignores keys.
		var uid = 'n' + Date.now().toString(36) + Math.floor(Math.random() * 1000);
		var holder = document.createElement('div');
		holder.innerHTML = tpl.innerHTML.replace(/__i__/g, uid);
		var row = holder.firstElementChild;
		list.appendChild(row);
		var first = row.querySelector('input, textarea');
		if (first) { first.focus(); }
	});

	// Show only the fields for the chosen question type.
	var qtype = editor.querySelector('[data-wic-qtype]');
	var layout = editor.querySelector('[data-wic-layout]');
	var panel = editor.querySelector('[data-wic-question-panel]');
	function showType() {
		var t = qtype ? qtype.value : 'mc';
		Array.prototype.forEach.call(editor.querySelectorAll('[data-qshow]'), function (el) {
			el.hidden = el.getAttribute('data-qshow').split(' ').indexOf(t) === -1;
		});
		if (panel && layout) {
			panel.classList.toggle('is-muted', layout.value !== 'question');
			var note = panel.querySelector('.wic-q-note');
			if (!note) {
				note = document.createElement('p');
				note.className = 'wic-help wic-q-note';
				panel.insertBefore(note, panel.children[1] || null);
			}
			note.textContent = layout.value === 'question' ? '' : 'The question is only used when the layout is set to Question.';
		}
	}
	if (qtype) { qtype.addEventListener('change', showType); }
	if (layout) { layout.addEventListener('change', showType); }
	showType();

	// Category suggestions follow the categories box as it is typed.
	var cats = editor.querySelector('[data-wic-categories]');
	var list = document.querySelector('[data-wic-category-list]');
	if (cats && list) {
		cats.addEventListener('input', function () {
			list.innerHTML = '';
			cats.value.split(/\r?\n/).forEach(function (c) {
				c = c.trim();
				if (!c) { return; }
				var o = document.createElement('option');
				o.value = c;
				list.appendChild(o);
			});
		});
	}
})();
