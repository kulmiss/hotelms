import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { renderApp } from './renderApp'

function mockHealth(body: object, ok = true) {
  vi.stubGlobal(
    'fetch',
    vi.fn().mockResolvedValue({ ok, status: ok ? 200 : 503, statusText: '', json: () => Promise.resolve(body) }),
  )
}

afterEach(() => vi.unstubAllGlobals())

describe('app shell', () => {
  it('shows the dashboard with a connected API', async () => {
    mockHealth({ status: 'ok', app: 'HotelMS', version: '0.1.0', time: '', checks: { database: 'ok' } })
    renderApp('/')
    expect(screen.getByRole('heading', { name: 'Dashboard' })).toBeInTheDocument()
    expect(await screen.findByText('Connected')).toBeInTheDocument()
    expect(screen.getByText('Version 0.1.0')).toBeInTheDocument()
  })

  it('reports when the API is not reachable', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))
    renderApp('/')
    expect(await screen.findByText('API not reachable', {}, { timeout: 4000 })).toBeInTheDocument()
  })

  it('lists every main section in the navigation', () => {
    mockHealth({})
    renderApp('/')
    const nav = screen.getByRole('navigation', { name: 'Main' })
    for (const label of ['Room board', 'Reservations', 'Guests', 'Billing', 'Reports', 'Settings']) {
      expect(nav).toHaveTextContent(label)
    }
  })

  it('opens a planned screen as a placeholder', async () => {
    mockHealth({})
    renderApp('/')
    await userEvent.click(screen.getByRole('link', { name: 'Reservations' }))
    expect(screen.getByRole('heading', { name: 'Reservations' })).toBeInTheDocument()
    expect(screen.getByText('Coming in phase 3')).toBeInTheDocument()
  })

  it('toggles dark mode and remembers it', async () => {
    mockHealth({})
    renderApp('/')
    await userEvent.click(screen.getByRole('button', { name: 'Dark mode' }))
    await waitFor(() => expect(document.documentElement).toHaveClass('dark'))
    expect(localStorage.getItem('hms-theme')).toBe('dark')
    expect(screen.getByRole('button', { name: 'Light mode' })).toBeInTheDocument()
  })

  it('shows a not-found page for unknown addresses', () => {
    mockHealth({})
    renderApp('/nope')
    expect(screen.getByRole('heading', { name: 'Page not found' })).toBeInTheDocument()
  })

  it('shows the sign-in page', () => {
    renderApp('/login')
    expect(screen.getByRole('heading', { name: 'Sign in' })).toBeInTheDocument()
    expect(screen.getByLabelText('Email')).toBeInTheDocument()
  })
})
