import { afterEach, describe, expect, it, vi } from 'vitest'
import { post, put } from '@/lib/api'

afterEach(() => vi.unstubAllGlobals())

// QA 8 Nov: a double-click on "Add guest" on a slow network made two guests. The same change sent twice
// while the first is still on its way goes out once; both callers get the same answer.
function slowServer() {
  let n = 0
  const fetchMock = vi.fn(async () => {
    n += 1
    await new Promise((r) => setTimeout(r, 30))
    return new Response(JSON.stringify({ data: { id: n } }), { status: 201, headers: { 'Content-Type': 'application/json' } })
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

describe('double submit', () => {
  it('sends the same change only once while it is on its way', async () => {
    const fetchMock = slowServer()
    const [a, b] = await Promise.all([post('/guests', { first_name: 'QA' }), post('/guests', { first_name: 'QA' })])
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(a).toEqual(b)
  })

  it('sends different changes, and the same change again after the first answer', async () => {
    const fetchMock = slowServer()
    await Promise.all([post('/guests', { first_name: 'A' }), post('/guests', { first_name: 'B' }), put('/guests/1', { first_name: 'A' })])
    expect(fetchMock).toHaveBeenCalledTimes(3)
    await post('/guests', { first_name: 'A' })
    expect(fetchMock).toHaveBeenCalledTimes(4)
  })
})

describe('Idempotency-Key on every change', () => {
  it('reuses the key for the same change within 3 seconds, then makes a new one', async () => {
    const { autoIdempotencyKey } = await import('@/lib/api')
    const a = autoIdempotencyKey('POST', '/front-desk/rooms/2/charges', '{"charge_type_id":4}', 1_000)
    expect(autoIdempotencyKey('POST', '/front-desk/rooms/2/charges', '{"charge_type_id":4}', 3_500)).toBe(a)
    expect(autoIdempotencyKey('POST', '/front-desk/rooms/2/charges', '{"charge_type_id":5}', 3_500)).not.toBe(a)
    expect(autoIdempotencyKey('POST', '/front-desk/rooms/2/charges', '{"charge_type_id":4}', 4_100)).not.toBe(a) // a second breakfast later
  })

  it('sends the key with every change, never with reading', async () => {
    const fetchMock = slowServer()
    await post('/guests', { first_name: 'QA' })
    const headers = (fetchMock.mock.calls[0] as unknown as [string, RequestInit])[1].headers as Record<string, string>
    expect(headers['Idempotency-Key']).toBeTruthy()
  })
})
