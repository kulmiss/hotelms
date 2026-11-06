import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ADMIN_PERMISSIONS, makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const admin = () => makeMe({}, [...ADMIN_PERMISSIONS, 'automations.manage', 'approvals.decide', 'payments.refund', 'settings.pricing', 'reservations.view'])
const builder = {
  triggers: ['booking_created', 'daily'], fields: { branch_id: 'all', nights: 'booking', weekday: 'all' }, ops: ['is', 'is_not', 'above', 'below'], actions: ['notify', 'booking_note'],
  branches: [{ id: 1, name: 'Bole branch' }], room_types: [], guest_types: [], staff: [], permissions: [],
}
const automations = {
  data: [
    { key: 'booking_confirmation', group: 'bookings', enabled: true, options: {}, to: ['guest'], urgent: false, blocked: null, module: null },
    { key: 'large_discount', group: 'money', enabled: true, options: { amount: '1000' }, to: [], urgent: false, blocked: null, module: null },
    { key: 'cashier_short', group: 'money', enabled: true, options: {}, to: [], urgent: true, blocked: 'module', module: 'cashier_close' },
  ],
  quiet: { quiet_hours: true, quiet_from: '23:00', quiet_to: '06:00' },
  rules: [],
  builder,
}

describe('automations', () => {
  it('turns a ready-made automation off and makes a rule after testing it', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: admin() }, 'GET /settings/automations': { body: automations },
      'PUT /settings/automations/booking_confirmation': { body: automations },
      'POST /settings/automation-rules/test': { body: { count: 3, days: 7 } },
      'POST /settings/automation-rules': { status: 201, body: { data: {} } },
    })
    renderApp('/settings/automations')

    expect(await screen.findByText('Needs the Cashier shift close module')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('switch', { name: 'Booking confirmation to the guest' }))
    const put = fetchMock.mock.calls.find(([u, i]) => String(u).endsWith('/settings/automations/booking_confirmation') && i?.method === 'PUT')
    expect(JSON.parse(String(put?.[1]?.body))).toMatchObject({ enabled: false })

    await userEvent.click(screen.getByRole('button', { name: 'New rule' }))
    await userEvent.type(screen.getByLabelText('Rule name'), 'Long stays')
    await userEvent.click(screen.getByRole('button', { name: 'Add condition' }))
    await userEvent.selectOptions(screen.getByLabelText('Field'), 'nights')
    await userEvent.selectOptions(screen.getByLabelText('Is'), 'above')
    await userEvent.type(screen.getByLabelText('Value'), '3')
    await userEvent.type(screen.getByLabelText('Message'), 'Long stay booked')
    await userEvent.click(screen.getByRole('button', { name: 'Test on last 7 days' }))
    expect(await screen.findByText('Would have run 3 times in the last 7 days')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    const post = fetchMock.mock.calls.find(([u, i]) => String(u).endsWith('/settings/automation-rules') && i?.method === 'POST')
    expect(JSON.parse(String(post?.[1]?.body))).toMatchObject({ name: 'Long stays', trigger: 'booking_created', conditions: [{ field: 'nights', op: 'above', value: '3' }], actions: [{ type: 'notify', message: 'Long stay booked' }] })
  })

  it('gives an approved refund with the chosen method', async () => {
    const refund = { id: 4, kind: 'refund', title: 'Refund ETB 1380.00 for cancelled booking BL-26-00001', payload: { amount: '1380.00', currency: 'ETB' }, status: 'pending', branch: 'Bole branch', decided_by: null, decided_at: null, note: null, created_at: '2026-11-10T09:00:00+03:00' }
    const fetchMock = mockApi({
      'GET /me': { body: admin() }, 'GET /approvals': { body: { data: [refund], pending: 1 } },
      'GET /reservations/lookups': { body: { payment_methods: [{ id: 2, name: 'Cash', type: 'cash' }] } },
      'POST /approvals/4/approve': { body: { message: 'Approved.' } },
    })
    renderApp('/approvals')

    const card = (await screen.findByText(refund.title)).closest('li') as HTMLElement
    expect(within(card).getByRole('button', { name: 'Give refund' })).toBeDisabled()
    await userEvent.selectOptions(await within(card).findByLabelText('Refund by'), '2')
    await userEvent.click(within(card).getByRole('button', { name: 'Give refund' }))
    const call = fetchMock.mock.calls.find(([u, i]) => String(u).endsWith('/approvals/4/approve') && i?.method === 'POST')
    expect(JSON.parse(String(call?.[1]?.body))).toMatchObject({ id: 4, payment_method_id: 2 })
    expect(await screen.findByText('Approved and done')).toBeInTheDocument()
  })

  it('shows notifications in the bell and marks them read', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: admin() }, 'GET /dashboard': { body: {} }, 'GET /alert-events': { body: { data: [], unread: 0 } },
      'GET /notifications': { body: { data: [{ id: 1, title: 'Room 101 is ready', body: 'For Abebe', link: null, urgent: false, read: false, created_at: '2026-11-10T09:00:00+03:00' }], unread: 1 } },
      'POST /notifications/read': { body: { message: 'Done.' } },
    })
    renderApp('/reports')

    await userEvent.click(await screen.findByRole('button', { name: /1 new/ }))
    expect(screen.getByText('Room 101 is ready')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Mark all read' }))
    expect(fetchMock.mock.calls.some(([u, i]) => String(u).endsWith('/notifications/read') && i?.method === 'POST')).toBe(true)
    expect(screen.queryByText('Changes saved')).not.toBeInTheDocument()
  })
})
