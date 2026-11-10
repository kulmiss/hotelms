import { useSyncExternalStore } from 'react'

/** Below 768px the sidebar opens as a drawer (template breakpoint). */
const MOBILE_QUERY = '(max-width: 767px)'

export function useIsMobile() {
  return useSyncExternalStore(
    (callback) => {
      const mql = window.matchMedia(MOBILE_QUERY)
      mql.addEventListener('change', callback)
      return () => mql.removeEventListener('change', callback)
    },
    () => window.matchMedia(MOBILE_QUERY).matches,
    () => false,
  )
}
