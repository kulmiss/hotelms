import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const meWith = (permissions: string[], modules = ['housekeeping', 'cashier_close', 'shifts', 'attendance']) => {
  const me = makeMe({}, permissions)
  return { ...me, hotel: { ...(me.hotel as NonNullable<typeof me.hotel>), modules } }
}

const openShift = {
  id: 3, status: 'open', user: { id: 1, name: 'Meron' }, branch: { id: 1, name: 'Bole branch' }, opened_at: '2026-11-12T08:00:00+03:00', closed_at: null,
  approved_by: null, approved_at: null, notes: null, balances: [{ currency: 'ETB', opening_amount: '500.00', expected_amount: null, counted_amount: null, difference: null }],
  expected: { ETB: { opening: '500.00', cash_in: '1380.00', cash_out: '0.00', expected: '1880.00' } }, payments: [],
}

describe('optional modules', () => {
  it('closes a cashier shift and asks why the count is short', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: meWith(['cashier.own_shift']) },
      'GET /cashier/current': { body: { data: openShift } },
      'GET /cashier/sessions': { body: { data: [], current_page: 1, last_page: 1, total: 0, per_page: 25 } },
      'POST /cashier/close': { body: { data: { ...openShift, status: 'closed' } } },
    })
    renderApp('/cashier')
    expect(await screen.findByText('ETB 1,880.00')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Count the drawer' }))
    await userEvent.type(screen.getByLabelText('Counted (ETB)'), '1800')
    expect(screen.getByText('Short by ETB 80.00')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Close shift' })).toBeDisabled()
    await userEvent.type(screen.getByLabelText('Why is it different?'), 'Wrong change')
    await userEvent.click(screen.getByRole('button', { name: 'Close shift' }))
    await waitFor(() => {
      const call = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/cashier/close'))
      expect(JSON.parse(String(call?.[1]?.body))).toEqual({ counted: { ETB: '1800' }, notes: 'Wrong change' })
    })
  })

  it('does not ask for an own shift when the role only sees all shifts (accountant, QA 8 Nov)', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: meWith(['cashier.view_all']) },
      'GET /cashier/sessions': { body: { data: [], current_page: 1, last_page: 1, total: 0, per_page: 25 } },
    })
    renderApp('/cashier')
    expect(await screen.findByText('All cashier shifts')).toBeInTheDocument()
    expect(fetchMock.mock.calls.some(([url]) => String(url).endsWith('/cashier/current'))).toBe(false)
  })

  it('lets housekeeping start and finish a task', async () => {
    const task = { id: 8, type: 'cleaning', priority: 'high', status: 'pending', notes: null, room: { id: 2, number: '102', floor: '1', status: 'dirty' }, assigned_to: null, due_at: null, started_at: null, completed_at: null }
    const fetchMock = mockApi({
      'GET /me': { body: meWith(['housekeeping.view', 'housekeeping.own_tasks']) },
      'GET /housekeeping/tasks': { body: { data: [task] } },
      'POST /housekeeping/tasks/8/start': { body: { data: { ...task, status: 'in_progress' } } },
    })
    renderApp('/housekeeping')
    expect(await screen.findByText('102')).toBeInTheDocument()
    expect(screen.getByText('Urgent')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Start' }))
    await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => String(url).endsWith('/housekeeping/tasks/8/start'))).toBe(true))
  })

  it('hides module screens when the module is off', async () => {
    mockApi({ 'GET /me': { body: meWith(['cashier.own_shift', 'housekeeping.view', 'dashboard.view'], []) }, 'GET /health': { body: { status: 'ok', app: 'HotelMS', version: '0.1.0', time: '', checks: {} } } })
    renderApp('/')
    expect(await screen.findByRole('heading', { name: 'Dashboard' })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Cashier shift' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Housekeeping' })).not.toBeInTheDocument()
  })

  it('clocks in on the kiosk with a PIN', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: meWith(['attendance.clock']) },
      'POST /attendance/kiosk': { body: { data: {}, name: 'Mulu', action: 'in' } },
    })
    renderApp('/kiosk')
    for (const d of ['4', '3', '2', '1']) await userEvent.click(await screen.findByRole('button', { name: d }))
    await userEvent.click(screen.getByRole('button', { name: 'OK' }))
    expect(await screen.findByText('Welcome, Mulu! You are clocked in.')).toBeInTheDocument()
    const call = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/attendance/kiosk'))
    expect(JSON.parse(String(call?.[1]?.body))).toEqual({ pin: '4321' })
  })
})
