import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const superAdmin = () => ({ ...makeMe({ is_super_admin: true, name: 'Owner' }, []), role: null, hotel: null, branches: [] })

const dashboard = {
  body: {
    hotels: { total: 3, by_status: { trial: 1, active: 1, grace: 0, suspended: 1 } },
    income_this_month: { ETB: '12000.00' },
    trials_ending_soon: [],
    needs_attention: [{ id: 3, name: 'Lalibela Lodge', status: 'suspended', subscription_ends_at: '2026-10-01', trial_ends_at: null }],
  },
}

const plans = {
  body: {
    data: [
      { id: 2, name: 'Standard', code: 'standard', description: 'Mid-size hotels', price_per_room: '100.00', minimum_monthly: '2000.00', yearly_free_months: 2, currency: 'ETB', is_custom: false, max_rooms: 60, max_branches: 2, max_staff: 15, modules: ['housekeeping'], is_active: true, hotels_count: 4 },
    ],
  },
}

const hotels = {
  body: {
    data: [
      { id: 1, name: 'Sheger Hotel', city: 'Addis Ababa', status: 'active', plan: { id: 2, name: 'Standard' }, subscription_ends_at: '2026-12-31', trial_ends_at: null, branches_count: 2 },
    ],
    counts: { active: 1 },
    meta: { total: 1, last_page: 1, current_page: 1 },
  },
}

describe('Super Admin area', () => {
  it('opens the command center for the HotelMS team', async () => {
    mockApi({ 'GET /me': { body: superAdmin() }, 'GET /admin/dashboard': dashboard })
    renderApp('/')
    expect(await screen.findByRole('heading', { name: 'Command center' })).toBeInTheDocument()
    expect(await screen.findByText('ETB 12,000.00')).toBeInTheDocument()
    expect(screen.getByText('Lalibela Lodge')).toBeInTheDocument()
    const nav = screen.getByRole('navigation', { name: 'Main' })
    expect(nav).toHaveTextContent('Hotels')
    expect(nav).not.toHaveTextContent('Room board')
  })

  it('keeps hotel staff out of the Super Admin area', async () => {
    mockApi({ 'GET /me': { body: makeMe() }, 'GET /health': { body: {} } })
    renderApp('/admin/hotels')
    expect(await screen.findByRole('heading', { name: 'Dashboard' })).toBeInTheDocument()
  })

  it('lists hotels with status filters', async () => {
    mockApi({ 'GET /me': { body: superAdmin() }, 'GET /admin/hotels': hotels })
    renderApp('/admin/hotels')
    expect(await screen.findByRole('link', { name: /Sheger Hotel/ })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Active (1)' })).toBeInTheDocument()
    expect(screen.getByText('31 Dec 2026')).toBeInTheDocument()
  })

  it('creates a hotel and shows the Hotel Admin sign-in details', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: superAdmin() },
      'GET /admin/plans': plans,
      'GET /admin/settings': { body: { data: { default_trial_days: 30, default_trial_plan_id: null, grace_days: 3 } } },
      'POST /admin/hotels': {
        status: 201,
        body: {
          data: { id: 9, name: 'Sheger Hotel', status: 'trial' },
          admin: { name: 'Meron', email: 'meron@sheger.test', temporary_password: 'Tmp4Pass9xyz', email_sent: true },
        },
      },
    })
    renderApp('/admin/hotels/new')

    await userEvent.type(await screen.findByLabelText('Hotel name'), 'Sheger Hotel')
    await userEvent.click(screen.getByRole('button', { name: /Next/ }))
    expect(await screen.findByRole('radio', { name: 'Standard' })).toBeChecked()
    await userEvent.click(screen.getByRole('button', { name: /Next/ }))
    await userEvent.type(await screen.findByLabelText('Full name'), 'Meron')
    await userEvent.type(screen.getByLabelText('Email (used to sign in)'), 'meron@sheger.test')
    await userEvent.click(screen.getByRole('button', { name: 'Create hotel' }))

    expect(await screen.findByText('Tmp4Pass9xyz')).toBeInTheDocument()
    const create = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/admin/hotels') && init?.method === 'POST')
    expect(JSON.parse(String(create?.[1]?.body))).toMatchObject({
      name: 'Sheger Hotel',
      plan_id: 2,
      admin: { name: 'Meron', email: 'meron@sheger.test' },
    })
  })

  it('shows plan prices and limits', async () => {
    mockApi({ 'GET /me': { body: superAdmin() }, 'GET /admin/plans': plans })
    renderApp('/admin/plans')
    expect(await screen.findByText('ETB 100.00 per room / month · minimum ETB 2,000.00')).toBeInTheDocument()
    expect(screen.getByText('4 hotels')).toBeInTheDocument()
  })
})

describe('hotel page', () => {
  it('gives a Hotel Admin a new temporary password and shows it', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: superAdmin() },
      'GET /admin/hotels/1': {
        body: {
          data: { id: 1, name: 'Sheger Hotel', status: 'trial', plan: { id: 2, name: 'Standard' }, trial_plan: null, billing_cycle: 'monthly', trial_ends_at: '2026-12-05', subscription_ends_at: null, suspend_reason: null, email: null, phone: null, address: null, city: 'Addis Ababa' },
          branches: [{ id: 1, name: 'Main branch', city: 'Addis Ababa', status: 'active' }],
          admins: [{ id: 7, name: 'Meron', email: 'meron@sheger.test', phone: null, last_login_at: null, must_change_password: true }],
          payments: [],
        },
      },
      'POST /admin/hotels/1/users/7/reset-password': { body: { temporary_password: 'NewTmp123abc', email_sent: true } },
    })
    renderApp('/admin/hotels/1')

    await userEvent.click(await screen.findByRole('button', { name: 'Reset password' }))
    await userEvent.click(screen.getByRole('button', { name: 'Create new password' }))

    expect(await screen.findByText('NewTmp123abc')).toBeInTheDocument()
    expect(screen.getByText('Also emailed to meron@sheger.test.')).toBeInTheDocument()
    const call = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/reset-password'))
    expect(JSON.parse(String(call?.[1]?.body))).toEqual({ send_email: true })
  })
})

describe('collapsible sidebar', () => {
  it('collapses, remembers it, and expands again', async () => {
    mockApi({ 'GET /me': { body: superAdmin() }, 'GET /admin/dashboard': dashboard })
    renderApp('/admin')
    await userEvent.click(await screen.findByRole('button', { name: 'Collapse menu' }))
    expect(screen.getByRole('complementary')).toHaveAttribute('data-collapsed', 'true')
    expect(localStorage.getItem('hms-sidebar-collapsed')).toBe('1')
    // Collapsed: icons only; each link still has its name for screen readers and a tooltip.
    expect(screen.getByRole('link', { name: 'Hotels' })).toBeInTheDocument()
    expect(screen.getAllByRole('tooltip').map((el) => el.textContent)).toContain('Hotels')

    await userEvent.click(screen.getByRole('button', { name: 'Expand menu' }))
    expect(screen.getByRole('complementary')).toHaveAttribute('data-collapsed', 'false')
  })
})

describe('platform settings', () => {
  const settings = {
    body: {
      data: {
        platform_name: 'HotelMS', support_email: null, support_phone: null, default_trial_days: 30, default_trial_plan_id: null, grace_days: 3,
        brand_color: '#0f766e', brand_logo_path: null, brand_logo_url: null, login_message: null, email_from_name: 'HotelMS', email_footer: null,
      },
    },
  }

  it('saves branding from its own tab', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: superAdmin() },
      'GET /admin/settings': settings,
      'GET /admin/plans': plans,
      'PUT /admin/settings': settings,
    })
    renderApp('/admin/settings')

    await userEvent.click(await screen.findByRole('tab', { name: 'Branding' }))
    await userEvent.click(screen.getByRole('button', { name: '#7c3aed' }))
    const message = screen.getByLabelText('Sign-in message')
    await userEvent.type(message, 'Welcome back')
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    expect(await screen.findByText('Saved.')).toBeInTheDocument()
    const call = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/admin/settings') && init?.method === 'PUT')
    expect(JSON.parse(String(call?.[1]?.body))).toMatchObject({ brand_color: '#7c3aed', login_message: 'Welcome back' })
  })

  it('has general, billing, branding and email tabs', async () => {
    mockApi({ 'GET /me': { body: superAdmin() }, 'GET /admin/settings': settings, 'GET /admin/plans': plans })
    renderApp('/admin/settings')
    for (const name of ['General', 'Trials and billing', 'Branding', 'Emails']) {
      expect(await screen.findByRole('tab', { name })).toBeInTheDocument()
    }
  })
})
