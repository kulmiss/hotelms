import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { healthOk, makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

describe('app shell (signed in)', () => {
  it('shows the dashboard with a connected API', async () => {
    mockApi({ 'GET /me': { body: makeMe() }, 'GET /health': healthOk })
    renderApp('/')
    expect(await screen.findByRole('heading', { name: 'Dashboard' })).toBeInTheDocument()
    expect(await screen.findByText('Connected')).toBeInTheDocument()
    expect(screen.getByText('Version 0.1.0')).toBeInTheDocument()
  })

  it('reports when the API is not reachable', async () => {
    mockApi({ 'GET /me': { body: makeMe() }, 'GET /health': { status: 503, body: {} } })
    renderApp('/')
    expect(await screen.findByText('API not reachable', {}, { timeout: 4000 })).toBeInTheDocument()
  })

  it('lists every main section in the navigation', async () => {
    mockApi({ 'GET /me': { body: makeMe() }, 'GET /health': healthOk })
    renderApp('/')
    const nav = await screen.findByRole('navigation', { name: 'Main' })
    for (const label of ['Room board', 'Reservations', 'Guests', 'Billing', 'Reports', 'Settings']) {
      expect(nav).toHaveTextContent(label)
    }
  })

  it('shows the user, their hotel branches and a sign-out button', async () => {
    mockApi({ 'GET /me': { body: makeMe() }, 'GET /health': healthOk })
    renderApp('/')
    expect(await screen.findByText('Meron Tesfaye')).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Piassa branch' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Account menu' }))
    expect(screen.getByRole('menu')).toBeVisible()
    expect(screen.getByRole('menuitem', { name: 'My profile' })).toBeInTheDocument()
    expect(screen.getByRole('menuitem', { name: 'Sign out' })).toBeInTheDocument()
    await userEvent.keyboard('{Escape}')
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })

  it('opens a planned screen as a placeholder', async () => {
    mockApi({ 'GET /me': { body: makeMe() }, 'GET /health': healthOk })
    renderApp('/')
    await userEvent.click(await screen.findByRole('link', { name: 'Reports' }))
    expect(screen.getByRole('heading', { name: 'Reports' })).toBeInTheDocument()
    expect(screen.getByText('Coming in phase 6')).toBeInTheDocument()
  })

  it('switches to dark mode from the account menu and remembers it', async () => {
    mockApi({ 'GET /me': { body: makeMe() }, 'GET /health': healthOk })
    renderApp('/')
    await userEvent.click(await screen.findByRole('button', { name: 'Account menu' }))
    await userEvent.click(screen.getByRole('menuitemradio', { name: 'Dark' }))
    await waitFor(() => expect(document.documentElement).toHaveClass('dark'))
    expect(localStorage.getItem('hms-theme')).toBe('dark')
    expect(screen.getByRole('menuitemradio', { name: 'Dark' })).toHaveAttribute('aria-checked', 'true')
  })

  it('lets users edit their own profile', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: makeMe() },
      'PUT /me': { body: makeMe({ name: 'Meron T.' }) },
    })
    renderApp('/profile')
    const name = await screen.findByLabelText('Full name')
    await userEvent.clear(name)
    await userEvent.type(name, 'Meron T.')
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByText('Saved.')).toBeInTheDocument()
    const call = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/me') && init?.method === 'PUT')
    expect(JSON.parse(String(call?.[1]?.body))).toMatchObject({ name: 'Meron T.' })
  })

  it('shows a not-found page for unknown addresses', async () => {
    mockApi({ 'GET /me': { body: makeMe() } })
    renderApp('/nope')
    expect(await screen.findByRole('heading', { name: 'Page not found' })).toBeInTheDocument()
  })
})
