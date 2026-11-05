import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { healthOk, makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

describe('signing in', () => {
  it('sends signed-out visitors to the sign-in page', async () => {
    mockApi({ 'GET /me': { status: 401, body: { message: 'Unauthenticated.' } } })
    renderApp('/reservations')
    expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument()
  })

  it('signs in and opens the dashboard', async () => {
    let signedIn = false
    const fetchMock = mockApi({
      'GET /sanctum/csrf-cookie': { status: 204 },
      'GET /me': () => (signedIn ? { body: makeMe() } : { status: 401 }),
      'POST /auth/login': () => {
        signedIn = true
        return { body: { message: 'Signed in.' } }
      },
      'GET /health': healthOk,
    })
    renderApp('/login')

    await userEvent.type(await screen.findByLabelText('Email'), 'meron@sheger.test')
    await userEvent.type(screen.getByLabelText('Password'), 'secret-123')
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByRole('heading', { name: 'Dashboard' })).toBeInTheDocument()
    const login = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/auth/login'))
    expect(JSON.parse(String(login?.[1]?.body))).toMatchObject({ email: 'meron@sheger.test', remember: true })
  })

  it('shows the error for a wrong password', async () => {
    mockApi({
      'GET /sanctum/csrf-cookie': { status: 204 },
      'GET /me': { status: 401 },
      'POST /auth/login': {
        status: 422,
        body: { message: 'Invalid', errors: { email: ['These credentials do not match our records.'] } },
      },
    })
    renderApp('/login')

    await userEvent.type(await screen.findByLabelText('Email'), 'meron@sheger.test')
    await userEvent.type(screen.getByLabelText('Password'), 'wrong')
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('These credentials do not match our records.')
  })

  it('asks for a new password after signing in with a temporary one', async () => {
    mockApi({ 'GET /me': { body: makeMe({ must_change_password: true }) } })
    renderApp('/')
    expect(await screen.findByRole('heading', { name: 'Set a new password' })).toBeInTheDocument()
  })

  it('saves the new password and continues', async () => {
    let changed = false
    mockApi({
      'GET /me': () => ({ body: makeMe({ must_change_password: !changed }) }),
      'PUT /me/password': () => {
        changed = true
        return { body: { message: 'Password changed.' } }
      },
      'GET /health': healthOk,
    })
    renderApp('/change-password')

    await userEvent.type(await screen.findByLabelText('Current password'), 'ChangeMe-123')
    await userEvent.type(screen.getByLabelText('New password'), 'MyOwnPass-1')
    await userEvent.type(screen.getByLabelText('Repeat new password'), 'MyOwnPass-1')
    await userEvent.click(screen.getByRole('button', { name: 'Save password' }))

    expect(await screen.findByRole('heading', { name: 'Dashboard' })).toBeInTheDocument()
  })

  it('signs out', async () => {
    let signedIn = true
    mockApi({
      'GET /me': () => (signedIn ? { body: makeMe() } : { status: 401 }),
      'POST /auth/logout': () => {
        signedIn = false
        return { body: {} }
      },
      'GET /health': healthOk,
    })
    renderApp('/')
    await userEvent.click(await screen.findByRole('button', { name: 'Account menu' }))
    await userEvent.click(screen.getByRole('menuitem', { name: 'Sign out' }))
    expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument()
  })
})

describe('try the demo', () => {
  it('offers demo roles on the sign-in page when switched on', async () => {
    let signedIn = false
    const fetchMock = mockApi({
      'GET /branding': { body: { data: { platform_name: 'HotelMS', brand_color: '#0f766e', login_message: null, support_email: null, support_phone: null, logo_url: null, demo_public: true } } },
      'GET /sanctum/csrf-cookie': { status: 204 },
      'GET /me': () => (signedIn ? { body: { ...makeMe({ name: 'Hana' }), hotel: { id: 5, name: 'Sheger Demo Hotel', status: 'active', default_currency: 'ETB', timezone: 'Africa/Addis_Ababa', is_demo: true } } } : { status: 401 }),
      'POST /demo/login': () => {
        signedIn = true
        return { body: {} }
      },
      'GET /health': healthOk,
    })
    renderApp('/login')

    await userEvent.click(await screen.findByRole('button', { name: 'Receptionist' }))

    expect(await screen.findByText(/This is the demo hotel/)).toBeInTheDocument()
    const call = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/demo/login'))
    expect(JSON.parse(String(call?.[1]?.body))).toEqual({ role: 'receptionist' })
  })
})
