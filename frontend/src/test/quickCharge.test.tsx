import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const data = {
  rooms: [
    { room_id: 1, number: '101', floor: '1', room_type: 'Standard', reservation_id: 9, code: 'BL-26-00001', guest: 'Abebe K' },
    { room_id: 2, number: '204', floor: '2', room_type: 'Deluxe', reservation_id: 10, code: 'BL-26-00002', guest: 'Sara T' },
  ],
  buttons: [
    { id: 1, name: 'Breakfast', price: '250.00', currency: 'ETB' },
    { id: 2, name: 'Taxi', price: null, currency: 'ETB' },
  ],
}
const added = (id: number, description: string, amount: string) => ({ status: 201, body: { data: { id, room: '204', description, quantity: '1', amount, currency: 'ETB', reservation_id: 10 } } })
const body = (fetchMock: ReturnType<typeof mockApi>, method: string, end: string) =>
  fetchMock.mock.calls.filter(([url, init]) => String(url).endsWith(end) && init?.method === method).map(([, init]) => (init?.body ? JSON.parse(String(init.body)) : null))

describe('quick charges', () => {
  it('charges breakfast to a room in one tap, and undoes it', async () => {
    let n = 0
    const fetchMock = mockApi({
      'GET /me': { body: makeMe({}, ['charges.room']) },
      'GET /front-desk/quick-charges': { body: data },
      'POST /front-desk/rooms/2/charges': () => added(50 + ++n, 'Breakfast', '250.00'),
      'DELETE /front-desk/charges/52': { body: { ok: true } },
    })
    renderApp('/') // Restaurant / Bar staff start on "Charge a room"

    expect(await screen.findByRole('heading', { name: 'Charge a room' })).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Room number or guest name'), '204') // the full number chooses the room
    await userEvent.click(await screen.findByRole('button', { name: 'Add Breakfast, ETB 250.00' }))
    expect(await screen.findByText(/Added: Breakfast/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Add Breakfast, ETB 250.00' }))
    await waitFor(() => expect(body(fetchMock, 'POST', '/front-desk/rooms/2/charges')).toEqual([{ charge_type_id: 1 }, { charge_type_id: 1 }]))
    expect(await screen.findByText('×2')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Undo' }))
    await waitFor(() => expect(body(fetchMock, 'DELETE', '/front-desk/charges/52')).toHaveLength(1))
    expect(await screen.findByText('×1')).toBeInTheDocument()
  })

  it('asks the amount for a taxi, and anything else with Other', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: makeMe({}, ['charges.room']) },
      'GET /front-desk/quick-charges': { body: data },
      'POST /front-desk/rooms/1/charges': (init?: RequestInit) => added(60, JSON.parse(String(init?.body)).description ?? 'Taxi', '300.00'),
    })
    renderApp('/charge')

    await userEvent.click(await screen.findByRole('button', { name: /101/ }))
    await userEvent.click(screen.getByRole('button', { name: 'Taxi' }))
    await userEvent.type(screen.getByLabelText('Amount'), '300')
    await userEvent.click(screen.getByRole('button', { name: 'Add to room 101' }))
    await waitFor(() => expect(body(fetchMock, 'POST', '/front-desk/rooms/1/charges')[0]).toEqual({ charge_type_id: 2, unit_price: '300' }))

    await userEvent.click(screen.getByRole('button', { name: 'Other' }))
    await userEvent.type(screen.getByLabelText('What'), 'Spa')
    await userEvent.type(screen.getByLabelText('Amount'), '500')
    await userEvent.click(screen.getByRole('button', { name: 'Add to room 101' }))
    await waitFor(() => expect(body(fetchMock, 'POST', '/front-desk/rooms/1/charges')[1]).toEqual({ description: 'Spa', unit_price: '500' }))
  })
})
