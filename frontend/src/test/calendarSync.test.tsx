import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const me = () => ({ ...makeMe({}, ['settings.rooms']), hotel: { id: 1, name: 'Sheger Hotel', status: 'active', default_currency: 'ETB', timezone: 'Africa/Addis_Ababa', modules: [] } })
const sync = (max: number | null) => ({
  data: [
    {
      id: 7, name: 'Airbnb', room_id: 1, room_type_id: null, room: '101', room_type: null, is_active: true, host: 'www.airbnb.com',
      last_synced_at: '2026-11-14T09:00:00+03:00', last_status: 'ok', last_error: null, events_count: 3,
      conflicts: [{ key: 'a|1', starts_on: '2026-11-20', ends_on: '2026-11-22', code: 'BL-0001', text: 'Booking BL-0001 is in the same room' }],
    },
  ],
  rooms: [{ id: 1, number: '101', room_type_id: 3, room_type: 'Standard', export_url: null }],
  limit: { used: 1, max },
  every_minutes: 15,
})

describe('calendar sync', () => {
  it('shows each link, its clashes, Sync now and the export links', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: me() },
      'GET /settings/calendar-sync': { body: sync(10) },
      'POST /settings/calendar-sync/7/sync': { body: { sync: { ok: true, events: 3, conflicts: 1, error: null } } },
      'POST /settings/calendar-sync/rooms/1/export': { body: { export_url: 'https://app.test/ical/x.ics' } },
    })
    renderApp('/settings/calendar-sync')
    expect(await screen.findByText(/mainly for apartments/)).toBeInTheDocument()
    expect(await screen.findByText(/Rooms in calendar sync/)).toHaveTextContent('Rooms in calendar sync: 1 of 10 (your plan).')
    expect(screen.getByText(/Booking BL-0001 is in the same room/)).toBeInTheDocument()
    expect(screen.getByText(/Nothing was cancelled/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /Sync now/ }))
    await userEvent.click(screen.getByRole('button', { name: 'Make link' }))
    const calls = fetchMock.mock.calls.map(([url, init]) => `${init?.method ?? 'GET'} ${String(url)}`)
    expect(calls.some((c) => c.startsWith('POST') && c.endsWith('/settings/calendar-sync/7/sync'))).toBe(true)
    expect(calls.some((c) => c.startsWith('POST') && c.endsWith('/settings/calendar-sync/rooms/1/export'))).toBe(true)
  })

  it('Free plan: no adding, a note how to get it', async () => {
    mockApi({ 'GET /me': { body: me() }, 'GET /settings/calendar-sync': { body: { ...sync(0), data: [] } } })
    renderApp('/settings/calendar-sync')
    expect(await screen.findByText(/comes with the Starter plan/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Add and read now' })).not.toBeInTheDocument()
  })
})
