import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const superAdmin = () => ({ ...makeMe({ is_super_admin: true, name: 'Owner' }, []), role: null, hotel: null, branches: [] })
const sentBody = (fetchMock: ReturnType<typeof mockApi>, path: string, method: string) => {
  const call = fetchMock.mock.calls.find(([u, init]) => String(u).includes(path) && init?.method === method)
  return call ? JSON.parse(String(call[1]?.body ?? '{}')) : undefined
}

describe('smart alerts bell', () => {
  it('shows new alerts and marks one as seen', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: makeMe() },
      'GET /alert-events': { body: { data: [{ id: 7, title: 'Cash short ETB 300.00 on Hana’s shift', link: '/cashier', fired_at: '2026-11-12T10:15:00+03:00', acknowledged_at: null }], unread: 1 } },
      'POST /alert-events/7/ack': { body: { message: 'Done.' } },
    })
    renderApp('/profile')
    await userEvent.click(await screen.findByRole('button', { name: 'Alerts: 1 new' }))
    expect(screen.getByRole('link', { name: /Cash short ETB 300.00/ })).toHaveAttribute('href', '/cashier')
    await userEvent.click(screen.getByRole('button', { name: 'Mark as seen' }))
    await waitFor(() => expect(fetchMock.mock.calls.some(([u, i]) => String(u).endsWith('/alert-events/7/ack') && i?.method === 'POST')).toBe(true))
  })

  it('does not ask for hotel alerts as Super Admin', async () => {
    const fetchMock = mockApi({ 'GET /me': { body: superAdmin() } })
    renderApp('/profile')
    expect(await screen.findByRole('button', { name: 'Notifications' })).toBeInTheDocument()
    expect(fetchMock.mock.calls.some(([u]) => String(u).includes('/alert-events'))).toBe(false)
  })
})

describe('report automation', () => {
  const custom = { data: [], measures: ['room_nights', 'room_revenue', 'bookings'], groups: ['day', 'room_type', 'source'] }

  it('saves the current filters as a view', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: makeMe({}, ['reports.payments']) },
      'GET /reports/payments': { body: { data: { by_method: [], by_staff: [], total: '0.00', from: '2026-11-01', to: '2026-11-12', currency: 'ETB', compare_range: null } } },
      'GET /saved-views': { body: { data: [] } },
      'POST /saved-views': { status: 201, body: { data: { id: 1 } } },
    })
    renderApp('/reports?report=payments')
    await userEvent.click(await screen.findByRole('button', { name: 'Save view' }))
    await userEvent.type(screen.getByLabelText('View name'), 'Month cash')
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(sentBody(fetchMock, '/saved-views', 'POST')).toMatchObject({ report_key: 'payments', name: 'Month cash', is_pinned: true, shared_with_roles: null }))
    // Not allowed to manage roles: no share list, and the roles list is never asked for.
    expect(fetchMock.mock.calls.some(([u]) => String(u).endsWith('/roles'))).toBe(false)
  })

  it('builds a custom report and shows the result', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: makeMe({}, ['reports.custom']) },
      'GET /custom-reports': { body: custom },
      'POST /custom-reports/run': { body: { data: { columns: ['room_nights', 'room_revenue'], rows: [{ key: '3', label: 'Deluxe', room_nights: 4, room_revenue: '8000.00' }], currency: 'ETB' } } },
    })
    renderApp('/reports?report=custom')
    await userEvent.selectOptions(await screen.findByLabelText('Group by'), 'room_type')
    await userEvent.click(screen.getByRole('button', { name: 'Run' }))
    expect(await screen.findByText('Deluxe')).toBeInTheDocument()
    expect(screen.getByText('ETB 8,000.00')).toBeInTheDocument()
    expect(sentBody(fetchMock, '/custom-reports/run', 'POST')).toMatchObject({ measures: ['room_nights', 'room_revenue'], group_by: 'room_type' })
  })

  it('adds a weekly schedule', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: makeMe({}, ['reports.schedule']) },
      'GET /report-schedules': { body: { data: [], reports: ['daily', 'performance'], people: [{ id: 1, name: 'Meron Tesfaye', email: 'meron@sheger.test' }] } },
      'POST /report-schedules': { status: 201, body: { data: { id: 1 } } },
    })
    renderApp('/reports?report=schedules')
    await userEvent.click(await screen.findByRole('button', { name: 'Add schedule' }))
    await userEvent.type(screen.getByLabelText('Name'), 'Monday summary')
    await userEvent.selectOptions(screen.getByLabelText('How often'), 'weekly')
    await userEvent.click(screen.getByRole('checkbox', { name: 'Meron Tesfaye' }))
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(sentBody(fetchMock, '/report-schedules', 'POST')).toMatchObject({ name: 'Monday summary', frequency: 'weekly', day: 1, recipients: [1], channels: ['email'] }))
  })

  it('switches a smart alert off', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: makeMe({}, ['alerts.manage']) },
      'GET /alert-rules': { body: { data: [{ id: 3, type: 'cash_short', threshold: { amount: 200 }, channels: ['app'], recipients: [], is_active: true, last_checked_at: null }], people: [] } },
      'PUT /alert-rules/3': { body: { data: {} } },
    })
    renderApp('/reports?report=alerts')
    expect(await screen.findByText('When a cashier shift closes 200 or more short.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('switch', { name: 'Cash short at close' }))
    await waitFor(() => expect(sentBody(fetchMock, '/alert-rules/3', 'PUT')).toMatchObject({ is_active: false }))
  })
})

describe('telegram', () => {
  it('gives a link code on the profile page', async () => {
    mockApi({
      'GET /me': { body: makeMe() },
      'GET /telegram': { body: { enabled: true, bot: 'ShegerBot', linked: false, chat_name: null } },
      'POST /telegram/link': { body: { data: { code: 'AB12CD34', url: 'https://t.me/ShegerBot?start=AB12CD34', bot: 'ShegerBot' } } },
    })
    renderApp('/profile')
    await userEvent.click(await screen.findByRole('button', { name: 'Link Telegram' }))
    expect(await screen.findByText('/start AB12CD34')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Open Telegram' })).toHaveAttribute('href', 'https://t.me/ShegerBot?start=AB12CD34')
  })
})

describe('Super Admin reports', () => {
  it('shows income and hotel health', async () => {
    mockApi({
      'GET /me': { body: superAdmin() },
      'GET /admin/reports/platform': {
        body: { data: { income: [{ month: '2026-11', totals: { ETB: '15000.00' }, by_plan: [{ plan: 'Pro', currency: 'ETB', total: '15000.00', payments: 3 }] }], hotels: { active: 2, trial: 1 }, trials_ending: [{ id: 5, name: 'Axum Inn', trial_ends_at: '2026-11-20' }], lost: 0, new_this_month: 1, rooms: 40 } },
      },
      'GET /admin/reports/hotel-health': {
        body: { data: [{ id: 4, name: 'Lalibela Lodge', status: 'active', plan: 'Pro', setup_percent: 100, setup_completed: true, last_activity: null, quiet_days: 15, bookings_30_days: 0, subscription_ends_at: null, health: 'at_risk' }], counts: { at_risk: 1 } },
      },
    })
    renderApp('/admin/reports')
    expect((await screen.findAllByText('ETB 15,000.00')).length).toBeGreaterThan(0)
    expect(screen.getByRole('link', { name: 'Axum Inn' })).toHaveAttribute('href', '/admin/hotels/5')
    expect(await screen.findByText('15 days ago')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'At risk · 1' })).toBeInTheDocument()
    expect(screen.getByRole('navigation', { name: 'Main' })).toHaveTextContent('Reports')
  })
})
