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
    expect(screen.getByRole('button', { name: 'Sign out' })).toBeInTheDocument()
  })

  it('opens a planned screen as a placeholder', async () => {
    mockApi({ 'GET /me': { body: makeMe() }, 'GET /health': healthOk })
    renderApp('/')
    await userEvent.click(await screen.findByRole('link', { name: 'Reservations' }))
    expect(screen.getByRole('heading', { name: 'Reservations' })).toBeInTheDocument()
    expect(screen.getByText('Coming in phase 3')).toBeInTheDocument()
  })

  it('toggles dark mode and remembers it', async () => {
    mockApi({ 'GET /me': { body: makeMe() }, 'GET /health': healthOk })
    renderApp('/')
    await userEvent.click(await screen.findByRole('button', { name: 'Dark mode' }))
    await waitFor(() => expect(document.documentElement).toHaveClass('dark'))
    expect(localStorage.getItem('hms-theme')).toBe('dark')
    expect(screen.getByRole('button', { name: 'Light mode' })).toBeInTheDocument()
  })

  it('shows a not-found page for unknown addresses', async () => {
    mockApi({ 'GET /me': { body: makeMe() } })
    renderApp('/nope')
    expect(await screen.findByRole('heading', { name: 'Page not found' })).toBeInTheDocument()
  })
})
