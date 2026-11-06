import { describe, expect, it } from 'vitest'
import { qrMatrix } from '@/lib/qr'

describe('QR code', () => {
  it('has the right size and the three corner squares', () => {
    const uri = 'otpauth://totp/HotelMS%3Aowner%40example.et?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=HotelMS&digits=6&period=30'
    const m = qrMatrix(uri)
    expect(m.length).toBe(45) // version 7 (with version blocks)
    const finder = (x: number, y: number) => m[y][x] && m[y][x + 6] && m[y + 6][x] && m[y + 6][x + 6] && m[y + 3][x + 3] && !m[y + 1][x + 1]
    expect(finder(0, 0) && finder(38, 0) && finder(0, 38)).toBe(true)
    expect(qrMatrix('HELLO').length).toBe(21)
  })
})
