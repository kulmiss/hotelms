import { useCallback, useSyncExternalStore } from 'react'

/** Front desk mode (decisions.md W5): each receptionist turns it on for themselves on this device. */
const key = (userId: number) => `hms-front-desk-mode-${userId}`
const listeners = new Set<() => void>()

function read(userId: number | undefined): boolean {
  if (!userId) return false
  try {
    return localStorage.getItem(key(userId)) === '1'
  } catch {
    return false
  }
}

export function useFrontDeskMode(userId: number | undefined) {
  const on = useSyncExternalStore(
    (l) => {
      listeners.add(l)
      return () => listeners.delete(l)
    },
    () => read(userId),
    () => false,
  )
  const set = useCallback(
    (value: boolean) => {
      if (!userId) return
      try {
        if (value) localStorage.setItem(key(userId), '1')
        else localStorage.removeItem(key(userId))
      } catch {
        // private mode: works for this visit only
      }
      listeners.forEach((l) => l())
    },
    [userId],
  )
  return [on, set] as const
}
