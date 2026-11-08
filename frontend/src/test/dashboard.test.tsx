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
