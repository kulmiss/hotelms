import { useSyncExternalStore } from 'react'

/** True while the CSS media query matches (e.g. a phone-sized screen). */
export function useMediaQuery(query: string): boolean {
  return useSyncExternalStore(
    (cb) => {
      const mq = window.matchMedia?.(query)
      mq?.addEventListener?.('change', cb)
      return () => mq?.removeEventListener?.('change', cb)
    },
    () => !!window.matchMedia?.(query).matches,
    () => false,
  )
}
