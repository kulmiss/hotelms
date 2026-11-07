import { screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mmss } from '@/lib/demo'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

describe('"Try the demo" time limit', () => {
  it('counts down and signs the visitor out at zero, then says why', async () => {
    const me = makeMe({}, ['dashboard.view', 'frontdesk.view'])
    const demoMe = { ...me, hotel: { ...me.hotel!, is_demo: true, demo_ends_at: new Date(Date.now() + 1500).toISOString() } }
    mockApi({ 'GET /me': { body: demoMe } })
    renderApp('/')

    expect(await screen.findByText(/Time left: 0:0[12]/)).toBeInTheDocument()
    expect(await screen.findByText('Your demo time is over. You can start the demo again below.', {}, { timeout: 4000 })).toBeInTheDocument()
  })

  it('shows minutes and seconds', () => {
    expect(mmss(725)).toBe('12:05')
    expect(mmss(59)).toBe('0:59')
    expect(mmss(-3)).toBe('0:00')
  })
})
