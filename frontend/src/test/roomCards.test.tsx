import { screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

describe('room cards', () => {
  it('prints one card per room with Wi-Fi, menu and WhatsApp QR codes', async () => {
    mockApi({
      'GET /me': { body: makeMe({}, ['settings.rooms']) },
      'GET /settings/room-cards': {
        body: {
          data: {
            hotel: { name: 'Sheger Hotel', logo_url: null, phone: '+251 11 555 0000' },
            branch: { id: 1, name: 'Bole' },
            wifi: { name: 'Sheger', password: 'tea;2026' },
            menu_url: 'https://example.com/menu',
            whatsapp: { number: '+251 911 000 111', url: 'https://wa.me/251911000111' },
            breakfast: '07:00–10:00',
            rooms: [
              { id: 1, number: '101', room_type: 'Standard' },
              { id: 2, number: '102', room_type: 'Standard' },
            ],
          },
        },
      },
    })
    renderApp('/room-cards/print')
    expect(await screen.findByText('Room 101')).toBeInTheDocument()
    expect(screen.getByText('Room 102')).toBeInTheDocument()
    expect(screen.getAllByRole('img', { name: 'Wi-Fi' })).toHaveLength(2)
    expect(screen.getAllByRole('img', { name: 'Restaurant menu' })).toHaveLength(2)
    expect(screen.getAllByRole('img', { name: 'Reception on WhatsApp' })).toHaveLength(2)
    expect(screen.getAllByText(/Password: tea;2026/)).toHaveLength(2)
    expect(screen.getAllByText('Breakfast: 07:00–10:00')).toHaveLength(2)
  })
})
