/**
 * Money maths on strings ("4662.50"), exact to the cent (CLAUDE.md: never floats for money).
 * Works in whole cents with BigInt, so 0.1 + 0.2 is exactly 0.30.
 */
function cents(v: string | number | null | undefined): bigint {
  const s = String(v ?? '0').trim() || '0'
  const neg = s.startsWith('-')
  const [whole, frac = ''] = s.replace(/^[-+]/, '').split('.')
  const c = BigInt(whole || '0') * 100n + BigInt((frac + '00').slice(0, 2))
  return neg ? -c : c
}

function str(c: bigint): string {
  const neg = c < 0n
  const a = neg ? -c : c
  return `${neg ? '-' : ''}${a / 100n}.${String(a % 100n).padStart(2, '0')}`
}

export const addMoney = (...vs: (string | number | null | undefined)[]) => str(vs.reduce<bigint>((s, v) => s + cents(v), 0n))
export const subMoney = (a: string | number | null | undefined, b: string | number | null | undefined) => str(cents(a) - cents(b))
/** -1, 0 or 1 */
export const compareMoney = (a: string | number | null | undefined, b: string | number | null | undefined) => {
  const d = cents(a) - cents(b)
  return d < 0n ? -1 : d > 0n ? 1 : 0
}
export const isPositive = (v: string | number | null | undefined) => cents(v) > 0n
export const isNegative = (v: string | number | null | undefined) => cents(v) < 0n
