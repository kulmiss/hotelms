import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const owner = () => ({ ...makeMe({ is_super_admin: true, name: 'Owner' }, []), role: null, hotel: null, branches: [], platform: { role: 'owner', permissions: ['hotels.view', 'releases.manage', 'content.manage'] } })
const note = { id: 3, version: '1.1.0', title: 'Front desk mode', body: 'Big tiles for reception.', published_at: '2026-11-06T09:00:00+03:00' }

describe('releases and what is new', () => {
  it('publishes a draft note', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: owner() }, 'GET /whats-new': { body: { data: [], version: '1.0.0' } },
      'GET /admin/releases': { body: { data: [{ ...note, status: 'draft', published_at: null }], live_version: '1.0.0' } },
      'POST /admin/releases/3/publish': { body: { data: {} } },
    })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    renderApp('/admin/releases')

    expect(await screen.findByText('Live version: 1.0.0')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Publish' }))
    expect(fetchMock.mock.calls.some(([u, i]) => String(u).endsWith('/admin/releases/3/publish') && i?.method === 'POST')).toBe(true)
  })

  it('shows a dot until the person opens What’s new', async () => {
    mockApi({ 'GET /me': { body: makeMe() }, 'GET /dashboard': { body: { data: null } }, 'GET /whats-new': { body: { data: [note], version: '1.1.0' } } })
    renderApp('/whats-new')

    expect(await screen.findByText('Big tiles for reception.')).toBeInTheDocument()
    expect(localStorage.getItem('hms-whats-new-seen-1')).toBe('3')
  })

  it('prints a subscription invoice', async () => {
    const inv = {
      id: 7, number: 'HMS-2026-00001', plan: 'Standard', billing_cycle: 'monthly', period_start: '2026-11-15', period_end: '2026-12-14', rooms: 12, amount: '2000.00', currency: 'ETB', status: 'unpaid', due_date: '2026-11-15', emailed_at: null, created_at: '2026-11-08T07:10:00+03:00',
      hotel: { name: 'Sheger Hotel', legal_name: null, tin: '0012345678', address: null, city: 'Addis Ababa' },
      from: { name: 'HotelMS PLC', tin: '0098765432', address: 'Bole', email: null, phone: null, payment_instructions: 'CBE 1000123456789' },
    }
    mockApi({ 'GET /me': { body: makeMe({}, ['subscription.view']) }, 'GET /subscription-invoices/7': { body: { data: inv } } })
    renderApp('/subscription-invoices/7')
    expect(await screen.findByText('HMS-2026-00001')).toBeInTheDocument()
    expect(screen.getByText('CBE 1000123456789')).toBeInTheDocument()
  })
})
