/** Formats a money string ("4662.50") for display without float maths. */
export function money(amount: string | number | null | undefined, currency = 'ETB'): string {
  if (amount === null || amount === undefined || amount === '') return '—'
  const [whole, cents = '00'] = String(amount).split('.')
  const sign = whole.startsWith('-') ? '-' : ''
  const digits = whole.replace('-', '').replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  return `${sign}${currency} ${digits}.${cents.padEnd(2, '0').slice(0, 2)}`
}

/** "2026-11-30" → "30 Nov 2026" (dates stay as written; no timezone shifts). */
export function date(value: string | null | undefined): string {
  if (!value) return '—'
  const [y, m, d] = value.slice(0, 10).split('-').map(Number)
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
  return `${d} ${months[m - 1]} ${y}`
}

/** A moment in time in the viewer's clock: "6 Nov 2026, 15:07" (same look on every screen). */
export function dateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

/** Free disk space: "950 GB free" rather than "975970 MB". */
export function megabytes(mb: number): string {
  return mb >= 1024 ? `${Math.round(mb / 1024).toLocaleString('en-GB')} GB` : `${mb} MB`
}
