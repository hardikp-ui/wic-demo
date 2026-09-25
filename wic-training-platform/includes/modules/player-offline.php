<?php
/**
 * Offline mode (#39).
 *
 * The service worker is served from the site root (/?wic_sw=1) rather than from the plugin
 * folder: a worker's maximum scope is the directory of its script URL, so one served from
 * /wp-content/plugins/… could never control the lesson page. Registered with the lesson
 * page's path as its scope, it controls only the player.
 *
 * What it caches: the lesson page for each course the learner opens (network first, cached
 * copy when offline), and plugin assets, uploads and core scripts (cache first, refreshed in
 * the background). REST calls are never cached — position and answer POSTs made while
 * offline are queued by the player and replayed in order, and still scored on the server.
 */

defined( 'ABSPATH' ) || exit;

class WIC_Offline {

	const CACHE = 'wic-player-v1';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'serve' ), 1 );
	}

	public static function url() {
		return add_query_arg( 'wic_sw', '1', home_url( '/' ) );
	}

	/** The lesson page path, which is the worker's scope. */
	public static function scope() {
		$path = wp_parse_url( wic_page_url( 'learn' ), PHP_URL_PATH );
		return $path ? $path : '/';
	}

	public static function serve() {
		if ( empty( $_GET['wic_sw'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		nocache_headers();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: ' . self::scope() );
		$offline = esc_js( __( 'You are offline, and this lesson has not been opened on this device before. Connect to the internet and open it once to make it available offline.', 'wic-tp' ) );
		echo 'var CACHE = ' . wp_json_encode( self::CACHE ) . ";\n";
		echo 'var OFFLINE_TEXT = "' . $offline . "\";\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- esc_js above.
		?>
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) {
	e.waitUntil(caches.keys().then(function (keys) {
		return Promise.all(keys.filter(function (k) { return k.indexOf('wic-player-') === 0 && k !== CACHE; }).map(function (k) { return caches.delete(k); }));
	}).then(function () { return self.clients.claim(); }));
});
function pageKey(href) {
	var u = new URL(href);
	return u.origin + u.pathname + '?course=' + (u.searchParams.get('course') || '');
}
function isAsset(url) {
	return /\/wp-content\//.test(url.pathname) || /\/wp-includes\//.test(url.pathname);
}
self.addEventListener('fetch', function (e) {
	var req = e.request;
	if (req.method !== 'GET') { return; }
	var url = new URL(req.url);
	if (url.origin !== self.location.origin) { return; }
	if (url.pathname.indexOf('/wp-json/') !== -1 || url.searchParams.has('rest_route')) { return; }
	if (req.mode === 'navigate') {
		if (!url.searchParams.get('course') || url.searchParams.get('restart') || url.searchParams.get('print')) { return; }
		e.respondWith(fetch(req).then(function (res) {
			if (res.ok) {
				var copy = res.clone();
				caches.open(CACHE).then(function (c) { c.put(pageKey(req.url), copy); });
			}
			return res;
		}).catch(function () {
			return caches.match(pageKey(req.url)).then(function (hit) {
				return hit || new Response('<!doctype html><meta charset="utf-8"><title>Offline</title><p style="font:1rem/1.5 sans-serif;padding:2rem">' + OFFLINE_TEXT + '</p>', { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
			});
		}));
		return;
	}
	if (!isAsset(url)) { return; }
	var range = req.headers.get('range');
	if (range) {
		// Audio and video ask for byte ranges; answer from the cached full file when there is one.
		e.respondWith(caches.open(CACHE).then(function (c) {
			return c.match(url.href).then(function (hit) {
				if (!hit) { return fetch(req); }
				return hit.blob().then(function (blob) {
					var m = /bytes=(\d*)-(\d*)/.exec(range) || [];
					var start = m[1] ? parseInt(m[1], 10) : 0;
					var end = m[2] ? parseInt(m[2], 10) : blob.size - 1;
					return new Response(blob.slice(start, end + 1), {
						status: 206,
						headers: {
							'Content-Type': hit.headers.get('Content-Type') || 'application/octet-stream',
							'Content-Range': 'bytes ' + start + '-' + end + '/' + blob.size,
							'Content-Length': String(end - start + 1),
							'Accept-Ranges': 'bytes'
						}
					});
				});
			});
		}));
		return;
	}
	e.respondWith(caches.open(CACHE).then(function (c) {
		return c.match(req, { ignoreSearch: false }).then(function (hit) {
			var net = fetch(req).then(function (res) {
				if (res.ok) { c.put(req, res.clone()); }
				return res;
			}).catch(function () { return hit; });
			return hit || net;
		});
	}));
});
self.addEventListener('message', function (e) {
	if (!e.data || e.data.type !== 'precache' || !e.data.urls) { return; }
	caches.open(CACHE).then(function (c) {
		e.data.urls.forEach(function (u) {
			c.match(u).then(function (hit) {
				if (hit) { return; }
				fetch(u, { credentials: 'same-origin' }).then(function (r) { if (r.ok) { c.put(u, r); } }).catch(function () {});
			});
		});
	});
});
		<?php
		exit;
	}
}

add_action( 'wic_init', array( 'WIC_Offline', 'init' ) );
