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

describe('Idempotency-Key: one per submission, the same for its retries (owner, QA 8 Nov)', () => {
  const keyOf = (call: unknown) => ((call as [string, RequestInit])[1].headers as Record<string, string>)['Idempotency-Key']
  const ok = () => new Response(JSON.stringify({ data: { id: 1 } }), { status: 201, headers: { 'Content-Type': 'application/json' } })

  it('gives every submission its own key – a real second action is a new one', async () => {
    const fetchMock = vi.fn(async () => ok())
    vi.stubGlobal('fetch', fetchMock)
    await post('/front-desk/rooms/2/charges', { charge_type_id: 4 })
    await post('/front-desk/rooms/2/charges', { charge_type_id: 4 }) // a second breakfast, right after
    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(keyOf(fetchMock.mock.calls[0])).toBeTruthy()
    expect(keyOf(fetchMock.mock.calls[0])).not.toBe(keyOf(fetchMock.mock.calls[1]))
  })

  it('retries a dropped or timed-out request with the SAME key', async () => {
    const fetchMock = vi.fn().mockRejectedValueOnce(new TypeError('Failed to fetch')).mockResolvedValueOnce(ok())
    vi.stubGlobal('fetch', fetchMock)
    await post('/reservations/9/payments', { amount: '100' })
    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(keyOf(fetchMock.mock.calls[1])).toBe(keyOf(fetchMock.mock.calls[0]))
  })

  it('waits and asks again with the SAME key while the server says "still being saved"', async () => {
    const busy = new Response(JSON.stringify({ message: 'This is still being saved.', code: 'busy' }), { status: 409, headers: { 'Content-Type': 'application/json' } })
    const fetchMock = vi.fn().mockResolvedValueOnce(busy).mockResolvedValueOnce(ok())
    vi.stubGlobal('fetch', fetchMock)
    await expect(post('/cashier/close', { counted: { ETB: '500' } })).resolves.toEqual({ data: { id: 1 } })
    expect(keyOf(fetchMock.mock.calls[1])).toBe(keyOf(fetchMock.mock.calls[0]))
  })

  it('keeps a key the form passes (money forms) and sends none when reading', async () => {
    const fetchMock = vi.fn(async () => ok())
    vi.stubGlobal('fetch', fetchMock)
    await post('/invoices/4/payments', { amount: '50' }, { 'Idempotency-Key': 'form-key-1' })
    expect(keyOf(fetchMock.mock.calls[0])).toBe('form-key-1')
  })
})
