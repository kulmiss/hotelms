import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { phoneLooksWrong } from '@/lib/smart'
import { ADMIN_PERMISSIONS, makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const hint = {
  key: 'tonight', text: 'Tonight looks about 80% full – 4 more arrivals to come.', why: '12 rooms in house now plus 4 arriving today, of 20 rooms.', tone: 'info', link: null,
  explain: { facts: [['Rooms with a guest now', '12'], ['Rooms in the hotel', '20']], how: 'Rooms with a guest staying tonight, plus bookings arriving today.', do: 'Check the arrivals list.' },
}

describe('smart hints', () => {
  it('shows a hint with its reason and hides it when dismissed', async () => {
    mockApi({ 'GET /me': { body: makeMe() }, 'GET /dashboard': { body: { data: null } }, 'GET /smart/dashboard': { body: { data: [hint] } } })
    renderApp('/')

    expect(await screen.findByText(hint.text)).toBeInTheDocument()
    expect(screen.queryByText(hint.why)).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Why?' }))
    expect(screen.getByText(hint.why)).toBeInTheDocument()
    // The full explanation: the numbers, how it was worked out, what to do.
    expect(screen.getByText('Rooms in the hotel').nextElementSibling).toHaveTextContent('20')
    expect(screen.getByText(hint.explain.how)).toBeInTheDocument()
    expect(screen.getByText(hint.explain.do)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Hide this tip' }))
    expect(screen.queryByText(hint.text)).not.toBeInTheDocument()
  })

  it('warns about a possible duplicate guest while typing', async () => {
    const dup = { key: 'duplicate_guest', text: 'Almaz Tesfaye may already exist (+251 911 223344).', why: 'Same phone number.', tone: 'warn', link: '/guests/3', data: { guest_id: 3, name: 'Almaz Tesfaye' } }
    mockApi({ 'GET /me': { body: makeMe({}, [...ADMIN_PERMISSIONS, 'guests.create_edit']) }, 'GET /guests': { body: { data: [], total: 0, last_page: 1, current_page: 1 } }, 'GET /guest-types': { body: { data: [] } }, 'GET /smart/guest_check': { body: { data: [dup] } } })
    renderApp('/guests/new')

    await userEvent.type(await screen.findByLabelText('Phone'), '0911223344')
    expect(await screen.findByText(dup.text, {}, { timeout: 2000 })).toBeInTheDocument()
  })

  it('checks Ethiopian phone numbers', () => {
    expect(phoneLooksWrong('+251 911 223344')).toBe(false)
    expect(phoneLooksWrong('0911223344')).toBe(false)
    expect(phoneLooksWrong('0111234567')).toBe(false)
    expect(phoneLooksWrong('091122334')).toBe(true)
    expect(phoneLooksWrong('+44 20 7946 0958')).toBe(false) // foreign: not checked
  })
})
