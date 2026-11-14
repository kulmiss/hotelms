/**
 * Ethiopian calendar (Amete Mihret) ⇄ Gregorian through the Julian Day Number (owner, 14 Nov 2026).
 * 12 months of 30 days + Pagume (5 days, 6 when the EC year % 4 == 3). The server stores Gregorian;
 * EC is only for showing and typing dates. Same maths as backend/app/Support/EthiopianDate.php.
 */
export type EcDate = { year: number; month: number; day: number }

const EPOCH = 1724221
export const EC_MONTHS = ['Meskerem', 'Tikimt', 'Hidar', 'Tahsas', 'Tir', 'Yekatit', 'Megabit', 'Miyazia', 'Ginbot', 'Sene', 'Hamle', 'Nehase', 'Pagume']
export const EC_MONTHS_AM = ['መስከረም', 'ጥቅምት', 'ኅዳር', 'ታኅሣሥ', 'ጥር', 'የካቲት', 'መጋቢት', 'ሚያዝያ', 'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሐሴ', 'ጳጉሜ']

const div = (a: number, b: number) => Math.floor(a / b)

export const isEcLeap = (year: number) => year % 4 === 3
export const ecDaysInMonth = (year: number, month: number) => (month < 13 ? 30 : isEcLeap(year) ? 6 : 5)

const ecToJdn = (y: number, m: number, d: number) => EPOCH + 365 * (y - 1) + div(y, 4) + 30 * (m - 1) + d - 1

function gregorianToJdn(y: number, m: number, d: number): number {
  const a = div(14 - m, 12)
  const y2 = y + 4800 - a
  const m2 = m + 12 * a - 3
  return d + div(153 * m2 + 2, 5) + 365 * y2 + div(y2, 4) - div(y2, 100) + div(y2, 400) - 32045
}

function jdnToGregorian(jdn: number): [number, number, number] {
  const a = jdn + 32044
  const b = div(4 * a + 3, 146097)
  const c = a - div(146097 * b, 4)
  const d = div(4 * c + 3, 1461)
  const e = c - div(1461 * d, 4)
  const m = div(5 * e + 2, 153)
  return [100 * b + d - 4800 + div(m, 10), m + 3 - 12 * div(m, 10), e - div(153 * m + 2, 5) + 1]
}

/** "2026-11-14" → { year: 2019, month: 3, day: 5 } */
export function toEthiopian(iso: string): EcDate {
  const [y, m, d] = iso.slice(0, 10).split('-').map(Number)
  const jdn = gregorianToJdn(y, m, d)
  const year = div(4 * (jdn - EPOCH) + 1463, 1461)
  const month = div(jdn - ecToJdn(year, 1, 1), 30) + 1
  return { year, month, day: jdn - ecToJdn(year, month, 1) + 1 }
}

/** { 2019, 3, 5 } → "2026-11-14"; null when the date does not exist (e.g. Pagume 6 in a common year). */
export function fromEthiopian({ year, month, day }: EcDate): string | null {
  if (!Number.isInteger(year) || year < 1 || month < 1 || month > 13 || day < 1 || day > ecDaysInMonth(year, month)) return null
  const [y, m, d] = jdnToGregorian(ecToJdn(year, month, day))
  return `${String(y).padStart(4, '0')}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`
}

/** "5 Hidar 2019" (Amharic month names when lang is "am"). */
export function formatEthiopian(iso: string, lang = 'en'): string {
  const ec = toEthiopian(iso)
  return `${ec.day} ${(lang === 'am' ? EC_MONTHS_AM : EC_MONTHS)[ec.month - 1]} ${ec.year}`
}
