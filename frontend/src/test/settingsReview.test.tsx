import { screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

describe('settings, staff and roles review (part 6)', () => {
  it('support ("log in as hotel") is not sent to "Set a new password" for a person with a temporary password', async () => {
    const me = makeMe({ name: 'New Staff', must_change_password: true }, ['reservations.view'])
    mockApi({ 'GET /me': { body: { ...me, impersonation: { by: 'Owner', reason: 'Help', started_at: '2026-11-05T10:00:00Z' } } } })
    renderApp('/')

    expect((await screen.findAllByText(/signed in as New Staff/i)).length).toBeGreaterThan(0)
    expect(screen.queryByText('Set a new password')).not.toBeInTheDocument()
  })

  it('still sends the person themselves to "Set a new password"', async () => {
    mockApi({ 'GET /me': { body: makeMe({ name: 'New Staff', must_change_password: true }, ['reservations.view']) } })
    renderApp('/')

    expect(await screen.findByText('Set a new password')).toBeInTheDocument()
  })
})
