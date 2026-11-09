import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { rangeDates } from '@/lib/dashboard'
import { healthOk, makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const kpis = { rooms: 2, available_nights: 60, room_nights: 40, occupancy: 66.7, room_revenue: '80000.00', adr: '2000.00', revpar: '1333.33', extras: '3000.00', fees: '0.00', discounts: '0.00', cancellation_fees: '0.00', revenue: '83000.00', tax: '12450.00', payments: '90000.00', bookings: 20, cancellation_rate: 5, lead_time: 3, average_stay: 2 }
const today = {
  date: '2026-11-12', arrivals: 4, arrived: 1, departures: 2, departed: 0, in_house: 7, occupancy: 58.3,
  rooms: { total: 12, by_status: { occupied: 7, available: 4, dirty: 1 } }, pending_expiring: 1, late_arrivals: 2,
  next_7_days: [{ date: '2026-11-12', occupancy: 58.3 }], next_days: [{ date: '2026-11-12', occupancy: 58.3 }, { date: '2026-11-13', occupancy: 90 }],
}
const money = { revenue_today: '12000.00', payments_today: '8000.00', unpaid_balances: '3000.00', cashier_shortages: 0, month: kpis }
const day = (date: string, extra = {}) => ({ date, arrivals: 2, departures: 1, in_house: 7, occupancy: 58.3, ...extra })
const leaving = {
  id: 9, code: 'BL-26-00009', status: 'checked_in', stay_type: 'nightly', source: 'phone', branch: { id: 1, name: 'Bole branch' }, guest: { id: 3, name: 'Saba Haile', phone: null },
  check_in_at: '2026-11-10T14:00:00+03:00', check_out_at: '2026-11-12T12:00:00+03:00', expires_at: null, rooms: [{ room_type: 'Standard', room_number: '405' }],
  adults: 2, children: 0, currency: 'ETB', total: '8855.00', paid_total: '4427.50', late: false, overdue: true, balance: '4427.50',
}

describe('hotel dashboard (P18)', () => {
  it('shows money KPIs, the Revenue tab and what needs attention to a manager', async () => {
    mockApi({
      'GET /me': { body: makeMe({}, ['dashboard.view', 'dashboard.view_revenue', 'reports.revenue', 'reports.outstanding']) },
      'GET /health': healthOk,
      'GET /dashboard': { body: { data: { ...today, ...money } } },
      'GET /dashboard/trend': { body: { data: [day('2026-11-11', { revenue: '4000.00', adr: '2000.00', revpar: '1333.33', payments: '3000.00' }), day('2026-11-12', { revenue: '5000.00', adr: '2100.00', revpar: '1400.00', payments: '6000.00' })], rooms: 12, currency: 'ETB' } },
      'GET /reports/performance': { body: { data: { kpis, compare: { ...kpis, adr: '1600.00' }, daily: [], room_types: [], sources: [], currency: 'ETB' } } },
      'GET /reports/outstanding': { body: { data: { rows: [{ id: 5, number: 'INV-1', status: 'open', guest: 'Abebe', phone: null, reservation: 'BL-1', reservation_id: 1, stay_status: 'checked_in', total: '3000.00', balance: '3000.00', currency: 'ETB', issued_at: '2026-11-10', age_days: 2 }], currency: 'ETB' } } },
    })
    renderApp('/')
    expect(await screen.findByText('ETB 2,000.00')).toBeInTheDocument() // ADR
    expect(screen.getByText('25%')).toBeInTheDocument() // ADR up from 1,600
    expect(screen.getAllByText('ETB 3,000.00').length).toBeGreaterThan(0) // outstanding
    expect(screen.getByText('4 · 2')).toBeInTheDocument() // arriving · leaving
    expect(screen.getByRole('tab', { name: 'Revenue' })).toBeInTheDocument()
    const attention = screen.getByRole('heading', { name: 'Needs attention' }).closest('section') as HTMLElement
    expect(within(attention).getByText('Unpaid bookings expiring')).toBeInTheDocument()
    expect(within(attention).getByText('Late arrivals')).toBeInTheDocument()
  })

  it('shows no money to a receptionist and checks out from the list in a side sheet', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: makeMe({}, ['dashboard.view', 'frontdesk.view', 'frontdesk.check_out', 'payments.take', 'reservations.view']) },
      'GET /dashboard': { body: { data: today } },
      'GET /dashboard/trend': { body: { data: [day('2026-11-11'), day('2026-11-12')], rooms: 12, currency: 'ETB' } },
      'GET /front-desk/today': { body: { arrivals: [], departures: [leaving], in_house: [leaving] } },
      'GET /reservations/9': { body: { data: { ...leaving, guest: { id: 3, first_name: 'Saba', last_name: 'Haile', full_name: 'Saba Haile' }, rooms: [{ id: 1, room_type_id: 1, room_type: 'Standard', room_id: 4, room_number: '405', adults: 2, children: 0, total: '8855.00' }], balance: '4427.50' } } },
    })
    renderApp('/')
    expect(await screen.findByText('Occupancy over time')).toBeInTheDocument()
    expect(screen.queryByText('ADR')).not.toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'Revenue' })).not.toBeInTheDocument()
    expect(fetchMock.mock.calls.some(([u]) => String(u).includes('/reports/'))).toBe(false) // no report questions for this role

    await userEvent.click(await screen.findByRole('tab', { name: /Leaving/ }))
    const row = (await screen.findAllByText('Saba Haile'))[0].closest('tr, li') as HTMLElement
    await userEvent.click(within(row).getByRole('button', { name: 'Check out' }))
    const sheet = await screen.findByRole('dialog')
    await waitFor(() => expect(fetchMock.mock.calls.some(([u]) => String(u).endsWith('/reservations/9'))).toBe(true))
    expect(await within(sheet).findByText('BL-26-00009 · 405 · Standard')).toBeInTheDocument()
  })

  it('asks every widget again for the new period', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: makeMe({}, ['dashboard.view']) },
      'GET /dashboard': { body: { data: today } },
      'GET /dashboard/trend': { body: { data: [day('2026-11-12')], rooms: 12, currency: 'ETB' } },
    })
    renderApp('/')
    await screen.findByText('Occupancy over time')
    await userEvent.click(screen.getByRole('button', { name: '7 days' }))
    const { from, to } = rangeDates('7')
    await waitFor(() => expect(fetchMock.mock.calls.some(([u]) => String(u).includes(`/dashboard/trend?from=${from}&to=${to}`))).toBe(true))
    expect(screen.getByRole('button', { name: '7 days' })).toHaveAttribute('aria-pressed', 'true')
  })

  it('keeps the page working when one widget fails', async () => {
    mockApi({
      'GET /me': { body: makeMe({}, ['dashboard.view']) },
      'GET /dashboard': { body: { data: today } },
      'GET /dashboard/trend': { status: 403, body: { message: 'Not allowed' } },
    })
    renderApp('/')
    expect((await screen.findAllByText('This part could not load. The rest of the page still works.')).length).toBeGreaterThan(0)
    expect(screen.getByRole('heading', { name: 'Room status now' })).toBeInTheDocument()
    expect(screen.getByRole('img', { name: 'Room status of 12 rooms' })).toBeInTheDocument()
  })
})

describe('Super Admin command center (P18)', () => {
  const superAdmin = () => ({ ...makeMe({ is_super_admin: true, name: 'Owner' }, []), role: null, hotel: null, branches: [], platform: { role: 'owner', permissions: ['hotels.view', 'system.view'] } })
  it('shows hotels by plan, health and the system, and opens a hotel in place', async () => {
    mockApi({
      'GET /me': { body: superAdmin() },
      'GET /admin/dashboard': { body: { hotels: { total: 1, by_status: { trial: 1, active: 0, grace: 0, suspended: 0 } }, income_this_month: {}, trials_ending_soon: [], needs_attention: [], today: { bookings: 0, money: {}, occupancy: 0, occupied: 0, rooms: 0, staff_online: 0, open_support: 0 }, top_hotels: [], activity: [] } },
      'GET /admin/reports/platform': { body: { data: { income: [{ month: '2026-11', totals: [], by_plan: [] }], hotels: { trial: 1 }, trials_ending: [], lost: 0, new_this_month: 1, rooms: 0, signups: [{ month: '2026-11', count: 1 }], churned: [{ month: '2026-11', count: 0 }], by_plan: [{ plan: 'Basic', hotels: 1 }] } } },
      'GET /admin/reports/hotel-health': { body: { data: [{ id: 2, name: 'Safari', status: 'trial', plan: 'Basic', setup_percent: 8, setup_completed: false, last_activity: null, quiet_days: null, bookings_30_days: 0, subscription_ends_at: null, health: 'watch' }], counts: { watch: 1 } } },
      'GET /admin/system': { body: { data: { checks: { database: { ok: true }, cache: { ok: true, driver: 'redis' }, scheduler: { ok: true, last_run: null }, jobs: { ok: true, waiting: 0, failed: 0, recent_failures: [] }, backups: { ok: true, daily: null, small: null }, disk: { ok: true, free_mb: 1000 } }, errors: [], versions: { app: '1', php: '8.3', laravel: '13', database: null }, checked_at: '' } } },
    })
    renderApp('/admin')
    expect(await screen.findByText('No subscription payments yet. Bars appear when a hotel pays.')).toBeInTheDocument()
    expect(screen.getByText('Basic')).toBeInTheDocument()
    expect((await screen.findAllByText('All OK')).length).toBeGreaterThan(0)
    await userEvent.click(await screen.findByRole('button', { name: /Safari/ }))
    const sheet = await screen.findByRole('dialog')
    expect(within(sheet).getByRole('link', { name: /Open the hotel page/ })).toHaveAttribute('href', '/admin/hotels/2')
  })
})

describe('module overviews (P18)', () => {
  const meWith = (permissions: string[]) => {
    const me = makeMe({}, permissions)
    return { ...me, hotel: { ...(me.hotel as NonNullable<typeof me.hotel>), modules: ['housekeeping', 'cashier_close'] } }
  }
  const shift = (id: number, status: string, difference: string | null) => ({
    id, status, user: { id: 1, name: 'Hana' }, branch: { id: 1, name: 'Bole branch' }, opened_at: '2026-11-11T07:05:00+03:00', closed_at: null, approved_by: null, approved_at: null, notes: null,
    balances: [{ currency: 'ETB', opening_amount: '2000.00', expected_amount: null, counted_amount: null, difference }],
  })

  it('adds up short and over on the cashier page', async () => {
    mockApi({
      'GET /me': { body: meWith(['cashier.view_all']) },
      'GET /cashier/sessions': { body: { data: [shift(3, 'open', null), shift(2, 'closed', '-150.00'), shift(1, 'approved', '50.00')], current_page: 1, last_page: 1, total: 3, per_page: 20 } },
    })
    renderApp('/cashier')
    expect(await screen.findByText('-ETB 100.00')).toBeInTheDocument()
    expect(screen.getByText('1 shift was short')).toBeInTheDocument()
    expect(screen.getByText('Closed, waiting for approval')).toBeInTheDocument()
  })

  it('shows rooms to clean and who is cleaning on the housekeeping page', async () => {
    const task = (id: number, status: string, room: string) => ({ id, type: 'cleaning', priority: 'normal', status, notes: null, room: { id, number: room, floor: '1', status: 'dirty' }, assigned_to: { id: 5, name: 'Mulu Girma' }, due_at: null, started_at: null, completed_at: null })
    mockApi({
      'GET /me': { body: meWith(['housekeeping.view', 'housekeeping.assign']) },
      'GET /housekeeping/board': { body: { data: [{ id: 1, number: '401', floor: '4', status: 'dirty', room_type: 'Standard', tasks: [] }, { id: 2, number: '402', floor: '4', status: 'available', room_type: 'Standard', tasks: [] }] } },
      'GET /housekeeping/tasks': { body: { data: [task(1, 'in_progress', '401'), task(2, 'pending', '301')] } },
      'GET /housekeeping/staff': { body: { data: [] } },
    })
    renderApp('/housekeeping')
    expect(await screen.findByRole('heading', { name: 'Who is cleaning' })).toBeInTheDocument()
    expect(await screen.findByText('401 in progress · 301 to do')).toBeInTheDocument()
  })

  it('opens Reports on an overview with the main numbers for managers', async () => {
    mockApi({
      'GET /me': { body: makeMe({}, ['reports.revenue', 'reports.my_shift', 'cashier.own_shift']) },
      'GET /reports/performance': { body: { data: { kpis, compare: { ...kpis, bookings: 10 }, daily: [], room_types: [], sources: [], from: '2026-11-01', to: '2026-11-12', currency: 'ETB', compare_range: null } } },
    })
    renderApp('/reports')
    expect(await screen.findByText('Bookings made')).toBeInTheDocument()
    expect(await screen.findByText('100%')).toBeInTheDocument() // 20 bookings, up from 10
    await userEvent.click(screen.getAllByRole('button', { name: /Revenue and performance/ })[0])
    expect(screen.getByRole('button', { name: 'Revenue and performance', current: 'page' })).toBeInTheDocument()
  })
})
