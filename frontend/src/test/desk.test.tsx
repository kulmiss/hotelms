import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const DESK = ['frontdesk.view', 'frontdesk.check_in', 'frontdesk.check_out', 'frontdesk.walk_in', 'frontdesk.room_move', 'rooms.change_status', 'fees.waive', 'reservations.view', 'invoices.view', 'invoices.add_charge', 'invoices.print', 'payments.take']
const me = () => makeMe({}, DESK)
const stay = { reservation_id: 9, code: 'BL-26-00001', guest: 'Abebe Kebede', check_in_at: '2026-11-12T14:00:00+03:00', check_out_at: '2026-11-14T12:00:00+03:00', balance: '3220.00', overdue: false }
const board = {
  data: [
    { id: 1, number: '101', floor: '1', status: 'occupied', room_type_id: 3, room_type: 'Standard', notes: null, stay, arriving: null },
    { id: 2, number: '102', floor: '1', status: 'dirty', room_type_id: 3, room_type: 'Standard', notes: null, stay: null, arriving: null },
  ],
  counts: { occupied: 1, dirty: 1 },
  refreshed_at: '2026-11-12T15:00:00+03:00',
}
const today = { arrivals: [], departures: [], in_house: [] }

const reservation = {
  id: 9, code: 'BL-26-00001', status: 'confirmed', stay_type: 'nightly', source: 'phone', branch: { id: 1, name: 'Bole branch' },
  guest: { id: 5, full_name: 'Abebe Kebede', phone: null }, check_in_at: '2020-01-01T14:00:00+03:00', check_out_at: '2099-01-03T12:00:00+03:00', expires_at: null,
  adults: 2, children: 0, currency: 'ETB', total: '4600.00', paid_total: '1380.00', deposit_required: '1380.00', notes: null, subtotal: '4000.00',
  discount_percent: '0.00', discount_amount: '0.00', tax_total: '600.00', balance: '3220.00', cancellation_fee: null, cancellation_policy_id: 2, policy: null,
  cancelled_at: null, cancel_reason: null, created_by: 'Meron', created_at: '2026-11-10T09:00:00+03:00',
  rooms: [{ id: 21, room_type_id: 3, room_type: 'Standard', room_id: null, room_number: null, adults: 2, children: 0, total: '4000.00', status: 'confirmed', lines: [], guests: [] }],
  invoices: [], payments: [],
}

const invoice = {
  id: 4, number: 'INV-2026-00001', status: 'partially_paid', reservation: { id: 9, code: 'BL-26-00001' }, guest: { id: 5, full_name: 'Abebe Kebede', phone: null, email: null, id_number: null, nationality: null },
  branch: { id: 1, name: 'Bole branch' }, currency: 'ETB', total: '4600.00', paid_total: '1380.00', balance: '3220.00', issued_at: '2026-11-12T15:00:00+03:00',
  subtotal: '4000.00', discount_total: '0.00', tax_total: '600.00', reservation_room_id: null, outstanding_approved_at: null, voided_at: null, void_reason: null,
  stay: { check_in_at: '2026-11-12T14:00:00+03:00', check_out_at: '2026-11-14T12:00:00+03:00', status: 'checked_in', rooms: [{ id: 21, room_number: '101', room_type: 'Standard' }] },
  items: [{ id: 1, type: 'room', description: 'Room 101 · Standard · Night', quantity: '1.00', unit_price: '2000.00', amount: '2000.00', is_waived: false, service_date: '2026-11-12', reservation_room_id: 21 }],
  taxes: [{ name: 'VAT', rate: '15.00', is_inclusive: false, amount: '600.00' }], payments: [],
  hotel: { name: 'Sheger Hotel', legal_name: null, tin: '0012345678', vat_number: null, address: null, city: 'Addis Ababa', phone: null, email: null, logo_url: null, footer: 'Thank you' },
}

describe('front desk', () => {
  it('shows the live room board and marks a dirty room clean', async () => {
    const fetchMock = mockApi({ 'GET /me': { body: me() }, 'GET /front-desk/board': { body: board }, 'GET /front-desk/today': { body: today }, 'POST /rooms/2/status': { body: { data: {} } } })
    renderApp('/rooms')

    expect(await screen.findByText('Abebe Kebede')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Room 102, Dirty' }))
    await userEvent.click(screen.getByRole('button', { name: 'Mark Available' }))
    await waitFor(() => {
      const call = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/rooms/2/status'))
      expect(JSON.parse(String(call?.[1]?.body))).toEqual({ status: 'available' })
    })
  })

  it('checks a guest in to a chosen clean room', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: me() },
      'GET /reservations/9': { body: { data: reservation } },
      'GET /reservations/lookups': { body: { policies: [], payment_methods: [{ id: 1, name: 'Cash', type: 'cash' }], currencies: [] } },
      'GET /reservations/9/check-in': { body: { data: { reservation, rooms: [{ id: 21, room_type: 'Standard', room_id: null, room_number: null, room_status: null, free_rooms: [{ id: 1, number: '101', floor: '1' }], early_fee: '1000.00' }], deposit_due: '0.00' } } },
      'POST /reservations/9/check-in': { body: { data: { ...reservation, status: 'checked_in' } } },
    })
    renderApp('/reservations/9?action=check-in')

    expect(await screen.findByText('Early check-in fee: ETB 1,000.00')).toBeInTheDocument()
    await userEvent.selectOptions(screen.getByLabelText('Room number'), '101')
    await userEvent.click(screen.getByRole('checkbox', { name: /Waive/ }))
    const buttons = screen.getAllByRole('button', { name: 'Check in' })
    await userEvent.click(buttons[buttons.length - 1])

    await waitFor(() => {
      const call = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/reservations/9/check-in') && init?.method === 'POST')
      expect(JSON.parse(String(call?.[1]?.body))).toEqual({ rooms: [{ id: 21, room_id: 1 }], waive_early_fee: true, payment: null })
    })
  })
})

describe('quick check-in', () => {
  it('checks a walk-in in with only name and phone', async () => {
    const quote = {
      stay_type: 'nightly', check_in_at: '2026-11-12T14:00:00+03:00', check_out_at: '2026-11-13T12:00:00+03:00', currency: 'ETB',
      rooms: [{ room_type_id: 3, room_type: 'Standard', room_id: 2, room_number: '102', adults: 1, children: 0, total: '2000.00', lines: [] }],
      subtotal: '2000.00', discount_percent: '0.00', discount_amount: '0.00', tax_total: '300.00', total: '2300.00', deposit_required: '690.00', policy_name: null,
    }
    const fetchMock = mockApi({
      'GET /me': { body: makeMe({}, [...DESK, 'frontdesk.quick_check_in', 'guests.create_edit']) },
      'GET /reservations/lookups': { body: { policies: [], payment_methods: [{ id: 1, name: 'Cash', type: 'cash' }], currencies: [] } },
      'GET /front-desk/walk-in/rooms': { body: { data: [{ id: 2, number: '102', floor: '1', room_type_id: 3, room_type: 'Standard', nightly_price: '2000.00', day_use_price: null, hourly_price: null, max_capacity: 2, currency: 'ETB' }], walk_in_payment: 'full', now: '2026-11-12T16:00:00+03:00' } },
      'POST /reservations/quote': { body: { data: quote } },
      'POST /front-desk/walk-in': { status: 201, body: { data: { ...reservation, id: 12, status: 'checked_in' } } },
    })
    renderApp('/rooms/walk-in')

    await userEvent.click(await screen.findByRole('button', { name: /102/ }))
    await userEvent.click(screen.getByRole('checkbox', { name: 'Quick check-in' }))
    await userEvent.type(screen.getByLabelText('Guest name'), 'Almaz Desta')
    await userEvent.type(screen.getByLabelText('Phone'), '0911000111')
    const button = await screen.findByRole('button', { name: 'Take payment and check in' })
    await waitFor(() => expect(button).toBeEnabled())
    await userEvent.click(button)

    await waitFor(() => {
      const call = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/front-desk/walk-in') && init?.method === 'POST')
      const body = JSON.parse(String(call?.[1]?.body))
      expect(body.quick_guest).toEqual({ name: 'Almaz Desta', phone: '0911000111' })
      expect(body.guest_id).toBeUndefined()
      expect(body.payment.amount).toBe('2300.00')
    })
  })

  it('hides quick check-in without the permission', async () => {
    mockApi({
      'GET /me': { body: makeMe({}, [...DESK, 'guests.create_edit']) },
      'GET /reservations/lookups': { body: { policies: [], payment_methods: [], currencies: [] } },
      'GET /front-desk/walk-in/rooms': { body: { data: [], walk_in_payment: 'full', now: '2026-11-12T16:00:00+03:00' } },
    })
    renderApp('/rooms/walk-in')
    expect(await screen.findByRole('heading', { name: 'Guest' })).toBeInTheDocument()
    expect(screen.queryByRole('checkbox', { name: 'Quick check-in' })).not.toBeInTheDocument()
  })
})

describe('billing', () => {
  it('adds an extra charge to a bill', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: me() },
      'GET /invoices/4': { body: { data: invoice } },
      'GET /charge-types': { body: { data: [{ id: 7, name: 'Laundry', default_price: '150.00', currency: 'ETB' }] } },
      'POST /invoices/4/items': { status: 201, body: { data: invoice } },
    })
    renderApp('/billing/4')

    expect(await screen.findByText('Room 101 · Standard · Night')).toBeInTheDocument()
    expect(screen.getByText('VAT 15%')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Add charge' }))
    await userEvent.selectOptions(screen.getByLabelText('Charge'), 'Laundry')
    await userEvent.clear(screen.getByLabelText('Qty'))
    await userEvent.type(screen.getByLabelText('Qty'), '2')
    const buttons = screen.getAllByRole('button', { name: 'Add charge' })
    await userEvent.click(buttons[buttons.length - 1])

    await waitFor(() => {
      const call = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/invoices/4/items'))
      expect(JSON.parse(String(call?.[1]?.body))).toMatchObject({ type: 'extra', charge_type_id: 7, quantity: '2', unit_price: '150' })
    })
  })

  it('prints an invoice with the hotel TIN', async () => {
    mockApi({ 'GET /me': { body: me() }, 'GET /invoices/4': { body: { data: invoice } } })
    renderApp('/billing/4/print')
    expect(await screen.findByText('TIN: 0012345678')).toBeInTheDocument()
    expect(screen.getByText('Thank you')).toBeInTheDocument()
  })

  it('finds a booking from the search box and opens it with the keyboard', async () => {
    const hit = { type: 'booking', title: 'BL-26-00001 · Abebe Kebede', subtitle: 'confirmed · 12 Nov 2026', link: '/reservations/9' }
    mockApi({
      'GET /me': { body: me() }, 'GET /front-desk/board': { body: board }, 'GET /front-desk/today': { body: today },
      'GET /search': { body: { data: [hit] } }, 'GET /reservations/9': { body: { data: reservation } },
    })
    renderApp('/rooms')

    const box = await screen.findByRole('combobox', { name: 'Search guests, rooms, bookings, bills' })
    await userEvent.type(box, 'Abebe')
    expect(await screen.findByRole('option', { name: /BL-26-00001 · Abebe Kebede/ })).toBeInTheDocument()
    await userEvent.keyboard('{Enter}')
    await waitFor(() => expect(screen.queryByRole('listbox')).not.toBeInTheDocument())
    expect((await screen.findAllByText(/BL-26-00001/)).length).toBeGreaterThan(0)
  })
})

describe('reception extras', () => {
  const arrival = { ...reservation, guest: { id: 5, name: 'Abebe Kebede', phone: null }, rooms: [{ room_type: 'Standard', room_number: null }], late: false, overdue: false, balance: '3220.00' }

  it('turns on front desk mode and checks in from the arrivals list', async () => {
    localStorage.clear()
    mockApi({
      'GET /me': { body: me() }, 'GET /dashboard': { body: {} }, 'GET /front-desk/today': { body: { arrivals: [arrival], departures: [], in_house: [] } },
      'GET /reservations/9': { body: { data: reservation } },
    })
    renderApp('/rooms')

    await userEvent.click(await screen.findByRole('button', { name: /Account menu|Meron Tesfaye/ }))
    await userEvent.click(screen.getByRole('menuitem', { name: 'Front desk mode' }))
    expect(await screen.findByRole('heading', { name: 'Front desk' })).toBeInTheDocument()
    expect(await screen.findByText('Abebe Kebede')).toBeInTheDocument()
    expect(localStorage.getItem('hms-front-desk-mode-1')).toBe('1')

    await userEvent.click(screen.getByRole('button', { name: 'Check in' }))
    expect(await screen.findAllByText(/BL-26-00001/)).not.toHaveLength(0)
    localStorage.clear()
  })

  it('prints a registration card', async () => {
    const card = { reservation, guest: { name: 'Abebe Kebede', phone: '+251 911', email: null, nationality: 'Ethiopian', id_type: 'national_id', id_number: 'ET123', date_of_birth: null }, hotel: invoice.hotel, printed_by: 'Meron' }
    mockApi({ 'GET /me': { body: me() }, 'GET /reservations/9/registration-card': { body: { data: card } } })
    renderApp('/reservations/9/registration-card')
    expect(await screen.findByText('ET123')).toBeInTheDocument()
    expect(screen.getByText('Guest signature')).toBeInTheDocument()
    expect(screen.getByText('National ID')).toBeInTheDocument()
  })

  it('prints on the hotel’s default paper', async () => {
    mockApi({ 'GET /me': { body: me() }, 'GET /invoices/4': { body: { data: { ...invoice, hotel: { ...invoice.hotel, receipt_paper: 'receipt' } } } } })
    renderApp('/billing/4/print')
    expect(await screen.findByRole('button', { name: '80 mm receipt' })).toHaveAttribute('aria-pressed', 'true')
  })
})
