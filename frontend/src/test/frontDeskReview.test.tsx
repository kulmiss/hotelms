import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const DESK = ['frontdesk.view', 'frontdesk.check_in', 'frontdesk.check_out', 'frontdesk.walk_in', 'rooms.change_status', 'reservations.view', 'invoices.view', 'payments.take', 'charges.room']
const stay = { reservation_id: 9, code: 'BL-26-00001', guest: 'Abebe Kebede', check_in_at: '2026-11-12T14:00:00+03:00', check_out_at: '2026-11-14T12:00:00+03:00', balance: '0.00', overdue: false, stay_type: 'nightly', open_stay: false }
const board = {
  data: [{ id: 1, number: '101', floor: '1', status: 'occupied', room_type_id: 3, room_type: 'Standard', notes: null, stay, arriving: null }],
  counts: { occupied: 1 },
  refreshed_at: '2026-11-12T15:00:00+03:00',
}
const today = { arrivals: [], departures: [], in_house: [] }

describe('front desk review (part 1)', () => {
  it('says "not found" instead of an empty page for a wrong booking link', async () => {
    mockApi({ 'GET /me': { body: makeMe({}, DESK) }, 'GET /reservations/404': { status: 404, body: { message: 'Not found.', code: 'not_found' } } })
    renderApp('/reservations/404')

    expect(await screen.findByText(/Not found\. It may have been removed/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Back to reservations' })).toHaveAttribute('href', '/reservations')
  })

  it('offers Try again when the room board could not load', async () => {
    mockApi({ 'GET /me': { body: makeMe({}, DESK) }, 'GET /front-desk/board': { status: 500, body: { message: 'Server error' } }, 'GET /front-desk/today': { body: today } })
    renderApp('/rooms')

    expect(await screen.findByText(/could not be loaded/, {}, { timeout: 8000 })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument()
  }, 10_000)

  it('gives an occupied room a clear Check out button', async () => {
    mockApi({ 'GET /me': { body: makeMe({}, DESK) }, 'GET /front-desk/board': { body: board }, 'GET /front-desk/today': { body: today }, 'GET /front-desk/quick-charges': { body: { rooms: [], buttons: [] } } })
    renderApp('/rooms')

    await userEvent.click(await screen.findByRole('button', { name: 'Room 101, Occupied' }))
    const panel = (await screen.findByRole('heading', { name: /Room 101/ })).closest('.panel') as HTMLElement
    expect(within(panel).getByRole('link', { name: 'Check out' })).toHaveAttribute('href', '/reservations/9?action=check-out')
    expect(within(panel).getByRole('link', { name: 'Stay details' })).toHaveAttribute('href', '/reservations/9')
  })

  it('shows Charge a room in front desk mode', async () => {
    mockApi({ 'GET /me': { body: makeMe({}, DESK) }, 'GET /front-desk/today': { body: today } })
    renderApp('/front-desk')

    expect(await screen.findByRole('link', { name: /Charge a room/ })).toHaveAttribute('href', '/charge')
  })

  it('hides the search box from a role with nothing to search (Restaurant / Bar)', async () => {
    mockApi({ 'GET /me': { body: makeMe({}, ['charges.room']) }, 'GET /front-desk/quick-charges': { body: { rooms: [], buttons: [] } } })
    renderApp('/charge')

    expect(await screen.findByRole('heading', { name: 'Charge a room' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Search guests/ })).not.toBeInTheDocument()
  })

  it('tells reception when a walk-in after midnight pays for last night', async () => {
    mockApi({
      'GET /me': { body: makeMe({}, DESK) },
      'GET /reservations/lookups': { body: { policies: [], payment_methods: [{ id: 1, name: 'Cash', type: 'cash' }], currencies: [] } },
      'GET /front-desk/walk-in/rooms': { body: { data: [], walk_in_payment: 'full', now: '2000-01-02T00:20:00+03:00', night_date: '2000-01-01' } },
    })
    renderApp('/rooms/walk-in')

    expect(await screen.findByText(/After midnight: this guest pays for last night/)).toBeInTheDocument()
  })
})
