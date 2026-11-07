import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

const tech = () => ({ ...makeMe({ is_super_admin: true, name: 'Abel' }, []), role: null, hotel: null, branches: [], platform: { role: 'tech', permissions: ['hotels.view', 'docs.view'] } })
const list = { data: [{ name: 'readme', title: 'HotelMS – developer documentation' }, { name: 'architecture', title: 'Architecture' }], available: true }

describe('developer docs in the app', () => {
  it('shows the docs and opens a linked document inside the app', async () => {
    mockApi({
      'GET /me': { body: tech() },
      'GET /admin/docs': { body: list },
      'GET /admin/docs/readme': { body: { data: { name: 'readme', title: 'Start', html: '<h1>Start here</h1><p>See <a href="/admin/docs/architecture#money">money</a>.</p>', updated_at: '2026-11-07T10:00:00+03:00' } } },
      'GET /admin/docs/architecture': { body: { data: { name: 'architecture', title: 'Architecture', html: '<h1>Architecture</h1><p>Money is a string.</p>', updated_at: '2026-11-07T10:00:00+03:00' } } },
    })
    renderApp('/admin/docs')

    expect(await screen.findByRole('heading', { name: 'Start here' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Developer docs' })).toBeInTheDocument() // in the menu
    await userEvent.click(screen.getByRole('link', { name: 'money' }))
    expect(await screen.findByText('Money is a string.')).toBeInTheDocument()
  })
})
