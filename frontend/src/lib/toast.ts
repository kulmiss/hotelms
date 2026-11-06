import { useSyncExternalStore } from 'react'

/** Mutation meta: no "Saved" toast (sign-in steps, previews, things with their own message). */
export const SILENT = { toast: false } as const
/** Mutation meta: "Removed." instead of "Saved." */
export const REMOVED = { toast: 'common.removedToast' } as const

export type Toast = { id: number; message: string; tone: 'ok' | 'info' | 'danger' }

let items: Toast[] = []
let next = 1
const listeners = new Set<() => void>()
const emit = () => listeners.forEach((l) => l())

/** Removes a toast (also done automatically after a few seconds). */
export function dismissToast(id: number) {
  items = items.filter((t) => t.id !== id)
  emit()
}

/** A short message at the corner of the screen, e.g. "Demo hotel reset". Closes by itself. */
export function toast(message: string, tone: Toast['tone'] = 'ok', ms = 4000): number {
  const id = next++
  items = [...items.slice(-2), { id, message, tone }] // at most 3 at once
  emit()
  window.setTimeout(() => dismissToast(id), ms)
  return id
}

export function useToasts(): Toast[] {
  return useSyncExternalStore(
    (l) => {
      listeners.add(l)
      return () => listeners.delete(l)
    },
    () => items,
    () => items,
  )
}
