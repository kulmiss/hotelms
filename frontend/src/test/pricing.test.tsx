import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const me = (switches: Record<string, boolean> = {}) => ({
  ...makeMe({}, ['settings.pricing']),
  hotel: { id: 1, name: 'Sheger Hotel', status: 'active', default_currency: 'ETB', timezone: 'Africa/Addis_Ababa', modules: [] },
  role: { id: 1, name: 'Hotel Admin', is_hotel_admin: true, max_discount_percent: '100' },
  switches,
})

describe('seasons and pricing', () => {
  it('holidays: Ethiopian dates next to each day and the preset button', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: me() },
      'GET /settings/holidays': { body: { data: [{ id: 1, name: 'Genna (Ethiopian Christmas)', date: '2027-01-07', kind: 'fixed', is_active: true }, { id: 2, name: 'Eid al-Fitr', date: '2027-03-10', kind: 'expected', is_active: true }] } },
      'POST /settings/holidays/ethiopian': { body: { added: 38, data: [] } },
    })
    renderApp('/settings/holidays')
    expect(await screen.findByText('Genna (Ethiopian Christmas)')).toBeInTheDocument()
    expect(screen.getByText('29 Tahsas 2019')).toBeInTheDocument()
    expect(screen.getByText('Expected – check the announcement')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Add Ethiopian holidays' }))
    expect(await screen.findByText('38 holidays added.')).toBeInTheDocument()
    expect(fetchMock.mock.calls.some(([url, init]) => String(url).endsWith('/settings/holidays/ethiopian') && init?.method === 'POST')).toBe(true)
  })

  it('stay rules are offered only when the plan has them', async () => {
    mockApi({ 'GET /me': { body: me({ smart_pricing: false }) }, 'GET /settings/rate-rules': { body: { data: [] } }, 'GET /settings/guest-types': { body: { data: [] } }, 'GET /settings/room-types': { body: { data: [] } } })
    renderApp('/settings/rate-rules')
    expect(await screen.findByText(/come with the Starter plan/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /Add rule/ }))
    const type = screen.getByLabelText('Rule type')
    expect(type).toHaveTextContent('Holidays (from the list)')
    expect(type).not.toHaveTextContent('Long stay')
  })
})
