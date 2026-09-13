// ╔══════════════════════════════════════════════════════════════╗
// ║  Sunday School PWA — Service Worker v45                     ║
// ╚══════════════════════════════════════════════════════════════╝
const SW_VERSION        = new URL(self.location.href).searchParams.get('v') || 'v45';
const CACHE_NAME        = `sunday-school-${SW_VERSION}`;
const SYNC_TAG          = 'sync-attendance';
const PERIODIC_SYNC_TAG = 'check-registrations';

const QUEUEABLE_ACTIONS = ['submitAttendance', 'submitUncleAttendance', 'updateCoupons'];

// Temporarily bypasses cache-first navigation strategy when a cookie check forces a reload.
let _bypassCacheUntil = 0;

const SHELL_URLS = [
    '/favicon.ico','/logo.png',
    '/manifest.json','/manifest.webmanifest',
    '/','/index.html',
    '/login/','/login/index.html',
    '/user/','/user/index.html',
    '/user/profile/','/user/profile/index.php',
    '/user/login/','/user/login/index.html',
    '/user/registration/','/user/registration/index.html',
    '/uncle/registration/','/uncle/registration/index.html',
    '/uncle/church/registration/','/uncle/church/registration/index.html',
    '/uncle/dashboard','/uncle/dashboard/','/uncle/dashboard/index.php',
    '/uncle/church',
    '/uncle/church/','/uncle/church/index.html',
    '/uncle/church/dashboard','/uncle/church/dashboard/',
    '/uncle/church/trips',
    '/uncle/church/trips/','/uncle/church/trips/index.html',
    '/uncle/trip',
    '/uncle/trip/','/uncle/trip/index.html',
    '/uncle/trip/filter',
    '/uncle/trip/filter/','/uncle/trip/filter/index.html',
    '/uncle/trip/points',
    '/uncle/trip/points/','/uncle/trip/points/index.html',
    '/js/qr-scanner.umd.min.js',
    '/js/qr-scanner-worker.min.js',
    '/fonts/cairo.css',
    '/fonts/cairo-arabic-wght-normal.woff2',
    '/fonts/cairo-latin-wght-normal.woff2',
    'https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800;900&family=IBM+Plex+Mono:wght@400;600&display=swap',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-solid-900.woff2',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-regular-400.woff2',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-brands-400.woff2',
    'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js',
];

// ── INSTALL ───────────────────────────────────────────────────
self.addEventListener('install', e => {
    self.skipWaiting();
    e.waitUntil(caches.open(CACHE_NAME).then(async cache => {
        for (const url of SHELL_URLS) {
            try {
                const response = await fetch(url, { cache: 'no-store' });
                if (response.ok) {
                    const isCookieCheck = await _isCookieCheckResponse(response);
                    if (!isCookieCheck) {
                        const copy = response.clone();
                        await cache.put(url, copy);
                    }
                }
            } catch (err) {
                // Ignore precache failures for individual URLs
            }
        }
    }));
});

// ── ACTIVATE ──────────────────────────────────────────────────
self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys()
            .then(keys => Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))))
            .then(() => self.clients.claim())
            .then(() => _registerPeriodicSync())
            .then(() => _flushQueue())
    );
});

// ── FETCH ─────────────────────────────────────────────────────
self.addEventListener('fetch', e => {
    const url = new URL(e.request.url);

    // Bypass Service Worker for security verification resources
    // This prevents blocking aes.js (which contains decryption logic) and avoids redirect deadlocks
    if (
        url.pathname.includes('aes.js') || 
        url.pathname.includes('cookies.html') || 
        url.pathname.includes('/taranim') ||
        url.hostname.includes('ifastnet.com')
    ) {
        return;
    }

    const isSameOrigin = url.origin === self.location.origin;
    const isOfflineShellFriendly =
        isSameOrigin && (
            url.pathname.startsWith('/uncle/dashboard') ||
            url.pathname.startsWith('/uncle/church') ||
            url.pathname.startsWith('/uncle/trip') ||
            url.pathname.startsWith('/uncle/registration') ||
            url.pathname.startsWith('/user/profile') ||
            url.pathname.startsWith('/user') ||
            url.pathname.startsWith('/login') ||
            url.pathname === '/'
        );
    const isSessionSensitive =
        isSameOrigin && (
            url.pathname === '/' ||
            url.pathname.includes('/login') ||
            url.pathname.includes('/uncle/dashboard') ||
            url.pathname.includes('/uncle/church') ||
            url.pathname.includes('/user/profile')
        );

    // Manifest must always return valid JSON (never plain "Offline")
    if (url.pathname === '/manifest.json' || url.pathname === '/manifest.webmanifest') {
        const manifestPath = url.pathname === '/manifest.webmanifest' ? '/manifest.webmanifest' : '/manifest.json';
        e.respondWith(
            (async () => {
                try {
                    const r = await fetch(e.request, { cache: 'no-store' });
                    if (r && r.ok) {
                        const isCookieCheck = await _isCookieCheckResponse(r);
                        if (!isCookieCheck) {
                            const copy = r.clone();
                            const cache = await caches.open(CACHE_NAME);
                            await cache.put(manifestPath, copy);
                        }
                    }
                    return r;
                } catch (err) {
                    const cached = await caches.match(manifestPath);
                    if (cached) return cached;
                    return new Response(JSON.stringify({
                        name: 'Sunday School Online',
                        short_name: 'Sunday School Online',
                        start_url: '/',
                        display: 'standalone',
                        icons: []
                    }), {
                        headers: { 'Content-Type': 'application/manifest+json; charset=utf-8' },
                        status: 200
                    });
                }
            })()
        );
        return;
    }

    // Build version must always be fetched fresh so updates can be detected.
    if (url.pathname === '/version.php') {
        e.respondWith(fetch(e.request, { cache: 'no-store' }).catch(() => new Response(JSON.stringify({
            success: false,
            version: 'dev',
            source: 'offline'
        }), {
            headers: { 'Content-Type': 'application/json; charset=utf-8' },
            status: 503
        })));
        return;
    }

    // API / POST calls — NEVER force window.reload / client.navigate on transient API cookie challenges
    if (e.request.method === 'POST' || url.pathname.includes('api.php') || url.pathname.includes('remote_sync')) {
        e.respondWith(
            (async () => {
                try {
                    const r = await fetch(e.request.clone());
                    const contentType = (r.headers && r.headers.get('content-type')) || '';
                    if (contentType.includes('application/json')) {
                        return r;
                    }
                    const isCookieCheck = await _isCookieCheckResponse(r);
                    if (isCookieCheck) {
                        return new Response(JSON.stringify({
                            success: false,
                            offline: true,
                            cookie_check: true,
                            message: 'رمز التحقق من ملفات الكوكيز نشط'
                        }), {
                            status: 503,
                            headers: { 'Content-Type': 'application/json; charset=utf-8' }
                        });
                    }
                    return r;
                } catch (err) {
                    if (e.request.method === 'POST') {
                        // Only queue attendance/coupon actions — not login, photos, etc.
                        const queued = await _maybeQueueRequest(e.request.clone());
                        if (queued) {
                            return new Response(JSON.stringify({
                                success: false, offline: true, queued: true,
                                message: 'محفوظ محلياً — سيُرفع عند الاتصال'
                            }), { headers: { 'Content-Type': 'application/json' } });
                        }
                    }
                    return new Response(JSON.stringify({
                        success: false, offline: true, message: 'غير متصل'
                    }), { headers: { 'Content-Type': 'application/json' }, status: 503 });
                }
            })()
        );
        return;
    }

    // ── PAGE NAVIGATION (Instant Cache when offline, Network-First with 1.5s timeout when online) ──
    if (e.request.mode === 'navigate') {
        e.respondWith(
            (async () => {
                const isHomePath = url.pathname === '/' || url.pathname === '/index.html';

                // If device is offline, serve cached HTML shell instantly with zero network delay.
                // If offline and launching from home root, serve /uncle/dashboard/ directly to prevent redirect bounce!
                if (!self.navigator.onLine) {
                    if (isHomePath) {
                        const dashCached = await caches.match('/uncle/dashboard/', { ignoreSearch: true }) || await caches.match('/uncle/dashboard/index.php', { ignoreSearch: true });
                        if (dashCached) return dashCached;
                    }
                    const cachedResp = (isOfflineShellFriendly ? await caches.match(e.request, { ignoreSearch: true }) : null) || await _matchOfflineShell(e.request, url);
                    if (cachedResp) return cachedResp;
                }

                try {
                    const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
                    const timeoutId = controller ? setTimeout(() => controller.abort(), 1500) : null;
                    const fetchOpts = { cache: 'no-store' };
                    if (controller) fetchOpts.signal = controller.signal;

                    const networkResp = await fetch(e.request, fetchOpts);
                    if (timeoutId) clearTimeout(timeoutId);

                    if (networkResp && networkResp.ok && isOfflineShellFriendly && await _isCacheableAppResponse(networkResp, e.request)) {
                        try {
                            const copy = networkResp.clone();
                            const cache = await caches.open(CACHE_NAME);
                            await cache.put(e.request, copy);
                        } catch(putErr) {}
                    }
                    return networkResp;
                } catch (err) {
                    if (isHomePath) {
                        const dashCached = await caches.match('/uncle/dashboard/', { ignoreSearch: true }) || await caches.match('/uncle/dashboard/index.php', { ignoreSearch: true });
                        if (dashCached) return dashCached;
                    }

                    const cachedResponse = (isOfflineShellFriendly ? await caches.match(e.request, { ignoreSearch: true }) : null) || await _matchOfflineShell(e.request, url);
                    if (cachedResponse) return cachedResponse;

                    const fallbackShell = await _matchOfflineShell(e.request, url);
                    if (fallbackShell) return fallbackShell;

                    return new Response('<!doctype html><meta charset="utf-8"><title>Offline</title><body dir="rtl" style="font-family:sans-serif;padding:24px">غير متصل بالإنترنت</body>', {
                        status: 503,
                        headers: { 'Content-Type': 'text/html; charset=utf-8' }
                    });
                }
            })()
        );
        return;
    }

    // Never cache session-sensitive GETs either.
    if (isSessionSensitive && !isOfflineShellFriendly) {
        e.respondWith(fetch(e.request, { cache: 'no-store' }).catch(() => new Response('Offline', { status: 503 })));
        return;
    }

    // Static JS / CSS Code Assets — Network-First with 1.5s timeout when online, Instant Cache fallback when offline
    const isCodeAsset = isSameOrigin && (url.pathname.endsWith('.js') || url.pathname.endsWith('.css') || url.pathname.includes('/js/'));
    if (isCodeAsset) {
        e.respondWith(
            (async () => {
                if (!self.navigator.onLine) {
                    const cached = await caches.match(e.request, { ignoreSearch: true });
                    if (cached) return cached;
                    return new Response('/* Offline */', {
                        status: 503,
                        headers: { 'Content-Type': url.pathname.endsWith('.css') ? 'text/css' : 'application/javascript' }
                    });
                }

                try {
                    const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
                    const timeoutId = controller ? setTimeout(() => controller.abort(), 1500) : null;
                    const fetchOpts = { cache: 'no-store' };
                    if (controller) fetchOpts.signal = controller.signal;

                    const networkResp = await fetch(e.request, fetchOpts);
                    if (timeoutId) clearTimeout(timeoutId);

                    if (networkResp && networkResp.ok && await _isCacheableAppResponse(networkResp, e.request)) {
                        try {
                            const copy = networkResp.clone();
                            const cache = await caches.open(CACHE_NAME);
                            await cache.put(e.request, copy);
                        } catch(putErr) {}
                    }
                    return networkResp;
                } catch (err) {
                    const cached = await caches.match(e.request, { ignoreSearch: true });
                    if (cached) return cached;
                    return new Response('/* Offline */', {
                        status: 503,
                        headers: { 'Content-Type': url.pathname.endsWith('.css') ? 'text/css' : 'application/javascript' }
                    });
                }
            })()
        );
        return;
    }

    // Media & Image assets — cache-first
    e.respondWith(
        caches.match(e.request, { ignoreSearch: true }).then(cached => {
            if (cached) return cached;
            if (!self.navigator.onLine) {
                return new Response('Offline', { status: 503 });
            }
            return (async () => {
                try {
                    const r = await fetch(e.request, { cache: 'no-store' });
                    const isCookieCheck = await _isCookieCheckResponse(r);
                    if (isCookieCheck) {
                        return new Response('Cookie check required', { status: 503 });
                    }
                    if (e.request.method === 'GET' && await _isCacheableAppResponse(r, e.request)) {
                        const cl = r.clone();
                        caches.open(CACHE_NAME).then(c => c.put(e.request, cl)).catch(() => {});
                    }
                    return r;
                } catch (err) {
                    return new Response('Offline', { status: 503 });
                }
            })();
        })
    );
});

async function _matchOfflineShell(request, url) {
    const direct = await caches.match(request, { ignoreSearch: true });
    if (direct) return direct;

    const sameOriginPath = url && url.origin === self.location.origin ? url.pathname : '';
    const fallbackCandidates = [];

    if (sameOriginPath.startsWith('/uncle/church/dashboard')) {
        fallbackCandidates.push('/uncle/church/', '/uncle/church/index.html');
    } else if (sameOriginPath.startsWith('/uncle/dashboard')) {
        fallbackCandidates.push('/uncle/dashboard/', '/uncle/dashboard/index.php', '/uncle/church/', '/uncle/church/index.html');
    } else if (sameOriginPath.startsWith('/uncle/church/trips')) {
        fallbackCandidates.push('/uncle/church/trips/', '/uncle/church/trips/index.html');
    } else if (sameOriginPath.startsWith('/uncle/church/registration')) {
        fallbackCandidates.push('/uncle/church/registration/', '/uncle/church/registration/index.html');
    } else if (sameOriginPath.startsWith('/uncle/church')) {
        fallbackCandidates.push('/uncle/church/', '/uncle/church/index.html');
    } else if (sameOriginPath.startsWith('/uncle/trip/filter')) {
        fallbackCandidates.push('/uncle/trip/filter/', '/uncle/trip/filter/index.html');
    } else if (sameOriginPath.startsWith('/uncle/trip/points')) {
        fallbackCandidates.push('/uncle/trip/points/', '/uncle/trip/points/index.html');
    } else if (sameOriginPath.startsWith('/uncle/trip')) {
        fallbackCandidates.push('/uncle/trip/', '/uncle/trip/index.html');
    } else if (sameOriginPath.startsWith('/uncle/registration')) {
        fallbackCandidates.push('/uncle/registration/', '/uncle/registration/index.html');
    } else if (sameOriginPath.startsWith('/user/profile')) {
        fallbackCandidates.push('/user/profile/', '/user/profile/index.php');
    } else if (sameOriginPath.startsWith('/user/login')) {
        fallbackCandidates.push('/user/login/', '/user/login/index.html');
    } else if (sameOriginPath.startsWith('/user/registration')) {
        fallbackCandidates.push('/user/registration/', '/user/registration/index.html');
    } else if (sameOriginPath.startsWith('/user')) {
        fallbackCandidates.push('/user/', '/user/index.html');
    } else if (sameOriginPath.startsWith('/login')) {
        fallbackCandidates.push('/login/', '/login/index.html');
    } else if (sameOriginPath === '' || sameOriginPath === '/') {
        fallbackCandidates.push('/', '/index.html');
    }

    for (const candidate of fallbackCandidates) {
        const cached = await caches.match(candidate);
        if (cached) return cached;
    }

    return null;
}

async function _isCookieCheckResponse(response) {
    if (!response || !response.ok) return false;

    const contentType = (response.headers && response.headers.get('content-type')) || '';
    if (contentType.includes('application/json')) {
        return false;
    }

    const responseUrl = response.url || '';
    if (responseUrl.indexOf('ifastnet.com/cookies.html') !== -1) {
        return true;
    }

    if (/text\/html/i.test(contentType)) {
        try {
            const copy = response.clone();
            const text = await copy.text();
            
            // To be a true iFastNet cookie challenge, it must be a small script page.
            // Our own login/sw scripts are larger (usually > 10KB), and contain function names like _isCookieCheckResponse.
            if (text.length < 8000 && text.indexOf('_isCookieCheckResponse') === -1) {
                if (
                    text.indexOf('__test') !== -1 ||
                    text.indexOf('slowAES') !== -1 ||
                    text.indexOf('aes.js') !== -1 ||
                    text.indexOf('cookies.html') !== -1 ||
                    text.indexOf('Cookies are not enabled') !== -1 ||
                    text.indexOf('browser is not accepting cookies') !== -1
                ) {
                    return true;
                }
            }
        } catch (_) {}
    }
    return false;
}

async function _isCacheableAppResponse(response, request) {
    if (!response) return false;

    // Cache opaque responses (status 0) for images and fonts to allow offline viewing
    const isFontOrImage = request && (request.destination === 'font' || request.destination === 'image');
    if (isFontOrImage && response.status === 0) {
        return true;
    }

    if (!response.ok || await _isCookieCheckResponse(response)) return false;
    const copy = response.clone();
    const contentType = copy.headers.get('content-type') || '';

    if (!/text\/html/i.test(contentType)) return true;

    try {
        const text = await copy.text();
        return text.indexOf('sv101.ifastnet.com/cookies.html') === -1 &&
            text.indexOf('Cookies are not enabled') === -1 &&
            text.indexOf('It appears your browser is not accepting cookies') === -1 &&
            text.indexOf('document.cookie="__test="') === -1 &&
            text.indexOf('document.cookie = "__test="') === -1 &&
            text.indexOf('/aes.js') === -1 &&
            text.indexOf('?i=1') === -1;
    } catch (_) {
        return false;
    }
}

async function _getRemoteBuildVersion() {
    try {
        const resp = await fetch('/version.php', { cache: 'no-store' });
        if (!resp.ok) return null;
        const data = await resp.json();
        return data && data.version ? data.version : null;
    } catch (err) {
        return null;
    }
}

// ── BACKGROUND SYNC ───────────────────────────────────────────
self.addEventListener('sync', e => {
    if (e.tag === SYNC_TAG) e.waitUntil(_flushQueue());
});

// ── PERIODIC SYNC ─────────────────────────────────────────────
self.addEventListener('periodicsync', e => {
    if (e.tag === PERIODIC_SYNC_TAG) {
        e.waitUntil(Promise.all([
            _checkNewRegistrations(),
            _flushQueue()
        ]));
    }
});

// ── PUSH ─────────────────────────────────────────────────────
self.addEventListener('push', e => {
    if (!e.data) return;
    let d;
    try { d = e.data.json(); } catch(_) { d = { title: 'مدارس الأحد', body: e.data.text() }; }

    const isReg = d.type === 'registration';
    const isDevMsg = d.type === 'developer_message';
    const isOtp = d.type === 'whatsapp_otp' || d.notifType === 'whatsapp_otp';
    const isTask = d.type === 'task_submission' || d.notifType === 'task_submission' || d.type === 'task';
    const targetUrl = d.redirect_url || d.url || (isOtp ? '/uncle/dashboard/?open_otp=1' : (isTask && d.className ? `/uncle/dashboard/tasks?class=${encodeURIComponent(d.className)}` : '/uncle/dashboard/'));

    const options = {
        body: d.body || '',
        icon: d.icon || '/logo.png',
        badge: '/logo.png',
        dir: 'rtl', lang: 'ar',
        tag: d.tag || d.type || 'general',
        renotify: true,
        requireInteraction: isReg || (isDevMsg && !!d.button_text) || isOtp || isTask,
        data: { url: targetUrl, type: d.type || d.notifType, className: d.className || d.class_name, otp: d.otp_code }
    };
    if (isReg) {
        options.actions = [
            { action: 'open', title: 'عرض الطلب' },
            { action: 'dismiss', title: 'إغلاق' }
        ];
    } else if (isTask) {
        options.actions = [
            { action: 'open', title: 'فتح التاسك' },
            { action: 'dismiss', title: 'إغلاق' }
        ];
    } else if (isDevMsg && d.button_text) {
        options.actions = [
            { action: 'open', title: d.button_text },
            { action: 'dismiss', title: 'إغلاق' }
        ];
    } else if (isOtp) {
        options.actions = [
            { action: 'open', title: 'عرض الكود' },
            { action: 'dismiss', title: 'إغلاق' }
        ];
    }
    e.waitUntil(Promise.all([
        self.registration.showNotification(d.title || 'مدارس الأحد', options),
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clients => {
            clients.forEach(client => {
                client.postMessage({ type: 'PUSH_NOTIFICATION_RECEIVED', data: d });
            });
        })
    ]));
});

// ── NOTIFICATION CLICK ────────────────────────────────────────
self.addEventListener('notificationclick', e => {
    e.notification.close();
    const { url, type, className } = e.notification.data || {};
    if (e.action === 'dismiss') return;
    
    const isUserPath = url && url.includes('/user/');
    const targetSubstring = isUserPath ? '/user/' : '/uncle/';
    const defaultUrl = isUserPath ? '/user/' : '/uncle/dashboard/';
    const finalUrl = url || defaultUrl;

    e.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
            const open = list.find(c => c.url.includes(targetSubstring));
            if (open) { 
                open.focus(); 
                if (finalUrl && (type === 'task_submission' || type === 'task')) {
                    try { open.navigate(finalUrl); } catch (_) { }
                }
                open.postMessage({ type: 'NOTIFICATION_CLICK', notifType: type, className, url: finalUrl }); 
            } else {
                self.clients.openWindow(finalUrl);
            }
        })
    );
});

// ── MESSAGE from page ─────────────────────────────────────────
self.addEventListener('message', e => {
    if (e.data?.type === 'SET_UNCLE_META') {
        _idbGetMeta().then(existing => {
            const merged = { ...existing, ...e.data.meta };
            return _idbSetMeta(merged);
        }).catch(() => {});
    }
    if (e.data?.type === 'FLUSH_QUEUE') {
        _flushQueue().catch(() => {});
    }
    if (e.data?.type === 'PRECACHE_OFFLINE_DATA' || e.data?.type === 'VERIFY_AND_PRECACHE_DATA') {
        if (self.navigator.onLine) {
            _verifyAndPrecacheShellData().catch(() => {});
        }
    }
});

async function _verifyAndPrecacheShellData() {
    if (!self.navigator.onLine) return;
    try {
        const cache = await caches.open(CACHE_NAME);
        for (const url of SHELL_URLS) {
            try {
                const match = await cache.match(url, { ignoreSearch: true });
                if (!match) {
                    const response = await fetch(url, { cache: 'no-store' });
                    if (response && response.ok && await _isCacheableAppResponse(response, null)) {
                        await cache.put(url, response.clone());
                    }
                }
            } catch (_) {}
        }
    } catch (_) {}
}

// ── SELECTIVE QUEUE ──────────────────────────────────────────
// Reads the POST body to decide if this action should be queued.
// Returns true if queued, false if not (caller shows generic offline error).
async function _maybeQueueRequest(req) {
    try {
        const bodyText = await req.text();
        // Parse FormData-encoded body to find the 'action' field
        const action = _parseFormField(bodyText, 'action');
        if (!QUEUEABLE_ACTIONS.includes(action)) return false;

        const db = await _openDB();
        await new Promise((resolve, reject) => {
            if (!db || !db.objectStoreNames.contains('queue')) return reject('No queue store');
            const tx = db.transaction('queue', 'readwrite');
            const store = tx.objectStore('queue');
            store.add({
                url: req.url, method: req.method, body: bodyText,
                headers: [...req.headers.entries()], ts: Date.now(), action
            });
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        });
        if (self.registration.sync) self.registration.sync.register(SYNC_TAG).catch(() => {});
        return true;
    } catch(err) { return false; }
}

// Extracts a field value from a URL-encoded or multipart FormData string
function _parseFormField(body, field) {
    try {
        if (!body) return '';
        // If it's multipart/form-data
        if (body.includes('Content-Disposition: form-data;')) {
            const escapedField = field.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&');
            const regex = new RegExp('name="' + escapedField + '"(?:\\r?\\n)+([^\\r\\n]+)', 'i');
            const match = body.match(regex);
            return match ? match[1].trim() : '';
        }
        // Fallback to URL-encoded query string
        const params = new URLSearchParams(body);
        return params.get(field) || '';
    } catch(_) { return ''; }
}

// ── FLUSH QUEUE ───────────────────────────────────────────────
async function _flushQueue() {
    try {
        const db = await _openDB();
        if (!db || !db.objectStoreNames.contains('queue')) return;
        const records = await _idbGetAll(db.transaction('queue', 'readonly').objectStore('queue'));
        if (!records.length) return;

    let flushed = 0;
    for (const rec of records) {
        try {
            const r = await fetch(rec.url, {
                method: rec.method,
                body: rec.body,
                headers: new Headers(rec.headers || [])
            });
            if (r.ok) {
                db.transaction('queue', 'readwrite').objectStore('queue').delete(rec.id);
                flushed++;
            }
        } catch(err) {}
    }

    if (!flushed) return;

    const uncleMeta = await _idbGetMeta() || {};
    const uncleName = uncleMeta.uncleName || '';

    // Tell open windows (they show a toast)
    const allClients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    allClients.forEach(c => c.postMessage({ type: 'SYNC_COMPLETE', count: flushed, uncleName }));

    // Show system notification if app is closed or in the background (no visible tabs)
    const hasVisibleTabs = allClients.some(c => c.visibilityState === 'visible');
    if (!hasVisibleTabs) {
        const title = `تم حفظ التغييرات بنجاح ✅`;
        self.registration.showNotification(title, {
            body: `تم رفع ${flushed} تغيير محفوظ محلياً بنجاح للمستخدم / ${uncleName || 'الخادم'}`,
            icon: '/logo.png', badge: '/logo.png',
            dir: 'rtl', lang: 'ar',
            tag: 'sync-complete', renotify: true,
            data: { url: '/uncle/dashboard/', type: 'sync' }
        }).catch(() => {});
    }
    } catch(err) {}
}

// ── BACKGROUND REGISTRATION CHECK ────────────────────────────
async function _checkNewRegistrations() {
    try {
        const meta = await _idbGetMeta();
        if (!meta?.apiUrl || !meta?.uncleId) return;

        const fd = new FormData();
        fd.append('action', 'getPendingRegistrationsCount');
        fd.append('uncle_id', meta.uncleId);
        const resp = await fetch(meta.apiUrl, { method: 'POST', body: fd });
        if (!resp.ok) return;
        const data = await resp.json();

        const newCount  = parseInt(data.count  || 0);
        const prevCount = parseInt(meta.lastRegCount || 0);

        if (newCount > prevCount) {
            const diff = newCount - prevCount;
            await self.registration.showNotification('مدارس الأحد — طلب تسجيل جديد 📋', {
                body: diff === 1 ? 'طلب تسجيل جديد بانتظار مراجعتك'
                                 : `${diff} طلبات تسجيل جديدة بانتظار مراجعتك`,
                icon: '/logo.png', badge: '/logo.png',
                dir: 'rtl', lang: 'ar',
                tag: 'registration', renotify: true, requireInteraction: true,
                actions: [
                    { action: 'open',    title: 'عرض الطلبات' },
                    { action: 'dismiss', title: 'إغلاق' }
                ],
                data: { url: '/uncle/dashboard/', type: 'registration' }
            }).catch(() => {});
        }
        await _idbSetMeta({ ...meta, lastRegCount: newCount });
    } catch(err) {}
}

// ── PERIODIC SYNC REGISTRATION ────────────────────────────────
async function _registerPeriodicSync() {
    try {
        if ('periodicSync' in self.registration) {
            await self.registration.periodicSync.register(PERIODIC_SYNC_TAG, {
                minInterval: 15 * 60 * 1000
            });
        }
    } catch(err) {}
}

// ── IDB helpers ───────────────────────────────────────────────
function _openDB() {
    return new Promise((res, rej) => {
        const req = indexedDB.open('ss-queue', 3);
        req.onupgradeneeded = ev => {
            const db = ev.target.result;
            if (!db.objectStoreNames.contains('queue'))
                db.createObjectStore('queue', { keyPath: 'id', autoIncrement: true });
            if (!db.objectStoreNames.contains('meta'))
                db.createObjectStore('meta', { keyPath: 'key' });
        };
        req.onsuccess = ev => res(ev.target.result);
        req.onerror   = ev => rej(ev);
    });
}

function _idbGetAll(store) {
    return new Promise((res, rej) => {
        const req = store.getAll();
        req.onsuccess = e => res(e.target.result || []);
        req.onerror   = e => rej(e);
    });
}

async function _idbGetMeta() {
    try {
        const db = await _openDB();
        if (!db || !db.objectStoreNames.contains('meta')) return null;
        return await new Promise((res, rej) => {
            const req = db.transaction('meta', 'readonly').objectStore('meta').get('uncle');
            req.onsuccess = e => res(e.target.result?.value || null);
            req.onerror   = e => rej(e);
        });
    } catch(err) { return null; }
}

async function _idbSetMeta(value) {
    try {
        const db = await _openDB();
        if (!db || !db.objectStoreNames.contains('meta')) return;
        return new Promise((res, rej) => {
            const tx = db.transaction('meta', 'readwrite');
            tx.objectStore('meta').put({ key: 'uncle', value });
            tx.oncomplete = res;
            tx.onerror    = rej;
        });
    } catch(err) {}
}
