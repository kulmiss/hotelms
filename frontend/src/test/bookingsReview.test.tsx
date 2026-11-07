import { screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const PERMS = ['reservations.view', 'reservations.create', 'reservations.edit', 'reservations.cancel', 'payments.take', 'frontdesk.check_in']
const reservation = {
  id: 9, code: 'BL-26-00001', status: 'cancelled', stay_type: 'nightly', source: 'phone', branch: { id: 1, name: 'Bole branch' },
  guest: { id: 5, full_name: 'Abebe Kebede', phone: null }, check_in_at: '2099-01-01T14:00:00+03:00', check_out_at: '2099-01-03T12:00:00+03:00', expires_at: null,
  adults: 2, children: 0, currency: 'ETB', total: '4600.00', paid_total: '1380.00', booking_total: '4600.00', deposit_required: '1380.00', notes: null, subtotal: '4000.00',
  discount_percent: '0.00', discount_amount: '0.00', tax_total: '600.00', balance: '-1380.00', cancellation_fee: '0.00', cancellation_policy_id: 2, policy: null,
  cancelled_at: '2026-11-10T09:00:00+03:00', cancel_reason: 'Plans changed', created_by: 'Meron', created_at: '2026-11-10T09:00:00+03:00', pay_later: null,
  rooms: [{ id: 21, room_type_id: 3, room_type: 'Standard', room_id: null, room_number: null, adults: 2, children: 0, total: '4000.00', status: 'cancelled', lines: [], guests: [] }],
  invoices: [], payments: [],
}
const lookups = { policies: [], payment_methods: [{ id: 1, name: 'Cash', type: 'cash' }], currencies: [] }

describe('bookings review (part 2)', () => {
  it('shows money to give back on a cancelled booking, and no payment button for reception', async () => {
    mockApi({ 'GET /me': { body: makeMe({}, PERMS) }, 'GET /reservations/9': { body: { data: reservation } }, 'GET /reservations/lookups': { body: lookups } })
    renderApp('/reservations/9')

    expect(await screen.findByText('To give back to the guest')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Record payment' })).not.toBeInTheDocument()
  })

  it('does not open the change form for a booking that cannot be changed', async () => {
    mockApi({ 'GET /me': { body: makeMe({}, PERMS) }, 'GET /reservations/9': { body: { data: reservation } }, 'GET /reservations/lookups': { body: lookups } })
    renderApp('/reservations/9/edit')

    expect(await screen.findByText(/Only bookings that are waiting or confirmed can be changed/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Back to the booking' })).toHaveAttribute('href', '/reservations/9')
  })

  it('says a list could not load instead of "nothing here"', async () => {
    mockApi({ 'GET /me': { body: makeMe({}, PERMS) }, 'GET /reservations': { status: 500, body: { message: 'Server error' } } })
    renderApp('/reservations')

    expect(await screen.findByText(/could not be loaded/, {}, { timeout: 8000 })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument()
  }, 10_000)
})
