import { screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { healthOk, makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const request = {
  id: 7, status: 'new', name: 'Abebe Kebede', phone: '+251 911 223 344', email: null, note: 'Late arrival',
  branch: { id: 1, name: 'Bole' }, room_type: { id: 3, name: 'Lake view' }, check_in: '2026-11-20', check_out: '2026-11-22',
  adults: 2, children: 0, reservation_id: null, created_at: '2026-11-14T10:00:00Z', handled_at: null,
}

describe('online booking requests (P7)', () => {
  it('lists new requests with Book it and Decline', async () => {
    mockApi({
      'GET /me': { body: makeMe({}, ['reservations.view', 'reservations.create', 'dashboard.view']) },
      'GET /health': healthOk,
      'GET /booking-requests': { body: { data: [request], new: 1 } },
    })
    renderApp('/booking-requests')
    expect(await screen.findByText('Abebe Kebede')).toBeInTheDocument()
    expect(screen.getByText('Late arrival')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Book it' })).toHaveAttribute('href', '/reservations/new?request=7')
    expect(screen.getByRole('button', { name: 'Decline' })).toBeInTheDocument()
  })
})
