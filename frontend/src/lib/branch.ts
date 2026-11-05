import { useEffect, useState } from 'react'
import type { Me } from './api'

const KEY = 'hms-branch'

/** The branch the user is working in (remembered on this device). */
export function useCurrentBranch(me: Me | null | undefined) {
  const branches = me?.branches ?? []
  const [selected, setSelected] = useState<number | null>(() => {
    try {
      const saved = Number(localStorage.getItem(KEY))
      return Number.isFinite(saved) && saved > 0 ? saved : null
    } catch {
      return null
    }
  })

  const current = branches.find((b) => b.id === selected) ?? branches[0] ?? null

  useEffect(() => {
    try {
      if (current) localStorage.setItem(KEY, String(current.id))
    } catch {
      // ignore
    }
  }, [current])

  return { branches, current, select: setSelected }
}
