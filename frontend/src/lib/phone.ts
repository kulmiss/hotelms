/**
 * Two phone numbers are the same person's when their last 9 digits match
 * (+251 911 223 344, 0911223344 and 911-22-33-44 are one number). Short numbers must match fully.
 */
export function samePhone(a: string | null | undefined, b: string | null | undefined): boolean {
  const x = (a ?? '').replace(/\D+/g, '')
  const y = (b ?? '').replace(/\D+/g, '')
  if (x.length < 6 || y.length < 6) return false
  return x.length >= 9 && y.length >= 9 ? x.slice(-9) === y.slice(-9) : x === y
}
