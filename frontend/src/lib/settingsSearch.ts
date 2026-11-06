import type { TFunction } from 'i18next'
import { useMe } from '@/lib/auth'
import { useCan } from '@/lib/hotel'

/** Settings sections, each guarded by its permission (permissions.md §10). */
export const SETTINGS_SECTIONS = [
  { key: 'profile', permission: 'settings.hotel' },
  { key: 'branches', permission: 'settings.branches' },
  { key: 'room-types', permission: 'settings.rooms' },
  { key: 'rooms', permission: 'settings.rooms' },
  { key: 'import', permission: 'settings.rooms' },
  { key: 'rate-rules', permission: 'settings.pricing' },
  { key: 'guest-types', permission: 'settings.pricing' },
  { key: 'currencies', permission: 'settings.exchange_rates' },
  { key: 'taxes', permission: 'settings.money' },
  { key: 'payment-methods', permission: 'settings.money' },
  { key: 'charges', permission: 'settings.charges' },
  { key: 'booking-rules', permission: 'settings.policies' },
  { key: 'cancellation', permission: 'settings.policies' },
  { key: 'modules', permission: 'settings.modules' },
  { key: 'attendance', permission: 'settings.attendance', module: 'attendance' },
  { key: 'automations', permission: 'automations.manage' },
  { key: 'security', permission: 'settings.hotel' },
  { key: 'subscription', permission: 'subscription.view' },
] as const

/** The settings sections this person may open (their permission, and the module is on). */
export function useSettingsSections() {
  const can = useCan()
  const me = useMe()
  const modules = me.data?.hotel?.modules ?? []
  return SETTINGS_SECTIONS.filter((s) => can(s.permission) && (!('module' in s) || modules.includes(s.module)))
}

/**
 * Settings search: every section is found by its name and by the labels of the settings inside it.
 * The labels come straight from the translation file, so a new setting is searchable without extra work.
 */
const SOURCES: Record<string, string[]> = {
  profile: ['settings.profile'],
  branches: ['settings.branches'],
  'room-types': ['settings.roomTypes'],
  rooms: ['settings.rooms'],
  import: ['import'],
  'rate-rules': ['settings.rates'],
  'guest-types': ['settings.guestTypes'],
  currencies: ['settings.currencies'],
  taxes: ['settings.taxes'],
  'payment-methods': ['settings.payments'],
  charges: ['settings.charges'],
  'booking-rules': ['settings.rules'],
  cancellation: ['settings.cancellation'],
  modules: ['settings.modules', 'modules'],
  attendance: ['settings.attendance'],
  automations: ['automations.items', 'automations.quiet'],
  security: ['settings.security'],
  subscription: ['settings.subscription'],
}

/** Extra words people type that are not on the screen. */
const SYNONYMS: Record<string, string> = {
  profile: 'logo name tin vat number invoice address phone email footer paper receipt a4 80mm',
  'booking-rules': 'deposit walk-in early late check-in check-out hourly cleaning expire',
  taxes: 'vat service charge tax',
  currencies: 'usd dollar exchange rate money',
  'payment-methods': 'cash telebirr cbe birr bank card mobile money',
  security: 'two-step 2fa password lock pin devices backup export download',
  automations: 'telegram reminder notification bell quiet hours rule',
  modules: 'housekeeping cashier shifts attendance features',
  subscription: 'plan pay paid until billing',
  'rate-rules': 'price weekend season discount corporate',
  cancellation: 'no-show refund policy fee',
}

export type SettingsHit = { section: string; label: string; find?: string }

/** Short, label-like texts only (no sentences with placeholders). */
function labels(value: unknown, out: string[] = []): string[] {
  if (typeof value === 'string') {
    if (value.length <= 60 && !value.includes('{{')) out.push(value)
  } else if (value && typeof value === 'object') {
    for (const v of Object.values(value)) labels(v, out)
  }
  return out
}

const norm = (s: string) => s.toLowerCase().normalize('NFKD').replace(/[^\p{L}\p{N}]+/gu, ' ').trim()

/** Matches for a search term within the sections this person may open (best first, at most `limit`). */
export function searchSettings(t: TFunction, allowed: string[], term: string, limit = 8): SettingsHit[] {
  const q = norm(term)
  if (q.length < 2) return []
  const words = q.split(' ')
  const hits: (SettingsHit & { score: number })[] = []
  for (const section of allowed) {
    const title = t(`settings.sections.${section}`)
    const nt = norm(title)
    if (words.every((w) => nt.includes(w))) hits.push({ section, label: title, score: nt.startsWith(q) ? 0 : 1 })
    else if (words.every((w) => norm(SYNONYMS[section] ?? '').includes(w))) hits.push({ section, label: title, score: 3 })
    const seen = new Set<string>()
    for (const ns of SOURCES[section] ?? []) {
      for (const label of labels(t(ns, { returnObjects: true }))) {
        const nl = norm(label)
        if (seen.has(nl) || nl === nt || !words.every((w) => nl.includes(w))) continue
        seen.add(nl)
        hits.push({ section, label, find: label, score: nl.startsWith(q) ? 1 : 2 })
      }
    }
  }
  return hits
    .sort((a, b) => a.score - b.score || a.label.length - b.label.length)
    .slice(0, limit)
    .map((h) => ({ section: h.section, label: h.label, find: h.find }))
}

/** Where a hit opens: the section, with the setting to highlight. */
export const settingsLink = (h: SettingsHit) => `/settings/${h.section}${h.find ? `?find=${encodeURIComponent(h.find)}` : ''}`
