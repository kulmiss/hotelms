/**
 * Small fetch wrapper for the HotelMS API (/api/v1).
 * Auth (Sanctum tokens) is added in phase 1.
 */
const BASE = (import.meta.env.VITE_API_URL ?? '') + '/api/v1'

export class ApiError extends Error {
  readonly status: number
  readonly body: unknown

  constructor(status: number, message: string, body: unknown) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.body = body
  }
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await fetch(BASE + path, {
    ...init,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...init.headers },
  })
  const body: unknown = await res.json().catch(() => null)
  if (!res.ok) {
    const message =
      body && typeof body === 'object' && 'message' in body ? String(body.message) : res.statusText
    throw new ApiError(res.status, message, body)
  }
  return body as T
}

export type Health = {
  status: 'ok' | 'degraded'
  app: string
  version: string
  time: string
  checks: { database: 'ok' | 'down' }
}

export const getHealth = () => api<Health>('/health')
