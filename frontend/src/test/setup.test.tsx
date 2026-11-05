import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { Me } from '@/lib/api'
import { healthOk, makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const KEYS = ['account', 'profile', 'branches', 'room_types', 'rooms', 'payments', 'taxes', 'booking_rules', 'cancellation', 'modules', 'team', 'review']
const REQUIRED = ['account', 'profile', 'branches', 'room_types', 'rooms', 'review']

function setupData(states: Record<string, string> = {}, extra: Record<string, unknown> = {}) {
  return {
    data: {
      completed: false,
      started: Object.keys(states).length > 0,
      skipped: 0,
      remaining: 10,
      steps: KEYS.map((key) => ({ key, required: REQUIRED.includes(key), state: states[key] ?? (key === 'account' ? 'done' : 'todo') })),
      hotel: { name: 'Sheger Hotel', status: 'trial', plan: 'Standard', trial_ends_at: '2026-11-19', max_rooms: 60 },
      ...extra,
    },
  }
}

function meWithSetup(setup: Partial<NonNullable<NonNullable<Me['hotel']>['setup']>>, user: Partial<Me['user']> = {}): Me {
  const me = makeMe(user)
  return { ...me, hotel: { ...(me.hotel as NonNullable<Me['hotel']>), setup: { completed: false, started: false, skipped: 0, remaining: 11, ...setup } } }
}

describe('setup walkthrough', () => {
  it('sends a new Hotel Admin to the welcome screen', async () => {
    mockApi({ 'GET /me': { body: meWithSetup({}) }, 'GET /setup': { body: setupData() } })
    renderApp('/')
    expect(await screen.findByRole('heading', { name: 'Welcome, Sheger Hotel' })).toBeInTheDocument()
    expect(screen.getByText('Plan: Standard')).toBeInTheDocument()
    expect(screen.getByText('Up to 60 rooms')).toBeInTheDocument()
  })

  it('starts with the password and locks the other steps until it is changed', async () => {
    mockApi({
      'GET /me': { body: meWithSetup({}, { must_change_password: true }) },
      'GET /setup': { body: setupData({}, { steps: KEYS.map((key) => ({ key, required: REQUIRED.includes(key), state: 'todo' })) }) },
    })
    renderApp('/settings')
    await userEvent.click(await screen.findByRole('button', { name: 'Start setup' }))
    expect(screen.getByRole('heading', { name: 'Set a new password' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Hotel profile/ })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Save and continue' })).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Save and exit' })).not.toBeInTheDocument()
  })

  it('skips an optional step and saves it', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: meWithSetup({ started: true }) },
      'GET /setup': { body: setupData({ profile: 'done', branches: 'done', room_types: 'done', rooms: 'done', payments: 'done' }) },
      'GET /settings/taxes': { body: { data: [] } },
      'PUT /setup/steps/taxes': { body: setupData({ taxes: 'skipped' }) },
      'GET /settings/booking-rules': { body: { data: {} } },
    })
    renderApp('/setup')
    expect(await screen.findByText('Step 7 of 12')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Skip for now' }))

    const call = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/setup/steps/taxes'))
    expect(JSON.parse(String(call?.[1]?.body))).toEqual({ state: 'skipped' })
    expect(await screen.findByText('Step 8 of 12')).toBeInTheDocument()
  })

  it('does not offer skip on a required step', async () => {
    mockApi({
      'GET /me': { body: meWithSetup({ started: true }) },
      'GET /setup': { body: setupData({ profile: 'done', branches: 'done' }) },
      'GET /settings/room-types': { body: { data: [] } },
    })
    renderApp('/setup')
    expect(await screen.findByText('Step 4 of 12')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Skip for now' })).not.toBeInTheDocument()
  })

  it('finishes from the review screen', async () => {
    const all = Object.fromEntries(KEYS.filter((k) => k !== 'review').map((k) => [k, 'done']))
    mockApi({
      'GET /me': { body: meWithSetup({ started: true }) },
      'GET /setup': { body: setupData(all) },
      'POST /setup/finish': { body: setupData(all, { completed: true }) },
    })
    renderApp('/setup')
    expect(await screen.findByRole('heading', { name: 'Review and finish' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Finish setup' }))
    expect(await screen.findByRole('heading', { name: "You're all set!" })).toBeInTheDocument()
  })

  it('shows a Finish setup card on the dashboard after Save and exit', async () => {
    mockApi({ 'GET /me': { body: meWithSetup({ started: true, remaining: 4 }) }, 'GET /health': healthOk })
    renderApp('/')
    expect(await screen.findByText('Finish setting up your hotel')).toBeInTheDocument()
    expect(screen.getByText('4 steps left.')).toBeInTheDocument()
  })
})
