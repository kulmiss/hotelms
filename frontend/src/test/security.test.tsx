import { cleanup, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { healthOk, makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

const security = (over: object = {}) => ({ two_factor: null, two_factor_setup_required: false, screen_lock_minutes: 0, has_pin: false, ...over })
const body = (fetchMock: ReturnType<typeof mockApi>, path: string) => {
  const call = fetchMock.mock.calls.find(([u, i]) => String(u).endsWith(path) && i?.method === 'POST')
  return call ? JSON.parse(String(call[1]?.body ?? '{}')) : undefined
}

describe('two-step sign-in', () => {
  it('asks for the code after the password', async () => {
    let signedIn = false
    const fetchMock = mockApi({
      'GET /sanctum/csrf-cookie': { status: 204 },
      'GET /me': () => (signedIn ? { body: makeMe() } : { status: 401 }),
      'POST /auth/login': { body: { two_factor: 'email', email: 'me••@sheger.test' } },
      'POST /auth/two-factor': () => {
        signedIn = true
        return { body: { message: 'Signed in.' } }
      },
      'GET /health': healthOk,
    })
    renderApp('/login')
    await userEvent.type(await screen.findByLabelText('Email'), 'meron@sheger.test')
    await userEvent.type(screen.getByLabelText('Password'), 'secret-123')
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByText('We sent a 6-digit code to me••@sheger.test.')).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('6-digit code'), '12a3456')
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    expect(await screen.findByRole('heading', { name: 'Dashboard' })).toBeInTheDocument()
    expect(body(fetchMock, '/auth/two-factor')).toEqual({ code: '123456' })
  })

  it('sends people who must use it to the set-up page first', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: { ...makeMe(), security: security({ two_factor_setup_required: true }) } },
      'POST /me/security/two-factor': { body: { data: { method: 'app', secret: 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', uri: 'otpauth://totp/x' } } },
      'POST /me/security/two-factor/confirm': { body: { recovery_codes: ['aaaaa-bbbbb', 'ccccc-ddddd'] } },
    })
    renderApp('/reservations')
    expect(await screen.findByRole('heading', { name: 'Turn on two-step sign-in' })).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Your password'), 'secret-123')
    await userEvent.click(screen.getByRole('button', { name: 'Next' }))
    expect(await screen.findByText('JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP')).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('6-digit code'), '654321')
    await userEvent.click(screen.getByRole('button', { name: 'Turn on' }))
    expect(await screen.findByText('aaaaa-bbbbb')).toBeInTheDocument()
    expect(body(fetchMock, '/me/security/two-factor')).toEqual({ method: 'app', password: 'secret-123' })
  })
})

describe('ended sessions', () => {
  it('goes to the sign-in page when the session ends on another screen', async () => {
    mockApi({
      'GET /me': { body: makeMe() },
      'GET /guests': { status: 401, body: { message: 'This device was signed out.', code: 'device_signed_out' } },
    })
    renderApp('/guests')
    expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument()
  })
})

describe('shared devices', () => {
  it('locks the screen and lets another staff member take over with a PIN', async () => {
    localStorage.setItem('hms_locked', '1')
    const fetchMock = mockApi({
      'GET /me': { body: { ...makeMe(), role: { id: 2, name: 'Receptionist', is_hotel_admin: false, max_discount_percent: '5.00' }, security: security({ screen_lock_minutes: 15 }) } },
      'GET /health': healthOk,
      'POST /auth/pin-switch': { body: { message: 'Signed in.' } },
    })
    renderApp('/')
    expect(await screen.findByRole('dialog', { name: 'Screen locked' })).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('PIN'), '4821')
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Screen locked' })).not.toBeInTheDocument())
    expect(body(fetchMock, '/auth/pin-switch')).toEqual({ pin: '4821' })
  })

  it('approves a device waiting in Team → Devices', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: makeMe() },
      'GET /devices': { body: { data: [{ id: 5, name: 'Safari on iPad', ip: '10.0.0.4', status: 'pending', approved: false, last_seen: '2026-11-12T09:30:00+03:00', this_device: false, user: { id: 3, name: 'Hana' } }] } },
      'POST /devices/5/approve': { body: { message: 'Done.' } },
    })
    renderApp('/devices')
    expect(await screen.findByText('1 device is waiting for your approval.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Approve' }))
    await waitFor(() => expect(fetchMock.mock.calls.some(([u, i]) => String(u).endsWith('/devices/5/approve') && i?.method === 'POST')).toBe(true))
  })

  it('turns on approved devices only in Settings → Security', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: makeMe() },
      'GET /settings/security': { body: { data: { require_two_factor_staff: false, approved_devices_only: false, screen_lock_minutes: 15 } } },
      'PUT /settings/security': { body: { data: { require_two_factor_staff: false, approved_devices_only: true, screen_lock_minutes: 15 } } },
    })
    renderApp('/settings/security')
    await userEvent.click(await screen.findByRole('checkbox', { name: /Only approved devices/ }))
    await waitFor(() => {
      const call = fetchMock.mock.calls.find(([u, i]) => String(u).endsWith('/settings/security') && i?.method === 'PUT')
      expect(JSON.parse(String(call?.[1]?.body))).toEqual({ require_two_factor_staff: false, approved_devices_only: true, screen_lock_minutes: 15 })
    })
  })
})

describe('forgot password', () => {
  it('sends a link and sets a new password', async () => {
    const fetchMock = mockApi({
      'GET /sanctum/csrf-cookie': { status: 204 },
      'GET /me': { status: 401 },
      'POST /auth/forgot-password': { body: { message: 'ok' } },
      'POST /auth/reset-password': { body: { message: 'ok' } },
    })
    renderApp('/login')
    await userEvent.click(await screen.findByRole('link', { name: 'Forgot password?' }))
    await userEvent.type(await screen.findByLabelText('Email'), 'meron@sheger.test')
    await userEvent.click(screen.getByRole('button', { name: 'Send the link' }))
    expect(await screen.findByText(/a link is on its way/)).toBeInTheDocument()

    cleanup()
    renderApp('/reset-password?token=abc&email=meron%40sheger.test')
    await userEvent.type(await screen.findByLabelText('New password'), 'NewPass-2026')
    await userEvent.type(screen.getByLabelText('Type it again'), 'NewPass-2026')
    await userEvent.click(screen.getByRole('button', { name: 'Save new password' }))
    expect(await screen.findByText('Password changed. You can sign in now.')).toBeInTheDocument()
    expect(body(fetchMock, '/auth/reset-password')).toEqual({ token: 'abc', email: 'meron@sheger.test', password: 'NewPass-2026', password_confirmation: 'NewPass-2026' })
  })
})

describe('weak password note (SECURITY_REPORT M2)', () => {
  it('asks to change a password that is weaker than the new rules, and can be closed', async () => {
    mockApi({ 'GET /me': { body: { ...makeMe(), security: security({ password_weak: true }) } }, 'GET /health': healthOk })
    renderApp('/')
    expect(await screen.findByText(/Your password is easier to guess/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Change password' })).toHaveAttribute('href', '/profile')
    const note = screen.getByText(/Your password is easier to guess/).closest('[role="status"]') as HTMLElement
    await userEvent.click(note.querySelector('button') as HTMLButtonElement)
    expect(screen.queryByText(/Your password is easier to guess/)).not.toBeInTheDocument()
  })
})
