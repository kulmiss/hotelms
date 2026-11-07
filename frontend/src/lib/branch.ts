import { useSyncExternalStore } from 'react'
import type { Me } from './api'

const KEY = 'hms-branch'

/* One branch choice for the whole app (top bar, room board, walk-in, housekeeping…), remembered on this device. */
let chosen: number | null = read()
const listeners = new Set<() => void>()

function read(): number | null {
  try {
    const saved = Number(localStorage.getItem(KEY))
    return Number.isFinite(saved) && saved > 0 ? saved : null
  } catch {
    return null
  }
}

export function chooseBranch(id: number | null) {
  if (id === chosen) return
  chosen = id
  try {
    if (id) localStorage.setItem(KEY, String(id))
  } catch {
    // ignore (private mode)
  }
  listeners.forEach((l) => l())
}

function subscribe(l: () => void) {
  listeners.add(l)
  return () => listeners.delete(l)
}

/** The branch the user is working in: the same everywhere on screen. */
export function useCurrentBranch(me: Me | null | undefined) {
  const branches = me?.branches ?? []
  const selected = useSyncExternalStore(subscribe, () => chosen, () => chosen)
  const current = branches.find((b) => b.id === selected) ?? branches[0] ?? null

  return { branches, current, select: chooseBranch }
}
