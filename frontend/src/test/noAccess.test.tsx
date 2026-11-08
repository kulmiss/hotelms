import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

// Owner (QA 8 Nov): a screen the role may not open says so, with a way back – no silent jump to the start page.
const RECEPTION = ['dashboard.view', 'frontdesk.view', 'reservations.view', 'guests.view']

describe('no access page', () => {
  it('shows "You can\'t open this page" for a screen the role may not open', async () => {
    mockApi({ 'GET /me': { body: makeMe({}, RECEPTION) } })
    renderApp('/staff')
    expect(await screen.findByRole('heading', { name: "You can't open this page" })).toBeInTheDocument()
    expect(screen.getByText(/Ask your manager/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Go to the start page' })).toHaveAttribute('href', '/')
  })

  it('also for a settings section the role may not use, and for Super Admin pages', async () => {
    mockApi({ 'GET /me': { body: makeMe({}, RECEPTION) } })
    const { unmount } = renderApp('/settings/taxes')
    expect(await screen.findByRole('heading', { name: "You can't open this page" })).toBeInTheDocument()
    unmount()

    mockApi({ 'GET /me': { body: makeMe({}, RECEPTION) } })
    renderApp('/admin/hotels')
    expect(await screen.findByRole('heading', { name: "You can't open this page" })).toBeInTheDocument()
  })

  // QA #120: screens that used to open by address for a role without the permission.
  it('blocks Reports for a role with no report (Restaurant / Bar)', async () => {
    mockApi({ 'GET /me': { body: makeMe({}, ['charges.room', 'attendance.clock']) } })
    renderApp('/reports')
    expect(await screen.findByRole('heading', { name: "You can't open this page" })).toBeInTheDocument()
  })

  it('blocks adding staff with only staff.view', async () => {
    mockApi({ 'GET /me': { body: makeMe({}, ['dashboard.view', 'staff.view']) } })
    renderApp('/staff/new')
    expect(await screen.findByRole('heading', { name: "You can't open this page" })).toBeInTheDocument()
  })

  it('blocks a Super Admin page the team member may not use', async () => {
    const sales = { ...makeMe({ is_super_admin: true, name: 'Sales' }, []), role: null, hotel: null, branches: [], platform: { role: 'sales', permissions: ['hotels.view', 'support.answer'] } }
    mockApi({ 'GET /me': { body: sales } })
    const { unmount } = renderApp('/admin/switches')
    expect(await screen.findByRole('heading', { name: "You can't open this page" })).toBeInTheDocument()
    unmount()
    mockApi({ 'GET /me': { body: sales } })
    renderApp('/admin/team')
    expect(await screen.findByRole('heading', { name: "You can't open this page" })).toBeInTheDocument()
  })

  it('goes back to the start page with the button', async () => {
    mockApi({ 'GET /me': { body: makeMe({}, RECEPTION) }, 'GET /dashboard': { body: { data: {} } } })
    renderApp('/roles')
    await userEvent.click(await screen.findByRole('link', { name: 'Go to the start page' }))
    expect(screen.queryByRole('heading', { name: "You can't open this page" })).not.toBeInTheDocument()
  })
})
