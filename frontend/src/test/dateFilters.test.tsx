import { fireEvent, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { presetOf, presetRange } from '@/lib/dateRange'
import { addDays, today } from '@/lib/front'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const urls = (fetchMock: ReturnType<typeof mockApi>, part: string) => fetchMock.mock.calls.map(([u]) => String(u)).filter((u) => u.includes(part))

describe('date filters', () => {
  it('works out the quick choices in hotel days (weeks start on Monday)', () => {
    const sat = '2026-11-07'
    expect(presetRange('today', sat)).toEqual({ from: sat, to: sat })
    expect(presetRange('yesterday', sat)).toEqual({ from: '2026-11-06', to: '2026-11-06' })
    expect(presetRange('thisWeek', sat)).toEqual({ from: '2026-11-02', to: sat })
    expect(presetRange('thisWeek', '2026-11-02')).toEqual({ from: '2026-11-02', to: '2026-11-02' }) // Monday
    expect(presetRange('thisMonth', sat)).toEqual({ from: '2026-11-01', to: sat })
    expect(presetRange('lastMonth', sat)).toEqual({ from: '2026-10-01', to: '2026-10-31' })
    expect(presetRange('lastMonth', '2026-01-15')).toEqual({ from: '2025-12-01', to: '2025-12-31' })
    expect(presetRange('any', sat)).toEqual({})
    expect(presetOf({ from: '2026-10-01', to: '2026-10-31' }, sat)).toBe('lastMonth')
    expect(presetOf({ from: '2026-10-03', to: '2026-10-09' }, sat)).toBe('custom')
  })

  it('filters bills and payments by date', async () => {
    const page = { data: [], current_page: 1, last_page: 1, total: 0 }
    const fetchMock = mockApi({ 'GET /me': { body: makeMe({}, ['invoices.view', 'payments.view']) }, 'GET /invoices': { body: page }, 'GET /payments': { body: page } })
    renderApp('/billing')

    await userEvent.selectOptions(await screen.findByLabelText('Dates'), 'Today')
    await waitFor(() => expect(urls(fetchMock, '/invoices?').some((u) => u.includes(`from=${today()}`) && u.includes(`to=${today()}`))).toBe(true))

    await userEvent.selectOptions(screen.getByLabelText('Dates'), 'Choose dates…')
    fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-10-01' } })
    await waitFor(() => expect(urls(fetchMock, '/invoices?').some((u) => u.includes('from=2026-10-01'))).toBe(true))
  })

  it('shows the room board on another day, only to look', async () => {
    const board = {
      data: [{ id: 2, number: '102', floor: '1', status: 'dirty', room_type_id: 3, room_type: 'Standard', notes: null, stay: null, arriving: null }],
      counts: { dirty: 1 },
      refreshed_at: `${today()}T09:00:00+03:00`,
    }
    const fetchMock = mockApi({ 'GET /me': { body: makeMe({}, ['frontdesk.view', 'rooms.change_status']) }, 'GET /front-desk/board': { body: board }, 'GET /front-desk/today': { body: { arrivals: [], departures: [], in_house: [] } } })
    renderApp('/rooms')

    await userEvent.click(await screen.findByRole('button', { name: 'Room 102, Dirty' }))
    expect(screen.getByRole('button', { name: 'Mark Available' })).toBeInTheDocument() // today: live, can change

    await userEvent.click(screen.getByRole('button', { name: 'Day after' }))
    await waitFor(() => expect(urls(fetchMock, '/front-desk/board').some((u) => u.includes(`date=${addDays(today(), 1)}`))).toBe(true))
    expect(await screen.findByText(/Showing the night of/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Mark Available' })).not.toBeInTheDocument() // another day: only to look

    await userEvent.click(screen.getByRole('button', { name: 'Back to today' }))
    expect(screen.queryByText(/Showing the night of/)).not.toBeInTheDocument()
  })
})
