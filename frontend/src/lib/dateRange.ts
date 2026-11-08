import type { TFunction } from 'i18next'
import { date } from './format'
import { addDays, today } from './front'

/** A date range in hotel days ("2026-11-01"), both ends included. Empty = any time. */
export type DateRange = { from?: string; to?: string }

export const DATE_PRESETS = ['any', 'today', 'yesterday', 'thisWeek', 'thisMonth', 'lastMonth', 'custom'] as const
export type DatePreset = (typeof DATE_PRESETS)[number]

/** The dates of a quick choice, counted from `now` (weeks start on Monday). */
export function presetRange(preset: DatePreset, now: string = today()): DateRange {
  const [y, m] = now.split('-').map(Number)
  const monthStart = (yy: number, mm: number) => `${yy}-${String(mm).padStart(2, '0')}-01`
  switch (preset) {
    case 'today':
      return { from: now, to: now }
    case 'yesterday':
      return { from: addDays(now, -1), to: addDays(now, -1) }
    case 'thisWeek': {
      const weekday = (new Date(`${now}T12:00:00Z`).getUTCDay() + 6) % 7 // Monday = 0
      return { from: addDays(now, -weekday), to: now }
    }
    case 'thisMonth':
      return { from: monthStart(y, m), to: now }
    case 'lastMonth': {
      const first = m === 1 ? monthStart(y - 1, 12) : monthStart(y, m - 1)
      return { from: first, to: addDays(monthStart(y, m), -1) }
    }
    default:
      return {}
  }
}

/** Which quick choice a range is (custom when it matches none). */
export function presetOf(range: DateRange, now: string = today()): DatePreset {
  if (!range.from && !range.to) return 'any'
  return DATE_PRESETS.find((p) => p !== 'any' && p !== 'custom' && presetRange(p, now).from === range.from && presetRange(p, now).to === range.to) ?? 'custom'
}

/** "This month", or "1 Nov 2026 – 5 Nov 2026" for own dates. */
export function rangeText(t: TFunction, range: DateRange): string {
  const p = presetOf(range)
  if (p !== 'custom') return t(`dates.${p}`)
  return `${range.from ? date(range.from) : '…'} – ${range.to ? date(range.to) : '…'}`
}

/** The chip for a date filter that is on (phones and tablets, P19): "Stayed: This month". None for any time. */
export function rangeChip(t: TFunction, key: string, label: string, range: DateRange, onRemove: () => void) {
  return range.from || range.to ? [{ key, label: `${label}: ${rangeText(t, range)}`, onRemove }] : []
}
