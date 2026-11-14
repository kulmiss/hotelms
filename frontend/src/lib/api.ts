import i18n from '@/i18n'
/**
 * Small fetch wrapper for the HotelMS API (/api/v1).
 * Signs in with Sanctum's cookie session: the browser keeps the session cookie,
 * and every change sends the XSRF token Laravel gave us.
 */
const ROOT = import.meta.env.VITE_API_URL ?? ''
const BASE = ROOT + '/api/v1'

/** Full URL of an API path (for downloads in a link). */
export const apiUrl = (path: string) => BASE + path

export class ApiError extends Error {
  readonly status: number
  readonly code?: string
  readonly errors: Record<string, string[]>
  /** The whole answer (e.g. `existing_task_id` when a room already has that open task). */
  readonly body: unknown

  constructor(status: number, message: string, body: unknown) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.body = body
    const b = (body ?? {}) as { code?: string; errors?: Record<string, string[]> }
    this.code = b.code
    this.errors = b.errors ?? {}
  }

  /** First error message for a form field, if any. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0]
  }
}

function xsrfToken(): string | undefined {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : undefined
}

/** Asks Laravel for a fresh XSRF cookie (needed before signing in). */
export async function prepareSession(): Promise<void> {
  await fetch(ROOT + '/sanctum/csrf-cookie', { credentials: 'include' })
}

/**
 * A change sent twice while the first is still on its way (double-click / double tap, often on a slow network) is
 * sent once: the second caller gets the first answer (QA 8 Nov: a double-click on "Add guest" made two guests).
 * Only exactly the same change (method, address and data) at the same time; one after the other is sent again.
 */
const inFlight = new Map<string, Promise<unknown>>()

export function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const method = (init.method ?? 'GET').toUpperCase()
  if (method === 'GET' || (init.body !== undefined && typeof init.body !== 'string')) return send<T>(path, init)
  const key = `${method} ${path} ${init.body ?? ''}`
  const running = inFlight.get(key)
  if (running) return running as Promise<T>
  const p = send<T>(path, init).finally(() => inFlight.delete(key))
  inFlight.set(key, p)
  return p
}

/**
 * Saved once (owner, QA 8 Nov): every change is one submission with its own Idempotency-Key (a new UUID per call
 * of `api()` – a form submit, a tap). When the network fails or the server says "still being saved", the SAME
 * submission is sent again with the SAME key, so the server returns the first answer instead of saving twice.
 * Money forms may pass their own key (`useMoneyAction`), kept until the payment is saved.
 */
const RETRY_NETWORK_MS = [1000, 3000]
const RETRY_BUSY_MS = [1500, 3000, 5000]
const wait = (ms: number) => new Promise((r) => setTimeout(r, ms))

async function send<T>(path: string, init: RequestInit = {}): Promise<T> {
  // Offline: nothing can be changed (read-only copy). The form keeps what was typed.
  if ((init.method ?? 'GET') !== 'GET' && typeof navigator !== 'undefined' && navigator.onLine === false) {
    throw new ApiError(0, i18n.t('offline.cannotChange'), { code: 'offline' })
  }
  const method = (init.method ?? 'GET').toUpperCase()
  const given = new Headers(init.headers).get('Idempotency-Key')
  const key = method !== 'GET' ? (given ?? newIdempotencyKey()) : null
  const attempt = () => {
    const token = xsrfToken()
    return fetch(BASE + path, {
      ...init,
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(token ? { 'X-XSRF-TOKEN': token } : {}),
        ...init.headers,
        ...(key ? { 'Idempotency-Key': key } : {}),
      },
    })
  }
  let res!: Response
  for (let i = 0; ; i++) {
    try {
      res = await attempt()
    } catch (e) {
      // Network dropped or timed out: send the same submission again (same key), never a new one.
      if (!key || i >= RETRY_NETWORK_MS.length || (typeof navigator !== 'undefined' && navigator.onLine === false)) throw e
      await wait(RETRY_NETWORK_MS[i])
      continue
    }
    // The first try is still being saved on the server (a retry or a second tab with the same key): wait for it.
    if (key && res.status === 409 && i < RETRY_BUSY_MS.length) {
      const peek = (await res.clone().json().catch(() => null)) as { code?: string } | null
      if (peek?.code === 'busy') {
        await wait(RETRY_BUSY_MS[i])
        continue
      }
    }
    break
  }
  const body: unknown = await res.json().catch(() => null)
  // More than the guest owes (often a typing mistake): ask, then send again with "yes" (owner, 2026-11-07).
  const overpay = res.status === 422 ? (body as { errors?: Record<string, string[]> } | null)?.errors?.confirm_overpay?.[0] : undefined
  if (overpay && typeof init.body === 'string' && typeof window !== 'undefined') {
    if (window.confirm(overpay)) {
      const data = JSON.parse(init.body) as Record<string, unknown>
      const payment = data.payment && typeof data.payment === 'object' ? { ...(data.payment as object), confirm_overpay: true } : undefined
      return api<T>(path, { ...init, body: JSON.stringify({ ...data, confirm_overpay: true, ...(payment ? { payment } : {}) }) })
    }
    throw new ApiError(422, i18n.t('payments.overpayCancelled'), { message: i18n.t('payments.overpayCancelled'), errors: { amount: [i18n.t('payments.overpayCancelled')] } })
  }
  if (!res.ok) {
    const message =
      body && typeof body === 'object' && 'message' in body ? String(body.message) : res.statusText
    throw new ApiError(res.status, message, body)
  }
  return body as T
}

export const post = <T>(path: string, data?: unknown, headers?: Record<string, string>) =>
  api<T>(path, { method: 'POST', body: data === undefined ? undefined : JSON.stringify(data), headers })

/** A fresh key for one payment attempt (the server answers a repeat with the first result). */
export function newIdempotencyKey(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`
}

/** Sends a file (multipart form). Pass null to remove. */
export async function upload<T>(path: string, field: string, file: File | null): Promise<T> {
  await prepareSession()
  const form = new FormData()
  if (file) form.append(field, file)
  const token = xsrfToken()
  const res = await fetch(BASE + path, {
    method: 'POST',
    credentials: 'include',
    headers: { Accept: 'application/json', ...(token ? { 'X-XSRF-TOKEN': token } : {}) },
    body: form,
  })
  const body: unknown = await res.json().catch(() => null)
  if (!res.ok) {
    const message = body && typeof body === 'object' && 'message' in body ? String(body.message) : res.statusText
    throw new ApiError(res.status, message, body)
  }
  return body as T
}

export const del = <T>(path: string) => api<T>(path, { method: 'DELETE' })

export const put = <T>(path: string, data?: unknown) =>
  api<T>(path, { method: 'PUT', body: data === undefined ? undefined : JSON.stringify(data) })

export type Health = {
  status: 'ok' | 'degraded'
  app: string
  version: string
  time: string
  checks: { database: 'ok' | 'down' }
}

export const getHealth = () => api<Health>('/health')

/** Setup walkthrough progress (Hotel Admin only). */
export type SetupSummary = { completed: boolean; started: boolean; skipped: number; remaining: number }

export type Me = {
  user: {
    id: number
    name: string
    email: string
    phone: string | null
    is_super_admin: boolean
    must_change_password: boolean
  }
  hotel: { id: number; name: string; status: string; default_currency: string; timezone: string; is_demo?: boolean; demo_ends_at?: string | null; modules?: string[]; setup?: SetupSummary | null } | null
  branches: { id: number; name: string }[]
  role: { id: number; name: string; is_hotel_admin: boolean; max_discount_percent: string } | null
  permissions: string[]
  platform?: { role: string | null; permissions: string[] } | null
  impersonation?: { by: string; reason: string; started_at: string } | null
  switches?: Record<string, boolean>
  emergency?: { maintenance: boolean; read_only: boolean } | null
  banners?: { id: number; message: string; tone: 'info' | 'warn' | 'danger' }[]
  security?: { two_factor: 'app' | 'email' | null; two_factor_setup_required: boolean; screen_lock_minutes: number; has_pin: boolean; password_weak?: boolean }
}
