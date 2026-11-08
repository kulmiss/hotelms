import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { healthOk, makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

describe('app shell (signed in)', () => {
  const support = () => ({ ...makeMe(), impersonation: { by: 'Owner', reason: 'Help', started_at: '2026-11-05T10:00:00Z' } })

  it('shows HotelMS support a connected API on the dashboard', async () => {
    mockApi({ 'GET /me': { body: support() }, 'GET /health': healthOk })
    renderApp('/')
    expect(await screen.findByRole('heading', { name: 'Dashboard' })).toBeInTheDocument()
    expect(await screen.findByText('Connected')).toBeInTheDocument()
    expect(screen.getByText('Version 0.1.0')).toBeInTheDocument()
  })

  it('does not show the technical API status to hotel staff', async () => {
    mockApi({ 'GET /me': { body: makeMe() }, 'GET /health': healthOk })
    renderApp('/')
    expect(await screen.findByRole('heading', { name: 'Dashboard' })).toBeInTheDocument()
    expect(screen.queryByText('API status')).not.toBeInTheDocument()
  })

  it('reports when the API is not reachable', async () => {
    mockApi({ 'GET /me': { body: support() }, 'GET /health': { status: 503, body: {} } })
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
    // The branch is chosen at the top of the sidebar.
    await userEvent.click(screen.getByRole('button', { name: /Choose branch/ }))
    expect(screen.getByRole('menuitemradio', { name: 'Piassa branch' })).toBeInTheDocument()
    await userEvent.keyboard('{Escape}')
    await userEvent.click(screen.getByRole('button', { name: 'Account menu' }))
    expect(screen.getByRole('menu')).toBeVisible()
    expect(screen.getByRole('menuitem', { name: 'My profile' })).toBeInTheDocument()
    expect(screen.getByRole('menuitem', { name: 'Sign out' })).toBeInTheDocument()
    await userEvent.keyboard('{Escape}')
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })

  it('opens a screen from the navigation', async () => {
    mockApi({ 'GET /me': { body: makeMe() }, 'GET /health': healthOk })
    renderApp('/')
    await userEvent.click(await screen.findByRole('link', { name: 'Reports' }))
    expect(await screen.findByRole('heading', { name: 'Reports' })).toBeInTheDocument()
    expect(screen.getByRole('navigation', { name: 'Reports' })).toHaveTextContent('Revenue and performance')
  })

  it('switches to dark mode from the theme button and remembers it', async () => {
    mockApi({ 'GET /me': { body: makeMe() }, 'GET /health': healthOk })
    renderApp('/')
    await userEvent.click(await screen.findByRole('button', { name: 'Theme' }))
    await userEvent.click(screen.getByRole('menuitemradio', { name: 'Dark' }))
    await waitFor(() => expect(document.documentElement).toHaveClass('dark'))
    expect(localStorage.getItem('hms-theme')).toBe('dark')
    await userEvent.click(screen.getByRole('button', { name: 'Theme' }))
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

describe('toasts', () => {
  it('shows a short message and closes it', async () => {
    const { toast } = await import('@/lib/toast')
    const { render, screen: s, act } = await import('@testing-library/react')
    const { Toaster } = await import('@/components/ui/Toaster')
    render(<Toaster />)
    act(() => void toast('Changes saved'))
    expect(await s.findByText('Changes saved')).toBeInTheDocument()
    await userEvent.click(s.getByRole('button', { name: 'Close' }))
    expect(s.queryByText('Changes saved')).not.toBeInTheDocument()
  })
})
