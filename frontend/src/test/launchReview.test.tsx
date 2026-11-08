import { screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { isUpdateError } from '@/components/layout/RouteError'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

describe('launch recheck', () => {
  it('knows when a screen failed because a new version was put online', () => {
    expect(isUpdateError(new TypeError('Failed to fetch dynamically imported module: /assets/Billing-abc.js'))).toBe(true)
    expect(isUpdateError(new Error('Importing a module script failed.'))).toBe(true)
    expect(isUpdateError(new TypeError("Cannot read properties of undefined (reading 'map')"))).toBe(false)
  })
})

describe('owner answers (8 Nov)', () => {
  it('opens Reports on the Daily report for the accountant', async () => {
    const accountant = { ...makeMe({}, ['reports.revenue', 'reports.my_shift', 'reports.accounting_export']), role: { id: 5, name: 'Accountant', is_hotel_admin: false, max_discount_percent: '0.00' } }
    mockApi({ 'GET /me': { body: accountant } })
    renderApp('/reports')

    expect(await screen.findByRole('button', { name: 'Daily manager report' })).toHaveAttribute('aria-current', 'page')
  })

  it('lets only a manager add charges to the bill of a guest who has left', async () => {
    const invoice = {
      id: 4, number: 'INV-2026-00001', status: 'partially_paid', reservation: { id: 9, code: 'BL-26-00001' }, guest: { id: 5, full_name: 'Abebe Kebede', phone: null, email: null, nationality: null },
      branch: { id: 1, name: 'Bole branch' }, currency: 'ETB', total: '4600.00', paid_total: '1380.00', balance: '3220.00', issued_at: '2026-11-12T15:00:00+03:00',
      subtotal: '4000.00', discount_total: '0.00', tax_total: '600.00', reservation_room_id: null, outstanding_approved_at: null, voided_at: null, void_reason: null, buyer: { company: null, tin: null },
      stay: { check_in_at: '2026-11-12T14:00:00+03:00', check_out_at: '2026-11-14T12:00:00+03:00', status: 'checked_out', rooms: [{ id: 21, room_number: '101', room_type: 'Standard' }] },
      items: [{ id: 1, type: 'room', description: 'Room 101 · Standard · Night', quantity: '1.00', unit_price: '2000.00', amount: '2000.00', is_waived: false, service_date: '2026-11-12', reservation_room_id: 21 }],
      taxes: [], payments: [],
      hotel: { name: 'Sheger Hotel', legal_name: null, tin: null, vat_number: null, address: null, city: null, phone: null, email: null, logo_url: null, footer: null },
    }
    const reception = ['invoices.view', 'invoices.add_charge', 'invoices.discount', 'payments.take', 'invoices.print']
    mockApi({ 'GET /me': { body: makeMe({}, reception) }, 'GET /invoices/4': { body: { data: invoice } }, 'GET /reservations/lookups': { body: { policies: [], payment_methods: [], currencies: [] } } })
    renderApp('/billing/4')

    expect(await screen.findByRole('button', { name: 'Record payment' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Add charge' })).not.toBeInTheDocument()
  })
})
