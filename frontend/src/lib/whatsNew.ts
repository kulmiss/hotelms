import { useSyncExternalStore } from 'react'
import { useWhatsNew } from './platform'

/** Which "What's new" note this person has seen (per user, on this device). */
const key = (userId: number) => `hms-whats-new-seen-${userId}`
const listeners = new Set<() => void>()

function seen(userId: number | undefined): number {
  if (!userId) return 0
  try {
    return Number(localStorage.getItem(key(userId)) ?? 0)
  } catch {
    return 0
  }
}

export function markWhatsNewSeen(userId: number, releaseId: number) {
  try {
    localStorage.setItem(key(userId), String(releaseId))
  } catch {
    // private mode
  }
  listeners.forEach((l) => l())
}

/** True when a note was published after the last one this person opened. */
export function useHasWhatsNew(userId: number | undefined): boolean {
  const q = useWhatsNew()
  const last = useSyncExternalStore(
    (l) => {
      listeners.add(l)
      return () => listeners.delete(l)
    },
    () => seen(userId),
    () => 0,
  )
  const newest = q.data?.data[0]?.id ?? 0
  return newest > last
}
