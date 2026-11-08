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
    expect(await screen.findByRole('heading', { name: "You can't open this page" })).toBeInTheDocument()
    // owner (QA 8 Nov): a clear "no access" page instead of a silent jump to the dashboard
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
    const sidebar = () => document.querySelector('[data-slot="sidebar"]')
    expect(sidebar()).toHaveAttribute('data-state', 'collapsed')
    expect(localStorage.getItem('hms-sidebar-collapsed')).toBe('1')
    // Collapsed: icons only; each link still has its name for screen readers and a tooltip.
    expect(screen.getByRole('link', { name: 'Hotels' })).toBeInTheDocument()
    await userEvent.hover(screen.getByRole('link', { name: 'Hotels' }))
    expect(await screen.findByRole('tooltip')).toHaveTextContent('Hotels')

    await userEvent.click(screen.getByRole('button', { name: 'Expand menu' }))
    expect(sidebar()).toHaveAttribute('data-state', 'expanded')
  })

  // Tablet widths (768–1023px): icons only until the person opens it; their own choice is remembered.
  const tablet = () =>
    vi.spyOn(window, 'matchMedia').mockImplementation(
      (query: string) =>
        ({ matches: query.includes('min-width: 768px'), media: query, onchange: null, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {}, dispatchEvent: () => false }) as MediaQueryList,
    )

  it('starts collapsed on a tablet', async () => {
    const spy = tablet()
    mockApi({ 'GET /me': { body: superAdmin() }, 'GET /admin/dashboard': dashboard })
    renderApp('/admin')
    expect(await screen.findByRole('button', { name: 'Expand menu' })).toBeInTheDocument()
    expect(document.querySelector('[data-slot="sidebar"]')).toHaveAttribute('data-state', 'collapsed')
    spy.mockRestore()
  })

  it('keeps the choice made on this tablet', async () => {
    const spy = tablet()
    localStorage.setItem('hms-sidebar-collapsed', '0')
    mockApi({ 'GET /me': { body: superAdmin() }, 'GET /admin/dashboard': dashboard })
    renderApp('/admin')
    expect(await screen.findByRole('button', { name: 'Collapse menu' })).toBeInTheDocument()
    spy.mockRestore()
  })
})

describe('command menu shortcut', () => {
  it('opens with "/" but not while typing in a box', async () => {
    mockApi({ 'GET /me': { body: superAdmin() }, 'GET /admin/dashboard': dashboard })
    renderApp('/admin')
    await screen.findByRole('heading', { name: 'Command center' })
    const box = document.createElement('input')
    document.body.appendChild(box)
    await userEvent.type(box, '/')
    expect(box).toHaveValue('/')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    box.remove()

    await userEvent.keyboard('/')
    expect(await screen.findByRole('combobox', { name: 'Search hotels and people' })).toBeInTheDocument()
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
      'GET /me': { body: { ...superAdmin(), platform: { role: 'owner', permissions: [] } } },
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
    expect(await screen.findByText('Changes saved')).toBeInTheDocument()
    const call = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/admin/settings') && init?.method === 'PUT')
    expect(JSON.parse(String(call?.[1]?.body))).toMatchObject({ brand_color: '#7c3aed', login_message: 'Welcome back' })
  })

  it('shows branding as Owner only to other team members, and saves General without owner fields', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: { ...superAdmin(), platform: { role: 'finance', permissions: ['billing.manage'] } } },
      'GET /admin/settings': settings,
      'GET /admin/plans': plans,
      'PUT /admin/settings': settings,
    })
    renderApp('/admin/settings')

    await userEvent.click(await screen.findByRole('button', { name: 'Save' }))
    await screen.findByText('Saved.')
    const call = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/admin/settings') && init?.method === 'PUT')
    const body = JSON.parse(String(call?.[1]?.body))
    expect(body).not.toHaveProperty('platform_name')
    expect(body).not.toHaveProperty('two_factor_admins')

    await userEvent.click(screen.getByRole('tab', { name: 'Branding' }))
    expect(await screen.findByText('Only the Owner can change this.')).toBeInTheDocument()
  })

  it('has general, billing, branding and email tabs', async () => {
    mockApi({ 'GET /me': { body: superAdmin() }, 'GET /admin/settings': settings, 'GET /admin/plans': plans })
    renderApp('/admin/settings')
    for (const name of ['General', 'Trials and billing', 'Branding', 'Emails']) {
      expect(await screen.findByRole('tab', { name })).toBeInTheDocument()
    }
  })
})

describe('log in as hotel', () => {
  const hotelDetail = {
    body: {
      data: { id: 1, name: 'Sheger Hotel', status: 'active', is_demo: false, plan: { id: 2, name: 'Standard' }, trial_plan: null, billing_cycle: 'monthly', trial_ends_at: null, subscription_ends_at: '2026-12-31', suspend_reason: null, email: null, phone: null, address: null, city: 'Addis Ababa' },
      branches: [],
      admins: [{ id: 7, name: 'Meron', email: 'meron@sheger.test', phone: null, last_login_at: null, must_change_password: false }],
      payments: [],
    },
  }

  it('opens the new demo hotel after "Reset demo now"', async () => {
    const owner = { ...superAdmin(), platform: { role: 'owner', permissions: ['hotels.view', 'hotels.create'] } }
    const demo = (id: number) => ({ body: { ...hotelDetail.body, data: { ...hotelDetail.body.data, id, name: `Demo ${id}`, is_demo: true } } })
    let wiped = false // after the reset the old demo hotel is gone (404), like the real server
    mockApi({
      'GET /me': { body: owner },
      'GET /admin/hotels/1': () => (wiped ? { status: 404, body: { message: 'Not found' } } : demo(1)),
      'POST /admin/hotels/1/demo/reset': () => {
        wiped = true
        return { body: { data: { id: 5 } } }
      },
      'GET /admin/hotels/5': demo(5),
    })
    renderApp('/admin/hotels/1')

    await userEvent.click(await screen.findByRole('button', { name: 'Reset demo now' }))
    expect(await screen.findByRole('heading', { name: 'Demo 5' })).toBeInTheDocument()
    expect(await screen.findByText('Demo hotel reset. Fresh sample data is ready.')).toBeInTheDocument()
    expect(screen.queryByText("Can't reach the server. Check the internet and try again.")).not.toBeInTheDocument()
    expect(screen.queryByText('This hotel no longer exists. Go back to the hotels list.')).not.toBeInTheDocument()
  })

  it('enters the hotel with a reason and shows the support banner', async () => {
    let inside = false
    const owner = { ...superAdmin(), platform: { role: 'owner', permissions: ['hotels.view', 'hotels.impersonate'] } }
    const fetchMock = mockApi({
      'GET /me': () => ({
        body: inside
          ? { ...makeMe({ name: 'Meron' }), impersonation: { by: 'Owner', reason: 'Help with rooms', started_at: '2026-11-05T10:00:00Z' } }
          : owner,
      }),
      'GET /admin/hotels/1': hotelDetail,
      'POST /admin/hotels/1/impersonate': () => {
        inside = true
        return { body: { message: 'ok', as: { id: 7, name: 'Meron' } } }
      },
      'GET /health': { body: {} },
    })
    renderApp('/admin/hotels/1')

    await userEvent.click(await screen.findByRole('button', { name: 'Log in as hotel' }))
    await userEvent.type(screen.getByLabelText('Reason'), 'Help with rooms')
    await userEvent.click(screen.getByRole('button', { name: 'Log in to hotel' }))

    expect(await screen.findByText(/You are signed in as Meron at Sheger Hotel for support/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Return to admin' })).toBeInTheDocument()
    const call = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/impersonate'))
    expect(JSON.parse(String(call?.[1]?.body))).toMatchObject({ reason: 'Help with rooms' })
  })

  it('hides "Log in as hotel" from team roles without that permission', async () => {
    mockApi({
      'GET /me': { body: { ...superAdmin(), platform: { role: 'finance', permissions: ['hotels.view', 'billing.manage'] } } },
      'GET /admin/hotels/1': hotelDetail,
    })
    renderApp('/admin/hotels/1')
    expect(await screen.findByRole('heading', { name: 'Sheger Hotel' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Log in as hotel' })).not.toBeInTheDocument()
  })
})

describe('switches', () => {
  const tech = () => ({ ...superAdmin(), platform: { role: 'owner', permissions: ['hotels.view', 'switches.manage'] } })
  const data = {
    registry: [
      { key: 'feature.telegram', type: 'bool', group: 'features', default: true },
      { key: 'limit.rooms', type: 'int', group: 'limits', default: null },
      { key: 'emergency.maintenance', type: 'bool', group: 'emergency', default: false },
    ],
    plans: [{ id: 2, name: 'Standard' }],
    switches: [{ id: 7, key: 'limit.rooms', level: 'hotel', target_id: 1, target_name: 'Sheger Hotel', value: 80, starts_at: null, ends_at: '2026-12-31T00:00:00+03:00', reason: 'Gift', scheduled: false, expired: false }],
    banners: [],
  }

  it('shows the gifts and turns a feature off for one plan', async () => {
    const fetchMock = mockApi({ 'GET /me': { body: tech() }, 'GET /admin/switches': { body: data }, 'POST /admin/switches': { status: 201, body: { data: {} } } })
    renderApp('/admin/switches')

    expect(await screen.findByText('Hotel: Sheger Hotel')).toBeInTheDocument()
    expect(screen.getByText('80')).toBeInTheDocument()
    await userEvent.click(screen.getAllByRole('button', { name: 'Change' })[1])
    await userEvent.selectOptions(screen.getByLabelText('For'), 'plan')
    await userEvent.selectOptions(screen.getByLabelText('Plan'), '2')
    await userEvent.selectOptions(screen.getByLabelText('Setting'), 'off')
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    const call = fetchMock.mock.calls.find(([u, i]) => String(u).endsWith('/admin/switches') && i?.method === 'POST')
    expect(JSON.parse(String(call?.[1]?.body))).toMatchObject({ key: 'feature.telegram', level: 'plan', target_id: 2, value: false, starts_at: null, ends_at: null })
  })

  it('shows the maintenance screen to hotel staff', async () => {
    mockApi({ 'GET /me': { body: { ...makeMe(), emergency: { maintenance: true, read_only: false } } } })
    renderApp('/')
    expect(await screen.findByText('We are improving HotelMS')).toBeInTheDocument()
  })
})


describe('Super Admin areas', () => {
  const owner = () => ({ ...superAdmin(), platform: { role: 'owner', permissions: ['hotels.view', 'support.answer', 'system.view', 'team.manage', 'switches.manage'] } })

  it('answers a support request with a reply', async () => {
    const req = { id: 4, subject: 'Printer', status: 'open', priority: 'urgent', channel: 'app', from: 'Meron', hotel: { id: 1, name: 'Sheger Hotel' }, assigned_to: null, last_message_at: '2026-11-06T08:00:00+03:00', created_at: null }
    const detail = { ...req, contact: { name: 'Meron', email: 'm@x.test', phone: null }, hotel_context: { status: 'active', plan: 'Standard', city: 'Addis Ababa', phone: null, email: null }, team: [], messages: [{ id: 1, kind: 'message', body: 'Blank receipt', author: 'Meron', created_at: null }] }
    const fetchMock = mockApi({
      'GET /me': { body: owner() },
      'GET /admin/support': { body: { data: [req], counts: { open: 1, waiting: 0, solved: 0 }, meta: { total: 1, last_page: 1, current_page: 1 } } },
      'GET /admin/support/4': { body: { data: detail } },
      'POST /admin/support/4/messages': { body: { data: detail } },
    })
    renderApp('/admin/support')

    await userEvent.click(await screen.findByRole('button', { name: /Printer/ }))
    expect(await screen.findByText('Blank receipt')).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Your answer'), 'Choose 80mm')
    await userEvent.click(screen.getByRole('button', { name: 'Reply' }))
    const call = fetchMock.mock.calls.find(([u, i]) => String(u).endsWith('/admin/support/4/messages') && i?.method === 'POST')
    expect(JSON.parse(String(call?.[1]?.body))).toMatchObject({ body: 'Choose 80mm', kind: 'reply', solve: false })
  })

  it('shows system health checks', async () => {
    const ok = { ok: true }
    mockApi({
      'GET /me': { body: owner() },
      'GET /admin/system': {
        body: {
          data: {
            checks: { database: { ...ok, ms: 3 }, cache: { ...ok, driver: 'database' }, scheduler: { ok: false, last_run: null, problem: 'The cron job has never run.' }, jobs: { ...ok, waiting: 0, failed: 0, recent_failures: [] }, backups: { ...ok, daily: null, small: null }, disk: { ...ok, free_mb: 9000 } },
            errors: [], versions: { app: '1.0.0', php: '8.3', laravel: '13', database: 'mysql' }, checked_at: '2026-11-06T09:00:00+03:00',
          },
        },
      },
    })
    renderApp('/admin/system')
    expect(await screen.findByText('The cron job has never run.')).toBeInTheDocument()
    expect(screen.getByText('Answers in 3 ms')).toBeInTheDocument()
  })

  it('lets a hotel ask for help', async () => {
    const created = { id: 9, subject: 'Wifi', status: 'open', priority: 'normal', channel: 'app', from: 'Meron', last_message_at: null, created_at: null, messages: [{ id: 1, kind: 'message', body: 'No wifi', author: 'Meron', created_at: null }] }
    const fetchMock = mockApi({ 'GET /me': { body: makeMe() }, 'GET /support': { body: { data: [] } }, 'POST /support': { status: 201, body: { data: created } }, 'GET /support/9': { body: { data: created } } })
    renderApp('/help?tab=ask')

    await userEvent.click(await screen.findByRole('button', { name: 'New question' }))
    await userEvent.type(screen.getByLabelText('Subject'), 'Wifi')
    await userEvent.type(screen.getByLabelText('Your question'), 'No wifi')
    await userEvent.click(screen.getByRole('button', { name: 'Send' }))
    expect(await screen.findByText('No wifi')).toBeInTheDocument()
    const call = fetchMock.mock.calls.find(([u, i]) => String(u).endsWith('/support') && i?.method === 'POST')
    expect(JSON.parse(String(call?.[1]?.body))).toEqual({ subject: 'Wifi', body: 'No wifi', priority: 'normal' })
  })
})
