/*
 * Offline support (decisions.md W6): the app itself (HTML, scripts, styles) is kept on the device
 * so it opens without internet. Hotel data is NOT cached here: the app keeps a small read-only
 * copy itself (src/lib/offline.ts), only for signed-in devices, wiped on sign-out.
 * A new version waits until the person taps "Refresh" (no surprise reloads mid-work).
 */
const VERSION = new URL(self.location.href).searchParams.get('v') || 'dev'
const CACHE = `hms-app-${VERSION}`
const SHELL = '/'

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.add(new Request(SHELL, { cache: 'reload' }))))
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith('hms-app-') && k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()),
  )
})

self.addEventListener('message', (event) => {
  if (event.data === 'skipWaiting') self.skipWaiting()
})

self.addEventListener('fetch', (event) => {
  const req = event.request
  if (req.method !== 'GET') return
  const url = new URL(req.url)
  if (url.origin !== self.location.origin) return
  // Hotel data, sign-in and uploads always go to the server.
  if (/^\/(api|sanctum|storage|up)(\/|$)/.test(url.pathname)) return

  // Pages: the newest from the server; without internet, the saved app.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req)
        .then((res) => {
          // The public website pages (/, /terms, /privacy) are not the app: never save them as the offline app.
          if (res.ok && !/^\/(terms|privacy)?$/.test(url.pathname)) {
            const copy = res.clone()
            void caches.open(CACHE).then((c) => c.put(SHELL, copy))
          }
          return res
        })
        .catch(() => caches.match(SHELL).then((r) => r || Response.error())),
    )
    return
  }

  // Built files have a hash in their name: once saved, always right.
  if (url.pathname.startsWith('/assets/') || /\.(svg|png|webp|woff2?)$/.test(url.pathname)) {
    event.respondWith(
      caches.match(req).then(
        (hit) =>
          hit ||
          fetch(req).then((res) => {
            if (res.ok) {
              const copy = res.clone()
              void caches.open(CACHE).then((c) => c.put(req, copy))
            }
            return res
          }),
      ),
    )
  }
})
