import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const roles = {
  body: {
    data: [
      { id: 1, key: 'hotel_admin', name: 'Hotel Admin', description: null, is_default: true, is_locked: true, max_discount_percent: '100.00', permissions: ['a', 'b'], users_count: 1 },
      { id: 3, key: 'receptionist', name: 'Receptionist', description: null, is_default: true, is_locked: false, max_discount_percent: '5.00', permissions: ['frontdesk.view'], users_count: 0 },
    ],
  },
}

describe('team', () => {
  it('hides screens the role is not allowed to use', async () => {
    mockApi({ 'GET /me': { body: makeMe({}, ['frontdesk.view']) }, 'GET /health': { body: {} } })
    renderApp('/')
    const nav = await screen.findByRole('navigation', { name: 'Main' })
    expect(nav).toHaveTextContent('Room board')
    expect(nav).not.toHaveTextContent('Staff')
    expect(nav).not.toHaveTextContent('Reservations')
  })

  it('adds a staff member and shows their temporary password', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: makeMe() },
      'GET /roles': roles,
      'POST /staff': {
        status: 201,
        body: {
          data: { id: 9, name: 'Abebe', email: 'abebe@sheger.test', phone: null, status: 'active', must_change_password: true, last_login_at: null, role: { id: 3, name: 'Receptionist', is_locked: false }, branches: [{ id: 1, name: 'Bole branch' }] },
          temporary_password: 'Tmp9Pass1abc',
          email_sent: true,
        },
      },
    })
    renderApp('/staff/new')

    await userEvent.type(await screen.findByLabelText('Full name'), 'Abebe')
    await userEvent.type(screen.getByLabelText('Email (used to sign in)'), 'abebe@sheger.test')
    await userEvent.click(await screen.findByRole('radio', { name: 'Receptionist' }))
    await userEvent.click(screen.getByRole('checkbox', { name: 'Bole branch' }))
    await userEvent.click(screen.getByRole('button', { name: 'Add staff member' }))

    expect(await screen.findByText('Tmp9Pass1abc')).toBeInTheDocument()
    const call = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/staff') && init?.method === 'POST')
    expect(JSON.parse(String(call?.[1]?.body))).toMatchObject({ name: 'Abebe', role_id: 3, branch_ids: [1] })
  })

  it('shows roles with the locked Hotel Admin role', async () => {
    mockApi({ 'GET /me': { body: makeMe() }, 'GET /roles': roles })
    renderApp('/roles')
    const card = (await screen.findByRole('heading', { name: 'Hotel Admin' })).closest('a')!
    expect(within(card).getByText('Locked')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Receptionist' })).toBeInTheDocument()
  })

  it('edits a role by ticking permissions', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: makeMe() },
      'GET /roles/3': { body: { data: roles.body.data[1] } },
      'GET /permissions': {
        body: { data: [{ group: 'frontdesk', permissions: [{ key: 'frontdesk.view', module: null }, { key: 'reservations.view', module: null }] }] },
      },
      'PUT /roles/3': { body: { data: roles.body.data[1] } },
      'GET /roles': roles,
    })
    renderApp('/roles/3')

    await userEvent.click(await screen.findByRole('checkbox', { name: 'See calendar, list and details' }))
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    await screen.findByRole('heading', { name: 'Roles and permissions' })
    const call = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/roles/3') && init?.method === 'PUT')
    expect(JSON.parse(String(call?.[1]?.body)).permissions.sort()).toEqual(['frontdesk.view', 'reservations.view'])
  })

  it('lists the activity log with old and new values', async () => {
    mockApi({
      'GET /me': { body: makeMe() },
      'GET /activity-log': {
        body: {
          data: [{ id: 1, event: 'updated', description: 'Staff member changed: Abebe', subject_type: 'User', properties: { old: { phone: null }, new: { phone: '+251911' } }, created_at: '2026-11-05T10:00:00Z', user: { id: 1, name: 'Meron' } }],
          current_page: 1,
          last_page: 1,
        },
      },
    })
    renderApp('/activity-log')
    expect(await screen.findByText('Staff member changed: Abebe')).toBeInTheDocument()
    expect(screen.getByText('Meron')).toBeInTheDocument()
    expect(screen.getByText(/→ \+251911/)).toBeInTheDocument()
  })
})
