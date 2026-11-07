import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { samePhone } from '@/lib/phone'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const DESK = ['frontdesk.view', 'frontdesk.check_in', 'frontdesk.check_out', 'frontdesk.walk_in', 'frontdesk.room_move', 'frontdesk.quick_check_in', 'guests.create_edit', 'reservations.view', 'payments.take']
const room = { id: 2, number: '102', floor: '1', room_type_id: 3, room_type: 'Standard', nightly_price: '2000.00', day_use_price: null, hourly_price: null, max_capacity: 2, currency: 'ETB' }
const quote = {
  stay_type: 'nightly', check_in_at: '2026-11-12T14:00:00+03:00', check_out_at: '2026-11-13T12:00:00+03:00', currency: 'ETB',
  rooms: [{ room_type_id: 3, room_type: 'Standard', room_id: 2, room_number: '102', adults: 1, children: 0, total: '2000.00', lines: [] }],
  subtotal: '2000.00', discount_percent: '0.00', discount_amount: '0.00', tax_total: '300.00', total: '2300.00', deposit_required: '690.00', policy_name: null,
}
const done = { status: 201, body: { data: { id: 12, status: 'checked_in' } } }
const walkInApi = (extra = {}) => ({
  'GET /me': { body: makeMe({}, DESK) },
  'GET /reservations/lookups': { body: { policies: [], payment_methods: [{ id: 1, name: 'Cash', type: 'cash' }], currencies: [] } },
  'GET /front-desk/walk-in/rooms': { body: { data: [room], walk_in_payment: 'full', now: '2026-11-12T16:00:00+03:00' } },
  'POST /reservations/quote': { body: { data: quote } },
  'POST /front-desk/walk-in': done,
  ...extra,
})
const sent = (fetchMock: ReturnType<typeof mockApi>, end: string) => {
  const call = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith(end) && init?.method === 'POST')
  return call ? JSON.parse(String(call[1]?.body)) : null
}

describe('open stay and faster front desk', () => {
  it('checks in a walk-in who does not know how long they stay', async () => {
    const fetchMock = mockApi(walkInApi())
    renderApp('/rooms/walk-in')

    await userEvent.click(await screen.findByRole('button', { name: /102/ }))
    await userEvent.click(screen.getByRole('checkbox', { name: 'Not sure how long (no end date)' }))
    expect(screen.getByLabelText('Check-out')).toBeDisabled()
    expect(screen.getByText(/adds one night to the bill each day/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('checkbox', { name: 'Quick check-in' }))
    await userEvent.type(screen.getByLabelText('Guest name'), 'Almaz Desta')
    await userEvent.type(screen.getByLabelText('Phone'), '0911000111')
    const button = await screen.findByRole('button', { name: 'Take payment and check in' })
    await waitFor(() => expect(button).toBeEnabled())
    await userEvent.click(button)

    await waitFor(() => expect(sent(fetchMock, '/front-desk/walk-in')?.open_stay).toBe(true))
    expect(sent(fetchMock, '/front-desk/walk-in').payment.amount).toBe('2300.00') // the first night
  })

  it('finds a guest who came before by the phone number', async () => {
    const abebe = { id: 5, full_name: 'Abebe Kebede', first_name: 'Abebe', last_name: 'Kebede', phone: '+251 911 223 344', id_number: null, is_blacklisted: false, reservations_count: 3 }
    const fetchMock = mockApi(walkInApi({ 'GET /guests': { body: { data: [abebe], current_page: 1, last_page: 1, total: 1 } } }))
    renderApp('/rooms/walk-in')

    await userEvent.click(await screen.findByRole('button', { name: /102/ }))
    await userEvent.click(screen.getByRole('checkbox', { name: 'Quick check-in' }))
    await userEvent.type(screen.getByLabelText('Phone'), '0911223344')
    expect(await screen.findByText('Abebe Kebede came before')).toBeInTheDocument()
    expect(screen.getByText(/3 stays/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Use this guest' }))

    const button = await screen.findByRole('button', { name: 'Take payment and check in' })
    await waitFor(() => expect(button).toBeEnabled())
    await userEvent.click(button)
    await waitFor(() => expect(sent(fetchMock, '/front-desk/walk-in')?.guest_id).toBe(5))
    expect(sent(fetchMock, '/front-desk/walk-in').quick_guest).toBeUndefined() // no second copy of the guest
  })

  it('asks "still staying?" on the leaving list: one tap adds a night', async () => {
    const row = (id: number, extra = {}) => ({
      id, code: `BL-26-0000${id}`, status: 'checked_in', stay_type: 'nightly', source: 'walk_in', branch: { id: 1, name: 'Bole branch' },
      guest: { id, name: `Guest ${id}`, phone: null }, check_in_at: '2099-01-01T14:00:00+03:00', check_out_at: '2099-01-03T12:00:00+03:00', expires_at: null,
      rooms: [{ room_type: 'Standard', room_number: `10${id}` }], adults: 1, children: 0, currency: 'ETB', total: '4600.00', paid_total: '4600.00', deposit_required: '0.00',
      late: false, overdue: false, balance: '0.00', ...extra,
    })
    const fetchMock = mockApi({
      'GET /me': { body: makeMe({}, DESK) },
      'GET /front-desk/board': { body: { data: [], counts: {}, refreshed_at: '2099-01-03T09:00:00+03:00' } },
      'GET /front-desk/today': { body: { arrivals: [], departures: [row(1), row(2, { open_stay: true })], in_house: [] } },
      'POST /reservations/1/extend': { body: { data: {} } },
    })
    renderApp('/rooms?tab=departures')

    expect(await screen.findByText(/still staying\?/)).toBeInTheDocument()
    expect(screen.getByText('No end date')).toBeInTheDocument()
    const staying = screen.getAllByRole('button', { name: 'Staying' })
    expect(staying).toHaveLength(1) // the open stay gets its nights by itself
    await userEvent.click(staying[0])
    await waitFor(() => expect(sent(fetchMock, '/reservations/1/extend')).toEqual({ check_out: '2099-01-04' }))
    expect(screen.getAllByRole('link', { name: 'Leaving' })).toHaveLength(2)
  })

  it('knows when two phone numbers are the same', () => {
    expect(samePhone('+251 911 223 344', '0911223344')).toBe(true)
    expect(samePhone('911-22-33-44', '251911223344')).toBe(true)
    expect(samePhone('0911223344', '0911223345')).toBe(false)
    expect(samePhone('12345', '12345')).toBe(false) // too short to be sure
    expect(samePhone(null, '0911223344')).toBe(false)
  })
})
