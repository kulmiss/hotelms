import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { healthOk, makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const kpis = { rooms: 2, available_nights: 6, room_nights: 2, occupancy: 33.3, room_revenue: '4000.00', adr: '2000.00', revpar: '666.67', extras: '300.00', fees: '0.00', discounts: '0.00', cancellation_fees: '0.00', revenue: '4300.00', tax: '645.00', payments: '4945.00', bookings: 2, cancellation_rate: 50, lead_time: 1, average_stay: 2 }

describe('dashboard and reports', () => {
  it('shows today on the dashboard with money for managers', async () => {
    mockApi({
      'GET /me': { body: makeMe({}, ['dashboard.view', 'dashboard.view_revenue']) },
      'GET /health': healthOk,
      'GET /dashboard': { body: { data: { date: '2026-11-12', arrivals: 4, arrived: 1, departures: 2, departed: 0, in_house: 7, occupancy: 58.3, rooms: { total: 12, by_status: { occupied: 7, available: 5 } }, pending_expiring: 1, late_arrivals: 0, next_7_days: [{ date: '2026-11-12', occupancy: 58.3 }], revenue_today: '12000.00', payments_today: '8000.00', unpaid_balances: '3000.00', cashier_shortages: 0, month: kpis } } },
    })
    renderApp('/')
    expect(await screen.findByText('Arrivals today')).toBeInTheDocument()
    expect(screen.getByText('1 unpaid booking expires within 3 hours.')).toBeInTheDocument()
    expect(screen.getByText('ETB 3,000.00')).toBeInTheDocument()
  })

  it('shows KPIs with the change and the bookings behind a day', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: makeMe({}, ['reports.revenue', 'reports.export']) },
      'GET /reports/performance': {
        body: { data: { kpis, compare: { ...kpis, occupancy: 25 }, daily: [{ date: '2026-11-12', room_nights: 1, occupancy: 50, room_revenue: '2000.00', adr: '2000.00', revenue: '2300.00', payments: '1380.00' }], room_types: [], sources: [], from: '2026-11-01', to: '2026-11-12', currency: 'ETB', compare_range: ['2026-10-20', '2026-10-31'] } },
      },
      'GET /reports/drilldown': { body: { data: [{ reservation_id: 9, code: 'BL-26-00001', guest: 'Abebe', room: '101', amount: '2000.00' }] } },
    })
    renderApp('/reports?report=performance')
    expect(await screen.findByText('33.3%')).toBeInTheDocument()
    expect(screen.getByText('33.2%')).toBeInTheDocument() // up from 25%
    expect(screen.getByRole('link', { name: 'Excel' })).toHaveAttribute('href', expect.stringContaining('format=xlsx'))
    await userEvent.click(screen.getByRole('listitem', { name: /12 Nov 2026/ }))
    expect(await screen.findByText(/BL-26-00001/)).toBeInTheDocument()
    await waitFor(() => expect(fetchMock.mock.calls.some(([u]) => String(u).includes('metric=room_nights'))).toBe(true))
  })
})

describe('personal and insight reports', () => {
  it('shows a receptionist only their own shift summary', async () => {
    mockApi({
      'GET /me': { body: makeMe({}, ['reports.my_shift']) },
      'GET /reports/my-shift': { body: { data: { from: '2026-11-12T08:00:00+03:00', to: '2026-11-12T16:00:00+03:00', session: null, sessions: [], check_ins: 3, check_outs: 2, walk_ins: 1, bookings: 4, payments: [{ method: 'Cash', currency: 'ETB', count: 2, amount: '5000.00', amount_in_base: '5000.00' }], payments_total: '5000.00', discounts: '0.00', max_discount_percent: '5.00', waived: 0, timeline: [], currency: 'ETB' } } },
    })
    renderApp('/reports')
    expect(await screen.findByText('Check-ins')).toBeInTheDocument()
    expect(screen.getByRole('navigation', { name: 'Reports' })).not.toHaveTextContent('Revenue and performance')
    expect(screen.getAllByText('ETB 5,000.00').length).toBeGreaterThan(0)
  })

  it('opens the daily report first for people who do not work the desk (Accountant)', async () => {
    const fetchMock = mockApi({ 'GET /me': { body: makeMe({}, ['reports.my_shift', 'reports.revenue']) } })
    renderApp('/reports')
    await waitFor(() => expect(fetchMock.mock.calls.some(([u]) => String(u).includes('/reports/daily'))).toBe(true))
    expect(fetchMock.mock.calls.some(([u]) => String(u).includes('/reports/my-shift'))).toBe(false)
  })

  it('flags people in the staff audit', async () => {
    mockApi({
      'GET /me': { body: makeMe({}, ['reports.audit']) },
      'GET /reports/audit': { body: { data: { rows: [{ user_id: 4, name: 'Hana', discounts: '900.00', discount_count: 6, waived: '0.00', waived_count: 0, voids: 3, void_value: '9000.00', refunds: '0.00', refund_count: 0, cash_short: '-250.00', cash_over: '0.00', flags: ['cash_short', 'voids'] }], from: '2026-11-01', to: '2026-11-12', currency: 'ETB' } } },
    })
    renderApp('/reports?report=audit')
    expect(await screen.findByText('1 person needs a look.')).toBeInTheDocument()
    expect(screen.getByText('Cash short over 200')).toBeInTheDocument()
    expect(screen.getByText('Many voids')).toBeInTheDocument()
  })
})
