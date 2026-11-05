import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const ALL = ['settings.hotel', 'settings.branches', 'settings.money', 'settings.exchange_rates', 'settings.charges', 'settings.policies', 'settings.modules', 'subscription.view']
const meAdmin = () => ({ ...makeMe({}, ALL), hotel: { id: 1, name: 'Sheger Hotel', status: 'active', default_currency: 'ETB', timezone: 'Africa/Addis_Ababa', modules: ['housekeeping'] } })

describe('hotel settings', () => {
  it('opens the first section and lists all sections the role may use', async () => {
    mockApi({
      'GET /me': { body: meAdmin() },
      'GET /settings/hotel': { body: { data: { name: 'Sheger Hotel', legal_name: null, tin: null, vat_number: null, email: null, phone: null, city: null, address: null, logo_url: null, invoice_prefix: 'INV', invoice_footer: null } } },
    })
    renderApp('/settings')
    expect(await screen.findByLabelText('Hotel name')).toHaveValue('Sheger Hotel')
    const nav = screen.getByRole('navigation', { name: 'Settings' })
    for (const s of ['Hotel profile', 'Branches', 'Taxes', 'Booking rules', 'Modules', 'Subscription']) expect(nav).toHaveTextContent(s)
    expect(nav).not.toHaveTextContent('Attendance') // module is off
  })

  it('adds a tax from the list', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: meAdmin() },
      'GET /settings/taxes': { body: { data: [{ id: 1, name: 'VAT', rate: '15.00', applies_to: 'all', is_inclusive: false, is_active: true }] } },
      'POST /settings/taxes': { status: 201, body: { data: {} } },
    })
    renderApp('/settings/taxes')

    expect(await screen.findByText('Example: a room at ETB 1,000.00 costs ETB 1,150.00 with VAT.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /Add tax/ }))
    await userEvent.type(screen.getByLabelText('Name'), 'Tourism levy')
    await userEvent.type(screen.getByLabelText('Rate (%)'), '2')
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    const call = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/settings/taxes') && init?.method === 'POST')
    expect(JSON.parse(String(call?.[1]?.body))).toMatchObject({ name: 'Tourism levy', rate: '2', applies_to: 'all', is_active: true })
  })

  it('shows modules outside the plan as locked', async () => {
    mockApi({
      'GET /me': { body: meAdmin() },
      'GET /settings/modules': {
        body: {
          data: [
            { module: 'housekeeping', available: true, enabled: true, plans: ['Standard', 'Pro'] },
            { module: 'attendance', available: false, enabled: false, plans: ['Pro'] },
          ],
        },
      },
    })
    renderApp('/settings/modules')
    expect(await screen.findByRole('switch', { name: 'Housekeeping' })).toBeChecked()
    expect(screen.getByRole('switch', { name: 'Attendance' })).toBeDisabled()
    expect(screen.getByText('Included in: Pro')).toBeInTheDocument()
  })

  it('keeps receptionists out of settings', async () => {
    mockApi({ 'GET /me': { body: makeMe({}, ['frontdesk.view']) }, 'GET /health': { body: {} } })
    renderApp('/settings/taxes')
    expect(await screen.findByRole('heading', { name: 'Dashboard' })).toBeInTheDocument()
  })
})
