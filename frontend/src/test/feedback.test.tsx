import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const item = { id: 3, reservation_id: 9, code: 'RES-1', guest: 'Abebe Kebede', room: '101', overall: 2, cleanliness: 1, staff: 4, comfort: null, value: null, comment: 'The shower was cold.', public_ok: false, submitted_at: '2026-11-13T10:00:00+03:00', checked_in_by: 'Meron', checked_out_by: null, reply: null, replied_by: null }

describe('guest feedback', () => {
  it('lists answers with the guest’s words and adds a reply note', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: makeMe({}, ['feedback.view']) },
      'GET /feedback/summary': { body: { data: { count: 1, averages: { overall: 2, cleanliness: 1, staff: 4, comfort: null, value: null }, low: 1, dashboard: false } } },
      'GET /feedback': { body: { data: [item], total: 1 } },
      'PUT /feedback/3/reply': { body: { data: { reply: 'Fixed it.' } } },
    })
    renderApp('/feedback')
    expect(await screen.findByText('The shower was cold.')).toBeInTheDocument()
    expect(screen.getByText('Low rating')).toBeInTheDocument()
    expect(screen.getByText(/Trends, per room and per staff come with the Starter plan/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Add a reply note' }))
    await userEvent.type(screen.getByLabelText('Reply note'), 'Fixed it.')
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))
    const call = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/feedback/3/reply') && init?.method === 'PUT')
    expect(JSON.parse(String(call?.[1]?.body))).toEqual({ reply: 'Fixed it.' })
  })
})
