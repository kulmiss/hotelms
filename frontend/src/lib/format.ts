/** Formats a money string ("4662.50") for display without float maths. */
export function money(amount: string | number | null | undefined, currency = 'ETB'): string {
  if (amount === null || amount === undefined || amount === '') return '—'
  const [whole, cents = '00'] = String(amount).split('.')
  const sign = whole.startsWith('-') ? '-' : ''
  const digits = whole.replace('-', '').replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  return `${sign}${currency} ${digits}.${cents.padEnd(2, '0').slice(0, 2)}`
}

import type { CalendarMode } from './api'
import { formatEthiopian } from './ethiopian'

/**
 * Which calendar dates are shown in (the person's choice, else the hotel's; set when /me loads).
 * Gregorian only / Ethiopian only / both (owner, 14 Nov 2026). The server always stores Gregorian.
 */
/** Gregorian months in Amharic (short forms used in Ethiopia). */
const AM_MONTHS = ['ጃንዩ', 'ፌብሩ', 'ማርች', 'ኤፕሪ', 'ሜይ', 'ጁን', 'ጁላይ', 'ኦገስ', 'ሴፕቴ', 'ኦክቶ', 'ኖቬም', 'ዲሴም']

let calendar: CalendarMode = 'gregorian'
let calendarLang = 'en'
export function setCalendarMode(mode: CalendarMode | null | undefined, lang?: string) {
  calendar = mode === 'ethiopian' || mode === 'both' ? mode : 'gregorian'
  if (lang) calendarLang = lang === 'am' ? 'am' : 'en'
}
export const calendarMode = () => calendar

/** "2026-11-30" → "30 Nov 2026" (dates stay as written; no timezone shifts). */
export function gregorianDate(value: string): string {
  const [y, m, d] = value.slice(0, 10).split('-').map(Number)
  const months = calendarLang === 'am' ? AM_MONTHS : ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
  return `${d} ${months[m - 1]} ${y}`
}

/** A date in the chosen calendar: "30 Nov 2026", "21 Hidar 2019 EC" or "30 Nov 2026 · 21 Hidar 2019". */
export function date(value: string | null | undefined): string {
  if (!value) return '—'
  if (calendar === 'gregorian') return gregorianDate(value)
  const ec = formatEthiopian(value.slice(0, 10), calendarLang)
  return calendar === 'ethiopian' ? `${ec} ${calendarLang === 'am' ? 'ዓ.ም.' : 'EC'}` : `${gregorianDate(value)} · ${ec}`
}

/** On printed bills and cards: the Gregorian date is always there (tax papers), the Ethiopian one next to it when chosen. */
export function printDate(value: string | null | undefined): string {
  if (!value) return '—'
  return calendar === 'gregorian' ? gregorianDate(value) : `${gregorianDate(value)} (${formatEthiopian(value.slice(0, 10), calendarLang)})`
}

/** The local calendar day of a moment ("2026-11-06" in the viewer's clock). */
function localDay(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** A moment in time in the viewer's clock: "6 Nov 2026, 15:07" (same look on every screen). */
export function dateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  const time = d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
  if (calendar === 'gregorian' && calendarLang !== 'am') return d.toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
  return `${date(localDay(d))}, ${time}`
}

/** Free disk space: "950 GB free" rather than "975970 MB". */
export function megabytes(mb: number): string {
  return mb >= 1024 ? `${Math.round(mb / 1024).toLocaleString('en-GB')} GB` : `${mb} MB`
}
