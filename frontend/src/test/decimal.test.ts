import { describe, expect, it } from 'vitest'
import { addMoney, compareMoney, isPositive, subMoney } from '@/lib/decimal'

describe('money maths', () => {
  it('is exact to the cent', () => {
    expect(addMoney('0.10', '0.20')).toBe('0.30')
    expect(addMoney('3220.00', '1150.00')).toBe('4370.00')
    expect(subMoney('1380.00', '1380.01')).toBe('-0.01')
    expect(subMoney('5', '7.5')).toBe('-2.50')
    expect(addMoney('-200.00', '50.5')).toBe('-149.50')
    expect(compareMoney('10.00', '9.99')).toBe(1)
    expect(isPositive('0.00')).toBe(false)
    expect(isPositive('0.01')).toBe(true)
  })
})
