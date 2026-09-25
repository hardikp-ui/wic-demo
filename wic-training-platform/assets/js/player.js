/**
 * WIC lesson player — one component driven by a slide list.
 *
 * Slides are addressed by ID (deep links survive edits). Layers are state inside a slide.
 * Answers are scored on the server; the browser never sees which option is correct.
 * Language is a field on the slide: switching changes text, narration and transcript together.
 * Offline, position and answer calls are queued and replayed in order — still scored on the server.
 */
(function () {
	'use strict';

	var D = window.WIC_PLAYER;
	if (!D) { return; }

	/* ------------------------------------------------------------ state */

	var slides = [];
	D.modules.forEach(function (m, mi) {
		m.slides.forEach(function (s) { slides.push({ s: s, m: m, mi: mi }); });
	});
	if (!slides.length) { return; }
	var seen = {};
	var seenAtLoad = {};
	D.seen.forEach(function (id) { seen[id] = true; seenAtLoad[id] = true; });
	var qstate = {};
	var drafts = {};
	slides.forEach(function (x) {
		if (x.s.state) { qstate[x.s.id] = { used: x.s.state.used, correct: x.s.state.correct }; }
		if (x.s.draft !== null && x.s.draft !== undefined) { drafts[x.s.id] = x.s.draft; }
	});
	var notes = D.notes || {};
	var index = Math.max(0, indexOf(D.start));
	var lastActivity = Date.now();
	var pingTimer = null;
	var finished = false;
	var lang = D.lang || D.defaultLang || '';
	var pending = {};         // slide id => answer queued while offline
	var synced = {};          // slide id => server result for an answer replayed after reconnecting
	var unlocked = {};        // locked navigation: slide id => requirements met this session
	var mediaDone = {};       // slide id => narration or video has ended
	var layersOpen = {};      // slide id => { layer id: true }
	var searchQuery = '';

	var $ = function (id) { return document.getElementById(id); };
	var el = {
		slide: $('wicp-slide'),
		outline: $('wicp-outline'),
		transcript: $('wicp-transcript'),
		prev: $('wicp-prev'),
		next: $('wicp-next'),
		pos: $('wicp-pos'),
		bar: $('wicp-bar'),
		pct: $('wicp-progress-text'),
		remaining: $('wicp-remaining'),
		audioWrap: $('wicp-audio-wrap'),
		audio: $('wicp-audio'),
		speed: $('wicp-speed'),
		auto: $('wicp-auto'),
		announce: $('wicp-announce'),
		side: $('wicp-side'),
		toggleSide: $('wicp-toggle-side'),
		fullscreen: $('wicp-fullscreen'),
		lightbox: $('wicp-lightbox'),
		lightboxImg: $('wicp-lightbox-img'),
		lightboxClose: $('wicp-lightbox-close'),
		offline: $('wicp-offline'),
		lang: $('wicp-lang'),
		locked: $('wicp-locked'),
		ttsWrap: $('wicp-tts-wrap'),
		tts: $('wicp-tts'),
		bookmark: $('wicp-bookmark'),
		note: $('wicp-note'),
		noteStatus: $('wicp-note-status'),
		noteList: $('wicp-note-list'),
		search: $('wicp-search'),
		searchQ: $('wicp-search-q'),
		searchResults: $('wicp-search-results'),
		resources: $('wicp-resources'),
		printModule: $('wicp-print-module')
	};

	/* ---------------------------------------------------------- helpers */

	function indexOf(id) {
		for (var i = 0; i < slides.length; i++) { if (slides[i].s.id === id) { return i; } }
		return -1;
	}

	function h(tag, attrs, children) {
		var n = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				if (k === 'text') { n.textContent = attrs[k]; }
				else if (k === 'html') { n.innerHTML = attrs[k]; }
				else if (k.indexOf('on') === 0) { n.addEventListener(k.slice(2), attrs[k]); }
				else if (attrs[k] === true) { n.setAttribute(k, ''); }
				else if (attrs[k] !== false && attrs[k] != null) { n.setAttribute(k, attrs[k]); }
			});
		}
		(children || []).forEach(function (c) { if (c) { n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
		return n;
	}

	function announce(msg) {
		el.announce.textContent = '';
		window.setTimeout(function () { el.announce.textContent = msg; }, 30);
	}

	function shuffle(arr) {
		var a = arr.slice();
		for (var i = a.length - 1; i > 0; i--) {
			var j = Math.floor(Math.random() * (i + 1));
			var t = a[i]; a[i] = a[j]; a[j] = t;
		}
		return a;
	}

	function range(n) { var a = []; for (var i = 0; i < n; i++) { a.push(i); } return a; }

	function store(key, value) {
		try {
			if (value === undefined) { return window.localStorage.getItem('wicp-' + key); }
			window.localStorage.setItem('wicp-' + key, value);
		} catch (e) { /* storage unavailable: fine, it is only a convenience */ }
		return null;
	}

	function debounce(fn, ms) {
		var t = null;
		return function () {
			var args = arguments;
			window.clearTimeout(t);
			t = window.setTimeout(function () { fn.apply(null, args); }, ms);
		};
	}

	/** Accent- and case-insensitive text, so "nutricion" finds "Nutrición". */
	function fold(text) {
		text = String(text || '');
		if (text.normalize) { text = text.normalize('NFD').replace(/[̀-ͯ]/g, ''); }
		return text.toLowerCase();
	}

	/** What a slide shows in the chosen language, falling back to the original wording. */
	function view(s) {
		var t = lang && lang !== D.defaultLang && s.i18n ? s.i18n[lang] : null;
		return {
			title: t && t.title ? t.title : s.title,
			html: t && t.html ? t.html : s.html,
			script: t && t.script ? t.script : s.script,
			audio: t && t.audio ? t.audio : s.audio,
			altAudio: t && t.altAudio ? t.altAudio : '',
			question: t && t.question ? t.question : s.question,
			translated: !!t,
			needsReview: !!(t && t.needsReview),
			wantsTranslation: !!(lang && lang !== D.defaultLang)
		};
	}

	function langLabel(code) {
		var out = code;
		(D.languages || []).forEach(function (l) { if (l.code === code) { out = l.label; } });
		return out;
	}

	/* ------------------------------------------------ server + offline queue */

	var QUEUEABLE = { '/position': 1, '/answer': 1, '/draft': 1, '/note': 1, '/pref': 1 };
	var QKEY = 'queue-' + D.courseId;
	var memQueue = null;
	var flushing = false;

	function readQueue() {
		if (memQueue) { return memQueue.slice(); }
		try { return JSON.parse(store(QKEY) || '[]') || []; } catch (e) { return []; }
	}
	function writeQueue(q) {
		try {
			window.localStorage.setItem('wicp-' + QKEY, JSON.stringify(q));
			memQueue = null;
		} catch (e) { memQueue = q.slice(); }
	}

	function rawApi(path, body) {
		return fetch(D.rest + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': D.nonce },
			body: JSON.stringify(body)
		}).then(function (r) {
			return r.json().then(function (j) { return { ok: r.ok, data: j }; }, function () { return { ok: r.ok, data: {} }; });
		});
	}

	function enqueue(path, body) {
		var q = readQueue();
		var last = q[q.length - 1];
		if (path === '/position' && last && last.path === '/position' && last.body.slide === body.slide) {
			return { ok: false, queued: true, data: {} };
		}
		if (path === '/draft' || path === '/note' || path === '/pref') {
			q = q.filter(function (x) { return !(x.path === path && x.body.slide === body.slide); });
		}
		q.push({ path: path, body: body });
		writeQueue(q);
		setOffline(true);
		return { ok: false, queued: true, data: {} };
	}

	function api(path, body) {
		if (QUEUEABLE[path] && (navigator.onLine === false || readQueue().length)) {
			// Keep order: while anything is waiting, new calls join the back of the queue.
			var r = enqueue(path, body);
			flush();
			return Promise.resolve(r);
		}
		return rawApi(path, body).catch(function (err) {
			if (QUEUEABLE[path]) { return enqueue(path, body); }
			throw err;
		});
	}

	function flush() {
		if (flushing || navigator.onLine === false) { return; }
		if (!readQueue().length) { setOffline(false); return; }
		flushing = true;
		(function next() {
			var q = readQueue();
			if (!q.length) {
				flushing = false;
				setOffline(false);
				announce('Back online. Your progress has been saved.');
				return;
			}
			var item = q[0];
			rawApi(item.path, item.body).then(function (r) {
				var rest = readQueue();
				rest.shift();
				writeQueue(rest);
				if (item.path === '/answer') { onAnswerSynced(item.body.slide, r); }
				if (item.path === '/position' && r.ok && r.data && typeof r.data.progress === 'number') { setProgress(r.data.progress); }
				next();
			}).catch(function () {
				flushing = false;
				setOffline(true);
			});
		})();
	}

	function setOffline(on) {
		if (!el.offline) { return; }
		var waiting = readQueue().length;
		if (on || waiting) {
			el.offline.hidden = false;
			el.offline.textContent = navigator.onLine === false || on ? 'Offline — progress will sync' : 'Syncing…';
		} else {
			el.offline.hidden = true;
			el.offline.textContent = '';
		}
	}

	readQueue().forEach(function (item) { if (item.path === '/answer') { pending[item.body.slide] = true; } });
	window.addEventListener('online', flush);
	window.addEventListener('offline', function () { setOffline(true); });

	/* ------------------------------------------------------- navigation */

	function maxSeenIndex() {
		var max = -1;
		slides.forEach(function (x, i) { if (seen[x.s.id]) { max = i; } });
		return max;
	}

	function isDone(i) {
		var id = slides[i].s.id;
		return !!(seenAtLoad[id] || unlocked[id]);
	}

	function firstUndone() {
		for (var i = 0; i < slides.length; i++) { if (!isDone(i)) { return i; } }
		return slides.length;
	}

	function canGo(i) {
		if (i < 0 || i >= slides.length) { return false; }
		if (D.navMode === 'locked') { return i <= firstUndone(); }
		return D.navMode !== 'linear' || i <= maxSeenIndex() + 1 || seen[slides[i].s.id];
	}

	function go(i) {
		if (!canGo(i)) {
			announce(D.navMode === 'locked'
				? 'Finish this slide first — listen to the narration and open every reveal.'
				: 'Finish the earlier slides first — this course is set to be worked through in order.');
			return;
		}
		stopSpeech();
		finished = false;
		index = i;
		render(true);
	}

	function ping() {
		window.clearTimeout(pingTimer);
		var id = slides[index].s.id;
		pingTimer = window.setTimeout(function () {
			api('/position', { course: D.courseId, slide: id }).then(function (r) {
				if (r.ok && r.data && typeof r.data.progress === 'number') { setProgress(r.data.progress); }
			}).catch(function () { /* next ping will catch up */ });
		}, 400);
	}

	/** Locked navigation: the current slide is done once its narration or video ended and every reveal was opened. */
	function checkUnlock() {
		if (D.navMode !== 'locked') { return; }
		var s = slides[index].s;
		var v = view(s);
		var needMedia = !!(v.audio || (s.video && s.video.url));
		var opened = layersOpen[s.id] || {};
		var allLayers = (s.layers || []).every(function (l) { return opened[l.id]; });
		var was = !!unlocked[s.id];
		if ((!needMedia || mediaDone[s.id]) && allLayers) { unlocked[s.id] = true; }
		updateControls();
		updateOutline();
		if (!was && unlocked[s.id] && !seenAtLoad[s.id]) { announce('You can move on to the next slide.'); }
	}

	function updateControls() {
		var last = index === slides.length - 1;
		var blocked = D.navMode === 'locked' && !isDone(index);
		el.prev.disabled = index === 0 && !finished;
		el.next.disabled = blocked;
		if (el.locked) {
			el.locked.hidden = !blocked;
			el.locked.textContent = blocked ? 'Next is available once the narration has finished and every reveal on this slide has been opened.' : '';
		}
		el.next.innerHTML = '';
		el.next.appendChild(document.createTextNode(last ? 'Finish ' : 'Next '));
		el.next.appendChild(h('span', { 'aria-hidden': 'true', text: last ? '✓' : '→' }));
	}

	/* ----------------------------------------------------------- render */

	function setProgress(pct) {
		pct = Math.max(0, Math.min(100, pct));
		el.bar.setAttribute('aria-valuenow', pct);
		el.bar.firstElementChild.style.width = pct + '%';
		el.pct.textContent = pct + '%';
	}

	function localProgress() {
		var n = 0;
		slides.forEach(function (x) { if (seen[x.s.id]) { n++; } });
		return Math.floor(n / slides.length * 100);
	}

	function remainingText() {
		var secs = 0;
		for (var i = index + 1; i < slides.length; i++) {
			if (!seen[slides[i].s.id]) { secs += slides[i].s.seconds; }
		}
		if (secs <= 0) { return ''; }
		var mins = Math.max(1, Math.round(secs / 60));
		return 'about ' + mins + ' min left';
	}

	function buildOutline() {
		el.outline.innerHTML = '';
		D.modules.forEach(function (m, mi) {
			var list = h('ol', { 'class': 'wicp-outline__list' });
			m.slides.forEach(function (s) {
				var i = indexOf(s.id);
				list.appendChild(h('li', null, [
					h('button', {
						type: 'button',
						'class': 'wicp-outline__item',
						'data-i': i,
						onclick: function () { go(i); }
					}, [
						h('span', { 'class': 'wicp-tick', 'aria-hidden': 'true' }),
						h('span', { 'class': 'wicp-outline__title', text: view(s).title }),
						h('span', { 'class': 'wicp-mark', 'data-mark': '1', 'aria-hidden': 'true' }),
						h('span', { 'class': 'wicp-sr', 'data-seen-label': '1' })
					])
				]));
			});
			var summary = h('summary', null, [
				h('span', { text: m.title }),
				m.pretest ? h('span', { 'class': 'wicp-tag', text: 'Pre-test' }) : null,
				m.assessment ? h('span', { 'class': 'wicp-tag', text: 'Graded' }) : null,
				h('span', { 'class': 'wicp-outline__count', 'data-mod-count': mi }),
				h('span', { 'class': 'wicp-mbar', 'aria-hidden': 'true' }, [h('span', { 'data-mod-bar': mi })])
			]);
			el.outline.appendChild(h('details', { 'class': 'wicp-outline__mod', 'data-mod': mi, open: true }, [summary, list]));
		});
	}

	function updateOutline() {
		var buttons = el.outline.querySelectorAll('.wicp-outline__item');
		Array.prototype.forEach.call(buttons, function (b) {
			var i = parseInt(b.getAttribute('data-i'), 10);
			var s = slides[i].s;
			var id = s.id;
			b.classList.toggle('is-seen', !!seen[id]);
			if (i === index) { b.setAttribute('aria-current', 'step'); } else { b.removeAttribute('aria-current'); }
			b.disabled = !canGo(i);
			b.querySelector('.wicp-outline__title').textContent = view(s).title;
			var n = notes[id];
			b.querySelector('[data-mark]').textContent = n && n.bookmark ? '★' : '';
			b.querySelector('[data-seen-label]').textContent = (seen[id] ? ' (seen)' : '') + (n && n.bookmark ? ' (bookmarked)' : '');
		});
		D.modules.forEach(function (m, mi) {
			var done = m.slides.filter(function (s) { return seen[s.id]; }).length;
			var counter = el.outline.querySelector('[data-mod-count="' + mi + '"]');
			if (counter) { counter.textContent = done + '/' + m.slides.length; }
			var bar = el.outline.querySelector('[data-mod-bar="' + mi + '"]');
			if (bar) { bar.style.width = (m.slides.length ? Math.round(done / m.slides.length * 100) : 0) + '%'; }
			var det = el.outline.querySelector('[data-mod="' + mi + '"]');
			// Collapse finished modules so long courses stay readable; keep the current one open.
			if (det && mi !== slides[index].mi && done === m.slides.length && !det.dataset.touched) { det.open = false; }
			if (det && mi === slides[index].mi) { det.open = true; }
		});
		var current = el.outline.querySelector('[aria-current]');
		if (current && current.scrollIntoView) { current.scrollIntoView({ block: 'nearest' }); }
	}

	function renderLayers(container, s) {
		var layers = s.layers;
		if (!layers || !layers.length) { return; }
		var wrap = h('div', { 'class': 'wicp-layers' });
		var buttons = h('div', { 'class': 'wicp-layers__buttons' });
		var panels = h('div', { 'class': 'wicp-layers__panels' });
		layersOpen[s.id] = layersOpen[s.id] || {};
		layers.forEach(function (l) {
			var pid = 'wicp-layer-' + l.id;
			var panel = h('div', { 'class': 'wicp-layer', id: pid, hidden: true, html: l.html });
			var btn = h('button', {
				type: 'button', 'class': 'wicp-layer__btn' + (layersOpen[s.id][l.id] ? ' is-visited' : ''), 'aria-expanded': 'false', 'aria-controls': pid,
				onclick: function () {
					var open = btn.getAttribute('aria-expanded') === 'true';
					btn.setAttribute('aria-expanded', open ? 'false' : 'true');
					panel.hidden = open;
					btn.classList.add('is-visited');
					layersOpen[s.id][l.id] = true;
					checkUnlock();
				}
			}, [l.label]);
			buttons.appendChild(btn);
			panels.appendChild(panel);
		});
		wrap.appendChild(buttons);
		wrap.appendChild(panels);
		container.appendChild(wrap);
	}

	var videoSync = null;
	function renderVideo(container, s, v) {
		var vid = s.video;
		var video = h('video', { controls: true, playsinline: true, preload: 'metadata', poster: vid.poster || null, 'class': 'wicp-video' });
		video.appendChild(h('source', { src: vid.url }));
		(vid.tracks || []).forEach(function (t) {
			video.appendChild(h('track', { kind: 'captions', src: t.src, srclang: t.lang, label: t.label, 'default': t.lang === lang ? true : null }));
		});
		video.addEventListener('ended', function () { mediaDone[s.id] = true; checkUnlock(); });
		video.addEventListener('error', function () { mediaDone[s.id] = true; checkUnlock(); });
		container.appendChild(h('div', { 'class': 'wicp-video-wrap' }, [video]));
		if (!(vid.tracks || []).length) {
			container.appendChild(h('p', { 'class': 'wicp-note', text: 'This video has no captions yet. The transcript tab has the words.' }));
		}
		if (v.altAudio) {
			// A second audio track: the video is muted and a dubbed track plays in step with it.
			var dub = h('audio', { src: v.altAudio, preload: 'auto' });
			var useDub = h('input', { type: 'checkbox', id: 'wicp-dub', checked: true });
			var sync = function () { if (Math.abs(dub.currentTime - video.currentTime) > 0.3) { dub.currentTime = video.currentTime; } };
			var apply = function () {
				video.muted = useDub.checked;
				if (!useDub.checked) { dub.pause(); } else if (!video.paused) { sync(); dub.play(); }
			};
			video.addEventListener('play', function () { if (useDub.checked) { sync(); var p = dub.play(); if (p && p.catch) { p.catch(function () {}); } } });
			video.addEventListener('pause', function () { dub.pause(); });
			video.addEventListener('seeked', sync);
			video.addEventListener('ratechange', function () { dub.playbackRate = video.playbackRate; });
			useDub.addEventListener('change', apply);
			container.appendChild(h('p', { 'class': 'wicp-check' }, [useDub, h('label', { 'for': 'wicp-dub', text: ' Play the ' + langLabel(lang) + ' audio track' })]));
			container.appendChild(dub);
			videoSync = dub;
			apply();
		}
	}

	function render(moveFocus) {
		var x = slides[index];
		var s = x.s;
		var v = view(s);

		if (videoSync) { videoSync.pause(); videoSync = null; }
		el.slide.innerHTML = '';
		el.slide.className = 'wicp-slide wicp-slide--' + s.layout;
		el.slide.setAttribute('lang', v.translated ? lang : (D.defaultLang || ''));
		if (D.completed && index === 0) {
			el.slide.appendChild(h('p', { 'class': 'wicp-note', text: 'You have already completed this course. Reviewing it does not change your record.' }));
		}
		if (v.wantsTranslation && !v.translated) {
			el.slide.appendChild(h('p', { 'class': 'wicp-note', text: 'This slide is not available in ' + langLabel(lang) + ' yet, so it is shown in ' + langLabel(D.defaultLang) + '.' }));
		} else if (v.needsReview) {
			el.slide.appendChild(h('p', { 'class': 'wicp-note', text: 'The original wording of this slide changed recently; this translation is waiting to be checked.' }));
		}
		el.slide.appendChild(h('p', { 'class': 'wicp-kicker', text: x.m.title }));
		var heading = h('h2', { tabindex: '-1', text: v.title });
		el.slide.appendChild(heading);

		if (s.video && s.video.url) {
			renderVideo(el.slide, s, v);
		}
		if (s.image) {
			var img = h('img', { src: s.image, alt: s.alt || '', 'class': 'wicp-img' });
			el.slide.appendChild(h('button', {
				type: 'button', 'class': 'wicp-img-btn', 'aria-label': 'Enlarge image: ' + (s.alt || ''),
				onclick: function () { openLightbox(s.image, s.alt); }
			}, [img]));
		}
		if (v.html) {
			el.slide.appendChild(h('div', { 'class': s.layout === 'callout' ? 'wicp-callout' : 'wicp-content', html: v.html }));
		}
		renderLayers(el.slide, s);
		if (s.layout === 'question' && v.question) {
			renderQuestion(el.slide, s, v.question);
		}

		// Narration
		if (v.audio) {
			el.audioWrap.hidden = false;
			if (el.audio.getAttribute('src') !== v.audio) { el.audio.setAttribute('src', v.audio); }
			el.audio.playbackRate = parseFloat(el.speed.value) || 1;
			var p = el.audio.play();
			if (p && p.catch) { p.catch(function () { /* autoplay blocked until the learner interacts */ }); }
			preloadNext();
		} else {
			el.audio.pause();
			el.audio.removeAttribute('src');
			el.audioWrap.hidden = true;
		}
		// Generated narration only where agreed, only where there is no recording, and always labelled.
		if (el.ttsWrap) {
			el.ttsWrap.hidden = !(D.generatedNarration && !v.audio && v.script && window.speechSynthesis);
		}

		renderTranscript();
		renderNotePanel();
		if (el.printModule) {
			el.printModule.href = x.m.printUrl || '#';
			el.printModule.hidden = !x.m.printUrl;
		}

		// Record
		seen[s.id] = true;
		setProgress(localProgress());
		el.remaining.textContent = remainingText();
		el.pos.textContent = 'Slide ' + (index + 1) + ' of ' + slides.length;
		updateControls();
		checkUnlock();
		updateOutline();
		ping();

		var url = new URL(window.location.href);
		url.searchParams.set('course', D.courseId);
		url.searchParams.set('slide', s.id);
		url.searchParams.delete('restart');
		url.searchParams.delete('_wpnonce');
		window.history.replaceState(null, '', url.toString());

		if (moveFocus) { heading.focus(); }
	}

	function preloadNext() {
		var n = slides[index + 1];
		var a = n ? view(n.s).audio : '';
		if (a) {
			var pre = new Audio();
			pre.preload = 'auto';
			pre.src = a;
		}
	}

	/* ------------------------------------------------- transcript + search */

	/** Put <mark> around every accent-insensitive match of q in text. */
	function highlighted(text, q) {
		var frag = document.createDocumentFragment();
		var fq = fold(q).trim();
		if (!fq) { frag.appendChild(document.createTextNode(text)); return frag; }
		var folded = '';
		var map = [];
		for (var i = 0; i < text.length; i++) {
			var f = fold(text.charAt(i));
			for (var k = 0; k < f.length; k++) { folded += f.charAt(k); map.push(i); }
		}
		var pos = 0;
		var at = folded.indexOf(fq);
		while (at !== -1) {
			var start = map[at];
			var end = map[at + fq.length - 1] + 1;
			if (start > pos) { frag.appendChild(document.createTextNode(text.slice(pos, start))); }
			frag.appendChild(h('mark', { text: text.slice(start, end) }));
			pos = end;
			at = folded.indexOf(fq, at + fq.length);
		}
		if (pos < text.length) { frag.appendChild(document.createTextNode(text.slice(pos))); }
		return frag;
	}

	function renderTranscript() {
		var v = view(slides[index].s);
		el.transcript.innerHTML = '';
		el.transcript.setAttribute('lang', v.translated ? lang : (D.defaultLang || ''));
		el.transcript.appendChild(h('h3', { text: v.title }));
		var p = h('p');
		if (v.script) { p.appendChild(highlighted(v.script, searchQuery)); } else { p.textContent = 'There is no narration on this slide.'; }
		el.transcript.appendChild(p);
	}

	function runSearch(q) {
		searchQuery = q;
		el.searchResults.innerHTML = '';
		var fq = fold(q).trim();
		if (!fq) { renderTranscript(); return; }
		var hits = [];
		slides.forEach(function (x, i) {
			var v = view(x.s);
			var text = (v.script || '');
			var ft = fold(text);
			var at = ft.indexOf(fq);
			if (at !== -1 || fold(v.title).indexOf(fq) !== -1) { hits.push({ i: i, v: v, at: at }); }
		});
		el.searchResults.appendChild(h('p', { 'class': 'wicp-help', text: hits.length ? hits.length + ' slide' + (hits.length === 1 ? '' : 's') + ' found' : 'Nothing found. Try fewer or different words.' }));
		if (hits.length) {
			var ul = h('ul');
			hits.forEach(function (hit) {
				var snippet = hit.v.script || '';
				if (hit.at > 40) { snippet = '…' + snippet.slice(hit.at - 40); }
				if (snippet.length > 140) { snippet = snippet.slice(0, 140) + '…'; }
				var btn = h('button', {
					type: 'button', 'class': 'wicp-link',
					disabled: !canGo(hit.i),
					onclick: function () { go(hit.i); }
				}, [h('strong', { text: hit.v.title }), h('br')]);
				btn.appendChild(highlighted(snippet, q));
				ul.appendChild(h('li', null, [btn]));
			});
			el.searchResults.appendChild(ul);
		}
		renderTranscript();
	}

	if (el.search) {
		el.search.addEventListener('submit', function (e) { e.preventDefault(); runSearch(el.searchQ.value); });
		el.searchQ.addEventListener('input', debounce(function () { runSearch(el.searchQ.value); }, 300));
	}

	/* ----------------------------------------------------- notes + bookmarks */

	function saveNote() {
		var id = slides[index].s.id;
		var n = notes[id] || { note: '', bookmark: false };
		el.noteStatus.textContent = 'Saving…';
		api('/note', { course: D.courseId, slide: id, note: n.note, bookmark: n.bookmark ? 1 : 0 }).then(function (r) {
			el.noteStatus.textContent = r.queued ? 'Saved on this device — will sync when you are back online.' : (r.ok ? 'Saved.' : 'Could not save your note.');
		}).catch(function () { el.noteStatus.textContent = 'Could not save your note.'; });
		renderNoteList();
		updateOutline();
	}
	var saveNoteSoon = debounce(saveNote, 800);

	function renderNotePanel() {
		if (!el.note) { return; }
		var n = notes[slides[index].s.id] || { note: '', bookmark: false };
		el.note.value = n.note || '';
		el.bookmark.setAttribute('aria-pressed', n.bookmark ? 'true' : 'false');
		el.bookmark.textContent = n.bookmark ? '★ Bookmarked' : 'Bookmark this slide';
		el.noteStatus.textContent = '';
		renderNoteList();
	}

	function renderNoteList() {
		if (!el.noteList) { return; }
		el.noteList.innerHTML = '';
		var any = false;
		slides.forEach(function (x, i) {
			var n = notes[x.s.id];
			if (!n || (!n.bookmark && !n.note)) { return; }
			any = true;
			el.noteList.appendChild(h('li', null, [
				h('button', { type: 'button', 'class': 'wicp-link', onclick: function () { go(i); } }, [
					(n.bookmark ? '★ ' : '') + view(x.s).title
				]),
				n.note ? h('p', { 'class': 'wicp-help', text: n.note.length > 90 ? n.note.slice(0, 90) + '…' : n.note }) : null
			]));
		});
		if (!any) { el.noteList.appendChild(h('li', { 'class': 'wicp-help', text: 'No notes or bookmarks in this course yet.' })); }
	}

	function toggleBookmark() {
		var id = slides[index].s.id;
		var n = notes[id] || { note: '', bookmark: false };
		n.bookmark = !n.bookmark;
		notes[id] = n;
		renderNotePanel();
		announce(n.bookmark ? 'Slide bookmarked.' : 'Bookmark removed.');
		saveNote();
	}

	if (el.note) {
		el.note.addEventListener('input', function () {
			var id = slides[index].s.id;
			var n = notes[id] || { note: '', bookmark: false };
			n.note = el.note.value;
			notes[id] = n;
			el.noteStatus.textContent = '';
			saveNoteSoon();
		});
		el.bookmark.addEventListener('click', toggleBookmark);
	}

	/* -------------------------------------------------------- questions */

	var saveDraftSoon = debounce(function (sid, answer) {
		api('/draft', { course: D.courseId, slide: sid, answer: answer });
	}, 600);

	function draftChanged(sid, answer) {
		drafts[sid] = answer;
		saveDraftSoon(sid, answer);
	}

	function renderQuestion(container, s, q) {
		var st = qstate[s.id] || { used: 0, correct: false };
		var done = st.correct || st.used >= q.attempts;
		var form = h('form', { 'class': 'wicp-q', novalidate: true });
		var fieldset = h('fieldset');
		fieldset.appendChild(h('legend', { html: q.prompt }));
		form.appendChild(fieldset);

		var getAnswer, order = [];
		var draft = drafts[s.id];

		if (q.type === 'mc' || q.type === 'tf' || q.type === 'mr') {
			order = q.type === 'tf' ? range(q.options.length) : shuffle(range(q.options.length));
			var multi = q.type === 'mr';
			if (multi) { fieldset.appendChild(h('p', { 'class': 'wicp-help', text: 'Select all that apply.' })); }
			order.forEach(function (oi) {
				var id = 'wicp-o-' + s.id + '-' + oi;
				var checked = multi ? (Array.isArray(draft) && draft.indexOf(oi) !== -1) : draft === oi;
				fieldset.appendChild(h('div', { 'class': 'wicp-option' }, [
					h('input', { type: multi ? 'checkbox' : 'radio', name: 'q' + s.id, id: id, value: oi, checked: checked ? true : null }),
					h('label', { 'for': id, html: q.options[oi].text })
				]));
			});
			getAnswer = function () {
				var boxes = form.querySelectorAll('input:checked');
				if (!boxes.length) { return null; }
				if (!multi) { return parseInt(boxes[0].value, 10); }
				return Array.prototype.map.call(boxes, function (c) { return parseInt(c.value, 10); });
			};
			fieldset.addEventListener('change', function () { var a = getAnswer(); if (a !== null) { draftChanged(s.id, a); } });
		} else if (q.type === 'sort') {
			getAnswer = buildPlacement(fieldset, s.id, q.items, q.categories, false, Array.isArray(draft) ? draft : null);
			order = null;
		} else if (q.type === 'match') {
			getAnswer = buildPlacement(fieldset, s.id, q.left, q.right, true, Array.isArray(draft) ? draft : null);
			order = null;
		}

		if (q.hint) {
			form.appendChild(h('details', { 'class': 'wicp-hint' }, [h('summary', { text: 'Show a hint' }), h('div', { html: q.hint })]));
		}

		var feedback = h('div', { 'class': 'wicp-feedback', role: 'status', hidden: true });
		var submit = h('button', { type: 'submit', 'class': 'wicp-btn wicp-btn--primary', text: 'Check answer' });
		var attemptsText = h('span', { 'class': 'wicp-help' });
		form.appendChild(h('div', { 'class': 'wicp-q__actions' }, [submit, attemptsText]));
		form.appendChild(feedback);

		function refreshAttempts() {
			var cur = qstate[s.id] || { used: 0 };
			attemptsText.textContent = 'Attempt ' + Math.min(cur.used + 1, q.attempts) + ' of ' + q.attempts + ' · ' + q.points + ' points';
		}
		refreshAttempts();

		function lock() {
			Array.prototype.forEach.call(form.querySelectorAll('input, button:not(.wicp-review)'), function (n) { n.disabled = true; });
		}

		function showResult(d) {
			feedback.hidden = false;
			feedback.innerHTML = '';
			feedback.className = 'wicp-feedback ' + (d.correct ? 'is-ok' : 'is-err');
			feedback.appendChild(h('p', null, [h('strong', { text: d.correct ? 'Correct. ' : 'Not quite. ' })]));
			feedback.appendChild(h('div', { html: d.feedback }));
			if (d.correct_answer) {
				feedback.appendChild(h('p', null, [h('strong', { text: 'The correct answer: ' }), d.correct_answer]));
			}
			if (!d.correct && d.explain_slide) {
				var target = indexOf(d.explain_slide);
				if (target !== -1) {
					feedback.appendChild(h('button', {
						type: 'button', 'class': 'wicp-btn wicp-review',
						onclick: function () { go(target); }
					}, ['Review: ' + (slides[target] ? view(slides[target].s).title : d.explain_title)]));
				}
			}
			if (!d.done) {
				feedback.appendChild(h('p', { text: 'You have ' + d.attempts_left + ' attempt' + (d.attempts_left === 1 ? '' : 's') + ' left.' }));
				submit.disabled = false;
				refreshAttempts();
			} else {
				lock();
				attemptsText.textContent = '';
			}
			if (d.test_out) { showTestOut(feedback, d.test_out); }
		}

		if (pending[s.id]) {
			lock();
			feedback.hidden = false;
			feedback.className = 'wicp-feedback is-warn';
			feedback.textContent = 'Answer saved, will be checked when you are back online.';
		} else if (synced[s.id]) {
			showResult(synced[s.id]);
		} else if (done) {
			lock();
			feedback.hidden = false;
			feedback.className = 'wicp-feedback ' + (st.correct ? 'is-ok' : 'is-err');
			feedback.textContent = st.correct ? 'You answered this correctly.' : 'You have used every attempt on this question.';
			attemptsText.textContent = '';
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var answer = getAnswer();
			if (answer === null) {
				announce('Answer every part of the question first.');
				feedback.hidden = false;
				feedback.className = 'wicp-feedback is-warn';
				feedback.textContent = 'Answer every part of the question first.';
				return;
			}
			submit.disabled = true;
			delete synced[s.id];
			api('/answer', { course: D.courseId, slide: s.id, answer: answer, order: order || [], lang: lang }).then(function (r) {
				var d = r.data;
				if (r.queued) {
					pending[s.id] = true;
					lock();
					feedback.hidden = false;
					feedback.className = 'wicp-feedback is-warn';
					feedback.textContent = 'Answer saved, will be checked when you are back online.';
					announce(feedback.textContent);
					return;
				}
				if (!r.ok) {
					feedback.hidden = false;
					feedback.innerHTML = '';
					feedback.className = 'wicp-feedback is-warn';
					feedback.textContent = d && d.message ? d.message : 'Something went wrong. Please try again.';
					if (d && (d.code === 'wic_answered' || d.code === 'wic_no_attempts')) { lock(); } else { submit.disabled = false; }
					announce(feedback.textContent);
					return;
				}
				recordState(s.id, d);
				delete drafts[s.id];
				showResult(d);
				announce(feedback.textContent);
			}).catch(function () {
				submit.disabled = false;
				feedback.hidden = false;
				feedback.className = 'wicp-feedback is-warn';
				feedback.textContent = 'Could not reach the server. Your answer was not recorded — please try again.';
			});
		});

		container.appendChild(form);
	}

	function recordState(sid, d) {
		var cur = qstate[sid] || { used: 0, correct: false };
		cur.used += 1;
		cur.correct = !!d.correct;
		qstate[sid] = cur;
		if (d.test_out && d.test_out.passed) { D.completed = true; }
	}

	/** An answer made offline has now been scored: update the record and, if it is on screen, show the result. */
	function onAnswerSynced(sid, r) {
		delete pending[sid];
		delete drafts[sid];
		if (r.ok && r.data) {
			recordState(sid, r.data);
			synced[sid] = r.data;
		}
		if (!finished && slides[index] && slides[index].s.id === sid) {
			render(false);
			announce(r.ok ? 'Your answer has been checked. ' + (r.data.correct ? 'Correct.' : 'Not quite.') : 'Your saved answer could not be checked.');
		}
	}

	function showTestOut(box, t) {
		if (t.passed) {
			box.appendChild(h('p', { 'class': 'wicp-testout is-ok' }, [h('strong', { text: 'You tested out. ' }),
				'You scored ' + t.pct + '% on the pre-test (pass mark ' + t.pass_mark + '%). The course is complete — you do not need to work through the rest, though you are welcome to.']));
			if (t.certificate) {
				box.appendChild(h('p', null, [h('a', { 'class': 'wicp-btn wicp-btn--primary', href: t.certificate.url, text: 'View certificate ' + t.certificate.number })]));
			}
		} else {
			box.appendChild(h('p', { 'class': 'wicp-testout' }, [h('strong', { text: 'Pre-test finished. ' }),
				'You scored ' + t.pct + '% and the pass mark is ' + t.pass_mark + '%. Carry on with the course — it covers everything the pre-test asked.']));
		}
	}

	/**
	 * Drag-and-drop and matching as select-then-place: works with a mouse, a keyboard,
	 * touch and a screen reader. Values are always original indices.
	 */
	function buildPlacement(fieldset, sid, items, targets, oneToOne, initial) {
		var placed = items.map(function (x, i) {
			return initial && typeof initial[i] === 'number' && initial[i] >= 0 && initial[i] < targets.length ? initial[i] : -1;
		});
		var selected = -1;
		var itemOrder = shuffle(range(items.length));
		var targetOrder = oneToOne ? shuffle(range(targets.length)) : range(targets.length);

		fieldset.appendChild(h('p', { 'class': 'wicp-help', text: oneToOne
			? 'Select an item on the left, then select its match on the right.'
			: 'Select an item, then select where it belongs.' }));

		var grid = h('div', { 'class': 'wicp-place' });
		var pool = h('div', { 'class': 'wicp-place__pool', role: 'group', 'aria-label': 'Items' });
		var dest = h('div', { 'class': 'wicp-place__targets', role: 'group', 'aria-label': oneToOne ? 'Matches' : 'Categories' });
		grid.appendChild(pool);
		grid.appendChild(dest);
		fieldset.appendChild(grid);

		function changed() { draftChanged(sid, placed.slice()); }

		function draw() {
			pool.innerHTML = '';
			dest.innerHTML = '';
			itemOrder.forEach(function (ii) {
				if (!oneToOne && placed[ii] !== -1) { return; }
				var label = items[ii];
				if (oneToOne && placed[ii] !== -1) { label += '  →  ' + targets[placed[ii]]; }
				pool.appendChild(h('button', {
					type: 'button', 'class': 'wicp-chip' + (placed[ii] !== -1 ? ' is-placed' : ''), 'aria-pressed': selected === ii ? 'true' : 'false',
					onclick: function () { selected = selected === ii ? -1 : ii; draw(); focusItem(ii); }
				}, [label]));
			});
			if (!oneToOne && itemOrder.every(function (ii) { return placed[ii] !== -1; })) {
				pool.appendChild(h('p', { 'class': 'wicp-help', text: 'Everything is placed. Check your answer, or remove an item to move it.' }));
			}
			targetOrder.forEach(function (ti) {
				var box = h('div', { 'class': 'wicp-target' });
				var used = placed.indexOf(ti) !== -1;
				box.appendChild(h('button', {
					type: 'button', 'class': 'wicp-target__btn' + (oneToOne && used ? ' is-used' : ''), disabled: selected === -1,
					onclick: function () {
						if (selected === -1) { return; }
						if (oneToOne) {
							var other = placed.indexOf(ti);
							if (other !== -1) { placed[other] = -1; }
						}
						placed[selected] = ti;
						announce(items[selected] + ' placed in ' + targets[ti]);
						selected = -1;
						draw();
						changed();
					}
				}, [(selected === -1 ? '' : (oneToOne ? 'Match with: ' : 'Place in: ')) + targets[ti]]));
				if (!oneToOne) {
					var ul = h('ul', { 'class': 'wicp-target__list' });
					placed.forEach(function (t, ii) {
						if (t !== ti) { return; }
						ul.appendChild(h('li', null, [
							h('span', { text: items[ii] }),
							h('button', {
								type: 'button', 'class': 'wicp-link', 'aria-label': 'Remove ' + items[ii] + ' from ' + targets[ti],
								onclick: function () { placed[ii] = -1; draw(); changed(); }
							}, ['Remove'])
						]));
					});
					box.appendChild(ul);
				}
				dest.appendChild(box);
			});
		}

		function focusItem(ii) {
			var btns = pool.querySelectorAll('button');
			var visible = itemOrder.filter(function (x) { return oneToOne || placed[x] === -1; });
			var pos = visible.indexOf(ii);
			if (pos !== -1 && btns[pos]) { btns[pos].focus(); }
		}

		draw();
		return function () {
			return placed.some(function (p) { return p === -1; }) ? null : placed.slice();
		};
	}

	/* ----------------------------------------------------------- finish */

	function finish() {
		if (D.navMode === 'locked' && !isDone(index)) { checkUnlock(); return; }
		el.next.disabled = true;
		stopSpeech();
		rawApi('/complete', { course: D.courseId }).then(function (r) {
			el.next.disabled = false;
			if (!r.ok) { announce('Could not check completion. Please try again.'); return; }
			finished = true;
			showResults(r.data);
		}).catch(function () {
			el.next.disabled = false;
			announce(navigator.onLine === false
				? 'You are offline. Your progress is saved on this device; finish the course when you are back online.'
				: 'Could not reach the server. Please try again.');
		});
	}

	function showResults(d) {
		el.slide.innerHTML = '';
		el.slide.className = 'wicp-slide wicp-slide--results';
		var heading = h('h2', { tabindex: '-1', text: d.complete ? 'Course complete' : 'Not finished yet' });
		el.slide.appendChild(heading);

		if (d.complete) {
			D.completed = true;
			var mins = Math.max(1, Math.round((d.time_spent || 0) / 60));
			el.slide.appendChild(h('p', { 'class': 'wicp-score', text: 'Score: ' + d.score + '%' }));
			el.slide.appendChild(h('p', { text: 'Time in course: about ' + mins + ' minute' + (mins === 1 ? '' : 's') + '.' }));
			if (d.certificate) {
				el.slide.appendChild(h('p', null, [
					'Your certificate ', h('code', { text: d.certificate.number }), ' has been issued and a copy emailed to you.'
				]));
				el.slide.appendChild(h('p', null, [h('a', { 'class': 'wicp-btn wicp-btn--primary', href: d.certificate.url, text: 'View certificate' })]));
			}
			if (D.next && D.next.url) {
				el.slide.appendChild(h('p', null, [h('a', { 'class': 'wicp-btn', href: D.next.url, text: 'Continue to ' + D.next.title })]));
			}
		} else {
			if (d.unseen) {
				var first = indexOf(d.first_unseen);
				el.slide.appendChild(h('p', { text: d.unseen + ' slide' + (d.unseen === 1 ? ' has' : 's have') + ' not been opened yet.' }));
				if (first !== -1) {
					el.slide.appendChild(h('p', null, [h('button', { type: 'button', 'class': 'wicp-btn wicp-btn--primary', onclick: function () { go(first); } }, ['Go to "' + view(slides[first].s).title + '"'])]));
				}
			}
			if (d.blockers && d.blockers.length) {
				el.slide.appendChild(h('h3', { text: 'Before this course can be completed:' }));
				el.slide.appendChild(h('ul', null, d.blockers.map(function (b) {
					return h('li', null, [b.url ? h('a', { href: b.url, text: b.label }) : b.label]);
				})));
			}
			if (d.failed && d.failed.length) {
				d.failed.forEach(function (f) {
					el.slide.appendChild(h('p', { text: f.title + ': ' + f.pct + '% — the pass mark is ' + f.pass_mark + '%.' }));
				});
				var retake = d.retake || D.retake || { allowed: true };
				if (retake.allowed) {
					el.slide.appendChild(h('p', { text: 'Starting the course again keeps this attempt on your record and gives you fresh attempts at every question.' }));
					el.slide.appendChild(h('p', null, [h('a', { 'class': 'wicp-btn', href: D.restartUrl, text: 'Start the course again' })]));
				} else {
					el.slide.appendChild(h('p', { 'class': 'wicp-note', text: retake.message }));
				}
			}
		}

		if (d.modules && d.modules.length) {
			var rows = d.modules.filter(function (m) { return m.max > 0; }).map(function (m) {
				return h('tr', null, [
					h('th', { scope: 'row', text: m.title + (m.assessment ? ' (graded)' : '') }),
					h('td', { text: m.got + ' / ' + m.max }),
					h('td', { text: m.pct + '%' })
				]);
			});
			if (rows.length) {
				el.slide.appendChild(h('h3', { text: 'Score by module' }));
				el.slide.appendChild(h('table', { 'class': 'wicp-table' }, [
					h('thead', null, [h('tr', null, [h('th', { scope: 'col', text: 'Module' }), h('th', { scope: 'col', text: 'Points' }), h('th', { scope: 'col', text: 'Score' })])]),
					h('tbody', null, rows)
				]));
			}
		}
		el.slide.appendChild(h('p', null, [h('a', { href: D.portalUrl, text: 'Back to the portal' })]));
		el.prev.disabled = false;
		heading.focus();
	}

	/* ------------------------------------------------ generated narration */

	function spoken(text) {
		(D.pronunciations || []).forEach(function (p) {
			var term = p[0].replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
			text = text.replace(new RegExp('(^|[^\\w])' + term + '(?=$|[^\\w])', 'gi'), function (m, pre) { return pre + p[1]; });
		});
		return text;
	}

	function stopSpeech() {
		if (window.speechSynthesis) { window.speechSynthesis.cancel(); }
		if (el.tts) { el.tts.setAttribute('aria-pressed', 'false'); el.tts.textContent = 'Listen'; }
	}

	if (el.tts) {
		el.tts.addEventListener('click', function () {
			if (!window.speechSynthesis) { return; }
			if (el.tts.getAttribute('aria-pressed') === 'true') { stopSpeech(); return; }
			var v = view(slides[index].s);
			var u = new window.SpeechSynthesisUtterance(spoken(v.script));
			u.lang = v.translated ? lang : (D.defaultLang || lang);
			u.rate = parseFloat(el.speed.value) || 1;
			u.onend = function () { stopSpeech(); };
			window.speechSynthesis.cancel();
			window.speechSynthesis.speak(u);
			el.tts.setAttribute('aria-pressed', 'true');
			el.tts.textContent = 'Stop';
		});
	}

	/* ------------------------------------------------------------ extras */

	var lightboxReturn = null;
	function openLightbox(src, alt) {
		lightboxReturn = document.activeElement;
		el.lightboxImg.src = src;
		el.lightboxImg.alt = alt || '';
		el.lightbox.hidden = false;
		el.lightboxClose.focus();
	}
	function closeLightbox() {
		el.lightbox.hidden = true;
		if (lightboxReturn) { lightboxReturn.focus(); }
	}
	el.lightboxClose.addEventListener('click', closeLightbox);
	el.lightbox.addEventListener('click', function (e) { if (e.target === el.lightbox) { closeLightbox(); } });

	el.prev.addEventListener('click', function () { if (finished) { finished = false; render(true); return; } go(index - 1); });
	el.next.addEventListener('click', function () {
		if (index === slides.length - 1) { finish(); } else { go(index + 1); }
	});

	// Speed and auto-advance are per-viewer conveniences.
	var savedSpeed = store('speed');
	if (savedSpeed) { el.speed.value = savedSpeed; }
	el.speed.addEventListener('change', function () {
		el.audio.playbackRate = parseFloat(el.speed.value) || 1;
		store('speed', el.speed.value);
	});
	var savedAuto = store('auto');
	el.auto.checked = savedAuto === null ? !!D.autoAdvance : savedAuto === '1';
	el.auto.addEventListener('change', function () { store('auto', el.auto.checked ? '1' : '0'); });
	el.audio.addEventListener('ended', function () {
		var s = slides[index].s;
		mediaDone[s.id] = true;
		checkUnlock();
		if (el.auto.checked && s.layout !== 'question' && index < slides.length - 1 && canGo(index + 1)) { go(index + 1); }
	});
	el.audio.addEventListener('error', function () {
		// A clip that cannot load must never trap a learner behind locked navigation.
		if (el.audio.getAttribute('src')) { mediaDone[slides[index].s.id] = true; checkUnlock(); }
	});

	// Text size (A−/A/A+), remembered on this device.
	var sizeLevel = parseInt(store('size') || '0', 10) || 0;
	function applySize(announceIt) {
		sizeLevel = Math.max(-2, Math.min(4, sizeLevel));
		var pct = 100 + sizeLevel * 12.5;
		document.documentElement.style.fontSize = pct + '%';
		store('size', String(sizeLevel));
		if (announceIt) { announce('Text size ' + pct + '%'); }
	}
	Array.prototype.forEach.call(document.querySelectorAll('.wicp-size [data-size]'), function (b) {
		b.addEventListener('click', function () {
			var d = parseInt(b.getAttribute('data-size'), 10);
			sizeLevel = d === 0 ? 0 : sizeLevel + d;
			applySize(true);
		});
	});
	applySize(false);

	// Language: text, narration and transcript switch together.
	if (el.lang) {
		el.lang.addEventListener('change', function () {
			lang = el.lang.value;
			stopSpeech();
			api('/pref', { lang: lang, slide: 0 });
			if (finished) { finished = false; }
			render(false);
			if (searchQuery) { runSearch(searchQuery); }
			announce('Course language: ' + langLabel(lang));
		});
	}

	// Sidebar tabs: Outline, Transcript, Notes, Resources.
	var tabs = Array.prototype.slice.call(document.querySelectorAll('.wicp-tabs [role="tab"]'));
	function selectTab(t, focus) {
		tabs.forEach(function (x) {
			var on = x === t;
			x.setAttribute('aria-selected', on ? 'true' : 'false');
			x.tabIndex = on ? 0 : -1;
			$(x.getAttribute('aria-controls')).hidden = !on;
		});
		if (focus !== false) { t.focus(); }
	}
	tabs.forEach(function (t, i) {
		t.addEventListener('click', function () { selectTab(t); });
		t.addEventListener('keydown', function (e) {
			var n = null;
			if (e.key === 'ArrowRight') { n = tabs[(i + 1) % tabs.length]; }
			else if (e.key === 'ArrowLeft') { n = tabs[(i - 1 + tabs.length) % tabs.length]; }
			else if (e.key === 'Home') { n = tabs[0]; }
			else if (e.key === 'End') { n = tabs[tabs.length - 1]; }
			if (n) { e.preventDefault(); e.stopPropagation(); selectTab(n); }
		});
	});

	// Course resources, filled by the resources module.
	if (el.resources && D.resources && D.resources.length) {
		D.resources.forEach(function (r) {
			el.resources.appendChild(h('li', null, [
				r.thumb ? h('img', { src: r.thumb, alt: '', 'class': 'wicp-res-thumb' }) : null,
				h('span', null, [
					h('a', { href: r.url, target: '_blank', rel: 'noopener', text: r.title }),
					h('span', { 'class': 'wicp-help', text: ' ' + (r.kind === 'document' ? 'Document' : 'Link') + ' · opens in a new tab' })
				])
			]));
		});
	}

	// Outline open by default on wide screens, behind the toggle on narrow ones.
	function setSide(open) {
		el.side.classList.toggle('is-closed', !open);
		el.toggleSide.setAttribute('aria-expanded', open ? 'true' : 'false');
	}
	setSide(window.matchMedia('(min-width: 900px)').matches);
	el.toggleSide.addEventListener('click', function () { setSide(el.side.classList.contains('is-closed')); });
	el.outline.addEventListener('toggle', function (e) { if (e.target.dataset) { e.target.dataset.touched = '1'; } }, true);

	// Full screen, for shared clinic monitors.
	if (!document.documentElement.requestFullscreen) {
		el.fullscreen.hidden = true;
	} else {
		el.fullscreen.addEventListener('click', function () {
			if (document.fullscreenElement) { document.exitFullscreen(); } else { document.documentElement.requestFullscreen(); }
		});
		document.addEventListener('fullscreenchange', function () {
			el.fullscreen.setAttribute('aria-pressed', document.fullscreenElement ? 'true' : 'false');
		});
	}

	// Keyboard control at the player level.
	document.addEventListener('keydown', function (e) {
		lastActivity = Date.now();
		if (!el.lightbox.hidden && e.key === 'Escape') { closeLightbox(); return; }
		var t = e.target;
		var tag = t && t.tagName;
		if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || tag === 'VIDEO' || tag === 'AUDIO' || (t && t.isContentEditable) || e.altKey || e.ctrlKey || e.metaKey) { return; }
		if (t && t.getAttribute && t.getAttribute('role') === 'tab') { return; }
		if (e.key === 'ArrowRight') { e.preventDefault(); if (!el.next.disabled) { el.next.click(); } else { checkUnlock(); announce(el.locked ? el.locked.textContent : ''); } }
		else if (e.key === 'ArrowLeft') { e.preventDefault(); if (index > 0) { go(index - 1); } }
		else if (e.key === 'k' || e.key === 'K') {
			if (el.audio.getAttribute('src')) { if (el.audio.paused) { el.audio.play(); } else { el.audio.pause(); } }
		}
		else if ((e.key === 'b' || e.key === 'B') && el.bookmark && !finished) { toggleBookmark(); }
	});
	['mousemove', 'scroll', 'touchstart', 'click'].forEach(function (ev) {
		document.addEventListener(ev, function () { lastActivity = Date.now(); }, { passive: true });
	});

	// Heartbeat so time on a long slide counts — only while someone is actually there.
	window.setInterval(function () {
		var active = Date.now() - lastActivity < 120000 || !el.audio.paused;
		if (active && !finished) { ping(); }
	}, 60000);

	// Offline: register the service worker for this lesson and ask it to keep this course's files.
	if (D.sw && 'serviceWorker' in navigator && window.caches) {
		navigator.serviceWorker.register(D.sw.url, { scope: D.sw.scope }).then(function () {
			return navigator.serviceWorker.ready;
		}).then(function (reg) {
			var urls = (D.assets || []).slice();
			urls.push(window.location.origin + window.location.pathname + '?course=' + D.courseId);
			slides.forEach(function (x) {
				var s = x.s;
				if (s.image) { urls.push(s.image); }
				if (s.audio) { urls.push(s.audio); }
				if (s.video && s.video.url) { urls.push(s.video.url); }
				if (s.video && s.video.poster) { urls.push(s.video.poster); }
				Object.keys(s.i18n || {}).forEach(function (k) {
					if (s.i18n[k].audio) { urls.push(s.i18n[k].audio); }
					if (s.i18n[k].altAudio) { urls.push(s.i18n[k].altAudio); }
				});
			});
			urls = urls.filter(function (u) {
				try { return new URL(u, window.location.href).origin === window.location.origin; } catch (err) { return false; }
			}).map(function (u) { return new URL(u, window.location.href).href; });
			if (reg.active) { reg.active.postMessage({ type: 'precache', urls: urls }); }
		}).catch(function () { /* offline mode is a bonus; the player works without it */ });
	}

	buildOutline();
	render(false);
	setOffline(false);
	flush();
})();
