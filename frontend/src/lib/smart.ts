import { useCallback, useSyncExternalStore } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api } from './api'

/** A ✨ Smart hint (smart-features.md): a sentence, why, and maybe a link or data the screen can use. */
export type SmartHint = { key: string; text: string; why: string; tone: 'info' | 'ok' | 'warn'; link: string | null; data?: Record<string, unknown> }

export function useSmart(place: string, params: Record<string, string | number | null | undefined> = {}, enabled = true) {
  const qs = new URLSearchParams()
  Object.entries(params).forEach(([k, v]) => v !== null && v !== undefined && v !== '' && qs.set(k, String(v)))
  const s = qs.toString()
  return useQuery({
    queryKey: ['smart', place, s],
    queryFn: () => api<{ data: SmartHint[] }>(`/smart/${place}${s ? `?${s}` : ''}`),
    enabled,
    staleTime: 60_000,
    retry: false,
    placeholderData: keepPreviousData,
  })
}

/* Dismissed hints are remembered on this device for a week (by their text, so a new situation shows again). */
const KEY = 'hms-smart-dismissed'
const WEEK = 7 * 24 * 3600 * 1000
const listeners = new Set<() => void>()
let cache: Record<string, number> | null = null

function read(): Record<string, number> {
  if (cache) return cache
  try {
    const all = JSON.parse(localStorage.getItem(KEY) ?? '{}') as Record<string, number>
    const now = Date.now()
    cache = Object.fromEntries(Object.entries(all).filter(([, at]) => now - at < WEEK))
  } catch {
    cache = {}
  }
  return cache
}

/** For tests: forget what was dismissed. */
export function forgetDismissed() {
  cache = null
}

export const hintId = (h: SmartHint) => `${h.key}:${h.text}`

export function useDismissed() {
  const map = useSyncExternalStore(
    (l) => {
      listeners.add(l)
      return () => listeners.delete(l)
    },
    read,
    read,
  )
  const dismiss = useCallback((h: SmartHint) => {
    cache = { ...read(), [hintId(h)]: Date.now() }
    try {
      localStorage.setItem(KEY, JSON.stringify(cache))
    } catch {
      // private mode: hidden for this visit only
    }
    listeners.forEach((l) => l())
  }, [])
  return { isDismissed: (h: SmartHint) => hintId(h) in map, dismiss }
}

/** Ethiopian phone check (no server needed): +251 9xx… / 09xx… mobile, or +251 11… landline. */
export function phoneLooksWrong(phone: string): boolean {
  const d = phone.replace(/[^\d+]/g, '')
  if (d.length < 4) return false
  if (d.startsWith('+') && !d.startsWith('+251')) return false // a foreign number: not checked
  const local = d.replace(/^\+251/, '0').replace(/^251/, '0')
  return !/^0(9|7)\d{8}$/.test(local) && !/^0(1[1-9]|2[2-9]|3[3-9]|4[4-9]|5[5-9])\d{7}$/.test(local)
}
