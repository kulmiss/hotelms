import { onlineManager, type QueryClient, type QueryKey } from '@tanstack/react-query'
import { useSyncExternalStore } from 'react'

/**
 * Read-only offline copy for reception (decisions.md W6): who you are, today's arrivals and
 * departures, in-house guests (with phone numbers) and the room board. Kept only on this device
 * for the signed-in person, and wiped on sign-out. Nothing can be changed while offline.
 */
const PREFIX = 'hms_offline:'
const KEEP: QueryKey[] = [['me'], ['desk', 'board'], ['desk', 'today'], ['branding']]
const MAX_BYTES = 1_500_000

type Saved = { key: QueryKey; data: unknown; at: number }

const matches = (key: QueryKey) => KEEP.some((k) => k.every((part, i) => key[i] === part))
const storageKey = (userId: number) => `${PREFIX}${userId}`

function read(userId: number): Saved[] {
  try {
    return JSON.parse(localStorage.getItem(storageKey(userId)) ?? '[]') as Saved[]
  } catch {
    return []
  }
}

/** Puts the saved copy back into the app (only for the person who saved it), then keeps it up to date. */
export function startOfflineCopy(client: QueryClient): () => void {
  let userId: number | null = null
  try {
    userId = Number(localStorage.getItem(`${PREFIX}user`)) || null
  } catch {
    userId = null
  }
  if (userId) {
    for (const s of read(userId)) {
      if (client.getQueryData(s.key) === undefined) client.setQueryData(s.key, s.data, { updatedAt: s.at })
    }
  }

  let timer: number | undefined
  const save = () => {
    window.clearTimeout(timer)
    timer = window.setTimeout(() => {
      const me = client.getQueryData<{ user?: { id: number } } | null>(['me'])
      const id = me?.user?.id
      if (!id) return
      const saved: Saved[] = client
        .getQueryCache()
        .getAll()
        .filter((q) => matches(q.queryKey) && q.state.status === 'success' && q.state.data !== undefined && q.state.data !== null)
        .map((q) => ({ key: q.queryKey, data: q.state.data, at: q.state.dataUpdatedAt }))
      try {
        const json = JSON.stringify(saved)
        if (json.length > MAX_BYTES) return
        // Only ever one person's copy on a device.
        Object.keys(localStorage)
          .filter((k) => k.startsWith(PREFIX) && k !== storageKey(id))
          .forEach((k) => localStorage.removeItem(k))
        localStorage.setItem(storageKey(id), json)
        localStorage.setItem(`${PREFIX}user`, String(id))
      } catch {
        /* storage full or private mode: the app still works online */
      }
    }, 1000)
  }

  return client.getQueryCache().subscribe((event) => {
    if (event.type === 'updated' && event.action.type === 'success' && matches(event.query.queryKey)) save()
  })
}

/** Sign-out (or another person signs in): nothing of the hotel stays on this device. */
export function wipeOfflineCopy(): void {
  try {
    Object.keys(localStorage)
      .filter((k) => k.startsWith(PREFIX))
      .forEach((k) => localStorage.removeItem(k))
  } catch {
    /* nothing saved */
  }
}

export function useOnline(): boolean {
  return useSyncExternalStore(
    (l) => onlineManager.subscribe(l),
    () => onlineManager.isOnline(),
    () => true,
  )
}
