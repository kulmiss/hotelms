import { describe, expect, it } from 'vitest'
import { ecDaysInMonth, formatEthiopian, fromEthiopian, isEcLeap, toEthiopian } from '@/lib/ethiopian'

// Same table as backend tests/Unit/EthiopianDateTest.php.
const KNOWN: [string, number, number, number][] = [
  ['2024-09-11', 2017, 1, 1], ['2023-09-12', 2016, 1, 1], ['2023-09-11', 2015, 13, 6], ['2024-09-10', 2016, 13, 5],
  ['2026-09-11', 2019, 1, 1], ['2027-09-11', 2019, 13, 6], ['2027-09-12', 2020, 1, 1], ['2024-01-07', 2016, 4, 28],
  ['2025-01-07', 2017, 4, 29], ['2025-01-19', 2017, 5, 11], ['2025-03-02', 2017, 6, 23], ['2026-09-27', 2019, 1, 17],
  ['2026-11-14', 2019, 3, 5], ['2024-02-29', 2016, 6, 21], ['2000-01-01', 1992, 4, 22],
]

describe('Ethiopian calendar', () => {
  it.each(KNOWN)('%s = %i-%i-%i EC', (greg, year, month, day) => {
    expect(toEthiopian(greg)).toEqual({ year, month, day })
    expect(fromEthiopian({ year, month, day })).toBe(greg)
  })

  it('goes there and back for every day from 1900 to 2100', () => {
    const d = new Date(Date.UTC(1900, 0, 1))
    let prev = toEthiopian('1899-12-31')
    while (d.getUTCFullYear() <= 2100) {
      const iso = d.toISOString().slice(0, 10)
      const ec = toEthiopian(iso)
      expect(fromEthiopian(ec)).toBe(iso)
      const next = prev.day < ecDaysInMonth(prev.year, prev.month) ? { ...prev, day: prev.day + 1 } : prev.month < 13 ? { year: prev.year, month: prev.month + 1, day: 1 } : { year: prev.year + 1, month: 1, day: 1 }
      expect(ec).toEqual(next)
      prev = ec
      d.setUTCDate(d.getUTCDate() + 1)
    }
  })

  it('knows leap years and refuses dates that do not exist', () => {
    expect(isEcLeap(2019)).toBe(true)
    expect(ecDaysInMonth(2018, 13)).toBe(5)
    expect(fromEthiopian({ year: 2018, month: 13, day: 6 })).toBeNull()
    expect(fromEthiopian({ year: 2019, month: 13, day: 6 })).toBe('2027-09-11')
  })

  it('formats in English and Amharic', () => {
    expect(formatEthiopian('2026-11-14')).toBe('5 Hidar 2019')
    expect(formatEthiopian('2026-11-14', 'am')).toBe('5 ኅዳር 2019')
  })
})
