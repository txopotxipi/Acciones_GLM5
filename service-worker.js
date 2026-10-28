// service-worker.js — Cartera Pro GLM5 PWA Service Worker (v16)
// v16: CDN de Chart.js y su adapter fijados a versión exacta (antes "/npm/chart.js"
// flotaba a "latest" y un mayor a v5 rompería la app en silencio).
const CACHE_NAME = 'cartera-pro-glm5-v16';
const STATIC_ASSETS = [
    './favicon.ico',
    './icon-192.png',
    './icon-512.png',
    'https://cdn.jsdelivr.net/npm/chart.js@4.6.1/dist/chart.umd.min.js',
    'https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js',
    'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap'
];

self.addEventListener('install', function(event) {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then(function(cache) {
            return cache.addAll(STATIC_ASSETS);
        })
    );
});

self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(names) {
            return Promise.all(
                names.filter(function(n) { return n !== CACHE_NAME; })
                     .map(function(n) { return caches.delete(n); })
            );
        }).then(function() {
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', function(event) {
    const req = event.request;
    const url = req.url;

    // NUNCA cachear navegación de páginas (HTML/PHP/raíz), peticiones dinámicas ni APIs
    if (req.mode === 'navigate' ||
        req.method !== 'GET' ||
        url.includes('.php') ||
        url.includes('quick_update') ||
        url.includes('perplexity_report') ||
        url.includes('api.php') ||
        url.includes('yahoo.com') ||
        url.endsWith('/Acciones_GML5/') ||
        url.endsWith('/Acciones_GML5')) {
        return; // Pasa directo a la red (Network-Only)
    }

    // Network-First para scripts y estilos CSS con fallback a caché
    if (url.includes('.js') || url.includes('.css')) {
        event.respondWith(
            fetch(req).then(function(networkResponse) {
                if (networkResponse && networkResponse.status === 200) {
                    const resClone = networkResponse.clone();
                    caches.open(CACHE_NAME).then(function(cache) {
                        cache.put(req, resClone);
                    });
                }
                return networkResponse;
            }).catch(function() {
                return caches.match(req);
            })
        );
        return;
    }

    // Cache-First para imágenes y fuentes
    event.respondWith(
        caches.match(req).then(function(cachedResponse) {
            if (cachedResponse) return cachedResponse;
            return fetch(req).then(function(networkResponse) {
                if (networkResponse && networkResponse.status === 200) {
                    const resClone = networkResponse.clone();
                    caches.open(CACHE_NAME).then(function(cache) {
                        cache.put(req, resClone);
                    });
                }
                return networkResponse;
            });
        })
    );
});
