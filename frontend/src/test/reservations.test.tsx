import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const FRONT = ['reservations.view', 'reservations.create', 'reservations.edit', 'reservations.cancel', 'reservations.no_show', 'guests.view', 'guests.create_edit', 'payments.take']
const me = () => makeMe({}, FRONT)

const guest = { id: 5, first_name: 'Abebe', last_name: 'Kebede', full_name: 'Abebe Kebede', phone: '0911223344', email: null, nationality: null, id_type: 'national_id', id_number: 'A1', gender: null, date_of_birth: null, notes: null, guest_type_id: null, is_blacklisted: false, blacklist_reason: null, reservations_count: 2 }
const page = <T,>(data: T[]) => ({ data, current_page: 1, last_page: 1, total: data.length, per_page: 25 })

const quote = {
  stay_type: 'nightly', check_in_at: '2030-01-10T14:00:00+03:00', check_out_at: '2030-01-12T12:00:00+03:00', currency: 'ETB',
  rooms: [{ room_type_id: 3, room_type: 'Standard', room_id: null, room_number: null, adults: 2, children: 0, total: '4000.00', lines: [{ date: '2030-01-10', label: 'Night', amount: '2000.00', rule_name: null }, { date: '2030-01-11', label: 'Night', amount: '2000.00', rule_name: null }] }],
  subtotal: '4000.00', discount_percent: '0.00', discount_amount: '0.00', tax_total: '600.00', total: '4600.00', deposit_required: '1380.00', policy_name: 'Standard',
}

const detail = {
  id: 9, code: 'BL-30-00001', status: 'pending', stay_type: 'nightly', source: 'phone', branch: { id: 1, name: 'Bole branch' }, guest,
  check_in_at: '2030-01-10T14:00:00+03:00', check_out_at: '2030-01-12T12:00:00+03:00', expires_at: '2030-01-01T10:00:00+03:00',
  adults: 2, children: 0, currency: 'ETB', total: '4600.00', paid_total: '0.00', deposit_required: '1380.00', notes: null,
  subtotal: '4000.00', discount_percent: '0.00', discount_amount: '0.00', tax_total: '600.00', balance: '4600.00', cancellation_fee: null, cancellation_policy_id: 2,
  policy: { name: 'Standard', free_until_hours: 48, penalty_type: 'nights', penalty_value: '1.00', applies_to_no_show: true },
  cancelled_at: null, cancel_reason: null, created_by: 'Meron', created_at: '2029-12-31T10:00:00+03:00',
  rooms: [{ id: 1, room_type_id: 3, room_type: 'Standard', room_id: null, room_number: null, adults: 2, children: 0, total: '4000.00', status: 'pending', lines: quote.rooms[0].lines, guests: [] }],
  payments: [],
}
const lookups = { policies: [{ id: 2, name: 'Standard', free_until_hours: 48, penalty_type: 'nights', penalty_value: '1.00', is_default: true }], payment_methods: [{ id: 1, name: 'Cash', type: 'cash' }], currencies: [{ code: 'ETB', symbol: 'Br', is_default: true }] }

describe('guests', () => {
  it('lists and searches guests', async () => {
    const fetchMock = mockApi({ 'GET /me': { body: me() }, 'GET /guests': { body: page([guest]) } })
    renderApp('/guests')
    expect(await screen.findByText('Abebe Kebede')).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Search name, phone or ID'), '0911')
    await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => String(url).includes('q=0911'))).toBe(true))
  })
})

describe('reservations', () => {
  it('books rooms for a guest with a live price', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: me() },
      'GET /reservations/lookups': { body: lookups },
      'GET /reservations/availability': {
        body: { data: [{ room_type_id: 3, name: 'Standard', max_capacity: 2, base_capacity: 2, total_rooms: 2, available: 2, offered: true, price: '4000.00', lines: [], currency: 'ETB', free_rooms: [{ id: 11, number: '101', floor: '1', status: 'available' }] }], check_in_at: quote.check_in_at, check_out_at: quote.check_out_at },
      },
      'POST /reservations/quote': { body: { data: quote } },
      'GET /guests': { body: page([guest]) },
      'POST /reservations': { status: 201, body: { data: detail } },
      'GET /reservations/9': { body: { data: detail } },
    })
    renderApp('/reservations/new')

    await userEvent.click(await screen.findByRole('button', { name: 'Add' }))
    expect(await screen.findByText('ETB 4,600.00')).toBeInTheDocument()
    expect(screen.getByText('ETB 1,380.00')).toBeInTheDocument() // deposit to confirm

    await userEvent.type(screen.getAllByLabelText('Search name, phone or ID')[0], 'Abebe')
    await userEvent.click(await screen.findByRole('button', { name: /Abebe Kebede/ }))
    await userEvent.click(screen.getByRole('button', { name: 'Book' }))

    expect(await screen.findByRole('heading', { name: 'BL-30-00001' })).toBeInTheDocument()
    const call = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/reservations') && init?.method === 'POST')
    expect(JSON.parse(String(call?.[1]?.body))).toMatchObject({ branch_id: 1, stay_type: 'nightly', guest_id: 5, rooms: [{ room_type_id: 3, adults: 2, children: 0 }] })
  })

  it('books an actual room from the "By room" tab', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: me() },
      'GET /reservations/lookups': { body: lookups },
      'GET /reservations/availability': {
        body: { data: [{ room_type_id: 3, name: 'Standard', max_capacity: 2, base_capacity: 2, total_rooms: 2, available: 2, offered: true, price: '4000.00', lines: [], currency: 'ETB', free_rooms: [{ id: 11, number: '101', floor: '1', status: 'available' }, { id: 12, number: '102', floor: '1', status: 'available' }] }], check_in_at: quote.check_in_at, check_out_at: quote.check_out_at },
      },
      'POST /reservations/quote': { body: { data: quote } },
    })
    renderApp('/reservations/new')

    await userEvent.click(await screen.findByRole('tab', { name: 'By room' }))
    await userEvent.click(await screen.findByRole('button', { name: /^102/ }))
    expect(screen.getByRole('button', { name: /^102/ })).toHaveAttribute('aria-pressed', 'true')
    await waitFor(() => {
      const call = [...fetchMock.mock.calls].reverse().find(([url]) => String(url).endsWith('/reservations/quote'))
      expect(JSON.parse(String(call?.[1]?.body))).toMatchObject({ rooms: [{ room_type_id: 3, room_id: 12, adults: 2, children: 0 }] })
    })
  })

  it('records a deposit on a booking', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: me() },
      'GET /reservations/9': { body: { data: detail } },
      'GET /reservations/lookups': { body: lookups },
      'POST /reservations/9/payments': { status: 201, body: { data: { ...detail, status: 'confirmed', paid_total: '1380.00' } } },
    })
    renderApp('/reservations/9')
    expect(await screen.findByText('ETB 1,380.00 more deposit confirms this booking.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Record payment' }))
    expect(screen.getByLabelText('Amount')).toHaveValue('1380.00')
    await userEvent.click(screen.getByRole('button', { name: 'Save payment' }))

    await waitFor(() => {
      const call = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/reservations/9/payments'))
      expect(JSON.parse(String(call?.[1]?.body))).toMatchObject({ type: 'deposit', payment_method_id: 1, amount: '1380.00', currency: 'ETB' })
    })
  })

  it('shows the cancellation fee before cancelling', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: me() },
      'GET /reservations/9': { body: { data: detail } },
      'GET /reservations/9/cancellation-terms': { body: { data: { fee: '2000.00', refund_due: '0.00', free: false, hours_before: 20, policy: detail.policy } } },
      'POST /reservations/9/cancel': { body: { data: { ...detail, status: 'cancelled' } } },
    })
    renderApp('/reservations/9')
    await userEvent.click(await screen.findByRole('button', { name: 'Cancel booking' }))
    expect(await screen.findByText(/Cancelling now costs ETB 2,000.00 \(Standard\)\./)).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Reason'), 'Plans changed')
    const buttons = screen.getAllByRole('button', { name: 'Cancel booking' })
    await userEvent.click(buttons[buttons.length - 1])
    await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => String(url).endsWith('/reservations/9/cancel'))).toBe(true))
  })
})
