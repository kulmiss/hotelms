import { vi } from 'vitest'
import type { Me } from '@/lib/api'

type Reply = { status?: number; body?: unknown } | ((init?: RequestInit) => { status?: number; body?: unknown })

/** Fakes the API: map "METHOD /path" to a reply. Unknown calls return 404. */
export function mockApi(routes: Record<string, Reply>) {
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = new URL(String(input), 'http://localhost')
    const path = url.pathname.replace(/^\/api\/v1/, '')
    const key = `${(init?.method ?? 'GET').toUpperCase()} ${path}`
    const route = routes[key]
    const reply = typeof route === 'function' ? route(init) : (route ?? { status: 404, body: { message: 'Not found' } })
    const status = reply.status ?? 200
    return {
      ok: status >= 200 && status < 300,
      status,
      statusText: '',
      json: () => Promise.resolve(reply.body ?? {}),
    } as Response
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

export function makeMe(overrides: Partial<Me['user']> = {}): Me {
  return {
    user: {
      id: 1,
      name: 'Meron Tesfaye',
      email: 'meron@sheger.test',
      phone: null,
      is_super_admin: false,
      must_change_password: false,
      ...overrides,
    },
    hotel: { id: 1, name: 'Sheger Hotel', status: 'active', default_currency: 'ETB', timezone: 'Africa/Addis_Ababa' },
    branches: [
      { id: 1, name: 'Bole branch' },
      { id: 2, name: 'Piassa branch' },
    ],
  }
}

export const healthOk = {
  body: { status: 'ok', app: 'HotelMS', version: '0.1.0', time: '', checks: { database: 'ok' } },
}
