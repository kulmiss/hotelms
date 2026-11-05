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
