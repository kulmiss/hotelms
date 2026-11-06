import { useSyncExternalStore } from 'react'

/** A new version of the app is ready (waiting service worker). */
let waiting: ServiceWorker | null = null
const listeners = new Set<() => void>()
const notify = () => listeners.forEach((l) => l())

/** Registers the offline copy of the app (production only). */
export function registerServiceWorker(): void {
  if (!import.meta.env.PROD || !('serviceWorker' in navigator)) return
  window.addEventListener('load', () => {
    navigator.serviceWorker
      .register(`/sw.js?v=${encodeURIComponent(__APP_BUILD__)}`)
      .then((reg) => {
        const track = (sw: ServiceWorker | null) => {
          if (!sw) return
          sw.addEventListener('statechange', () => {
            if (sw.state === 'installed' && navigator.serviceWorker.controller) {
              waiting = sw
              notify()
            }
          })
        }
        if (reg.waiting && navigator.serviceWorker.controller) {
          waiting = reg.waiting
          notify()
        }
        reg.addEventListener('updatefound', () => track(reg.installing))
        // Look for a new version every 30 minutes (long reception shifts never reload).
        window.setInterval(() => void reg.update().catch(() => undefined), 30 * 60_000)
      })
      .catch(() => undefined)
    let reloading = false
    navigator.serviceWorker.addEventListener('controllerchange', () => {
      if (reloading) return
      reloading = true
      window.location.reload()
    })
  })
}

export function useUpdateReady(): boolean {
  return useSyncExternalStore(
    (l) => {
      listeners.add(l)
      return () => listeners.delete(l)
    },
    () => waiting !== null,
  )
}

/** "Refresh": switch to the new version now. */
export function applyUpdate(): void {
  if (waiting) waiting.postMessage('skipWaiting')
  else window.location.reload()
}
