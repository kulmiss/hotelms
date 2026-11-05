import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const ALL = ['settings.hotel', 'settings.branches', 'settings.money', 'settings.exchange_rates', 'settings.charges', 'settings.policies', 'settings.modules', 'subscription.view']
const meAdmin = () => ({ ...makeMe({}, ALL), hotel: { id: 1, name: 'Sheger Hotel', status: 'active', default_currency: 'ETB', timezone: 'Africa/Addis_Ababa', modules: ['housekeeping'] } })

describe('hotel settings', () => {
  it('opens the first section and lists all sections the role may use', async () => {
    mockApi({
      'GET /me': { body: meAdmin() },
      'GET /settings/hotel': { body: { data: { name: 'Sheger Hotel', legal_name: null, tin: null, vat_number: null, email: null, phone: null, city: null, address: null, logo_url: null, invoice_prefix: 'INV', invoice_footer: null } } },
    })
    renderApp('/settings')
    expect(await screen.findByLabelText('Hotel name')).toHaveValue('Sheger Hotel')
    const nav = screen.getByRole('navigation', { name: 'Settings' })
    for (const s of ['Hotel profile', 'Branches', 'Taxes', 'Booking rules', 'Modules', 'Subscription']) expect(nav).toHaveTextContent(s)
    expect(nav).not.toHaveTextContent('Attendance') // module is off
  })

  it('adds a tax from the list', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: meAdmin() },
      'GET /settings/taxes': { body: { data: [{ id: 1, name: 'VAT', rate: '15.00', applies_to: 'all', is_inclusive: false, is_active: true }] } },
      'POST /settings/taxes': { status: 201, body: { data: {} } },
    })
    renderApp('/settings/taxes')

    expect(await screen.findByText('Example: a room at ETB 1,000.00 costs ETB 1,150.00 with VAT.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /Add tax/ }))
    await userEvent.type(screen.getByLabelText('Name'), 'Tourism levy')
    await userEvent.type(screen.getByLabelText('Rate (%)'), '2')
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    const call = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/settings/taxes') && init?.method === 'POST')
    expect(JSON.parse(String(call?.[1]?.body))).toMatchObject({ name: 'Tourism levy', rate: '2', applies_to: 'all', is_active: true })
  })

  it('shows modules outside the plan as locked', async () => {
    mockApi({
      'GET /me': { body: meAdmin() },
      'GET /settings/modules': {
        body: {
          data: [
            { module: 'housekeeping', available: true, enabled: true, plans: ['Standard', 'Pro'] },
            { module: 'attendance', available: false, enabled: false, plans: ['Pro'] },
          ],
        },
      },
    })
    renderApp('/settings/modules')
    expect(await screen.findByRole('switch', { name: 'Housekeeping' })).toBeChecked()
    expect(screen.getByRole('switch', { name: 'Attendance' })).toBeDisabled()
    expect(screen.getByText('Included in: Pro')).toBeInTheDocument()
  })

  it('keeps receptionists out of settings', async () => {
    mockApi({ 'GET /me': { body: makeMe({}, ['frontdesk.view']) }, 'GET /health': { body: {} } })
    renderApp('/settings/taxes')
    expect(await screen.findByRole('heading', { name: 'Dashboard' })).toBeInTheDocument()
  })
})

describe('rooms and prices', () => {
  const meRooms = () => ({ ...meAdmin(), permissions: [...ALL, 'settings.rooms', 'settings.pricing'] })

  it('previews the room generator before creating rooms', async () => {
    const fetchMock = mockApi({
      'GET /me': { body: meRooms() },
      'GET /settings/room-types': { body: { data: [{ id: 3, branch_id: 1, name: 'Standard', nightly_price: '2500.00', currency: 'ETB' }] } },
      'GET /settings/rooms': { body: { data: [], limit: { used: 0, max: 20 } } },
      'POST /settings/rooms/generate': (init) => {
        const body = JSON.parse(String(init?.body))
        if (body.preview) {
          return { body: { data: [{ number: '101', floor: '1', room_type_id: 3, room_type: 'Standard' }, { number: '102', floor: '1', room_type_id: 3, room_type: 'Standard' }], existing: [], limit: { used: 0, max: 20 } } }
        }
        return { status: 201, body: { created: 2 } }
      },
    })
    renderApp('/settings/rooms')

    expect(await screen.findByText('0 of 20 rooms used')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Room generator' }))
    expect(await screen.findByText('2 rooms will be created:')).toBeInTheDocument()
    expect(screen.getByText('102')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Create 2 rooms' }))

    const created = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/settings/rooms/generate') && !JSON.parse(String(init?.body)).preview)
    expect(JSON.parse(String(created?.[1]?.body))).toMatchObject({ room_type_id: 3, first_floor: 1, floors: 1, rooms_per_floor: 10 })
  })

  it('asks for a room type before rooms can be added', async () => {
    mockApi({
      'GET /me': { body: meRooms() },
      'GET /settings/room-types': { body: { data: [] } },
      'GET /settings/rooms': { body: { data: [], limit: { used: 0, max: null } } },
    })
    renderApp('/settings/rooms')
    expect(await screen.findByText('Add a room type for this branch first.')).toBeInTheDocument()
  })

  it('shows a weekend rule and the stay price night by night', async () => {
    mockApi({
      'GET /me': { body: meRooms() },
      'GET /settings/guest-types': { body: { data: [] } },
      'GET /settings/room-types': { body: { data: [{ id: 3, branch_id: 1, name: 'Standard', nightly_price: '2500.00', currency: 'ETB' }] } },
      'GET /settings/rate-rules': { body: { data: [{ id: 1, name: 'Weekend', rule_type: 'weekend', days_of_week: [5, 6], room_type_id: null, adjustment: 'percent', value: '20.00', priority: 10, is_active: true }] } },
      'GET /settings/price-preview': {
        body: { data: { total: '5500.00', currency: 'ETB', nights: [{ date: '2026-11-12', amount: '2500.00', rule: null }, { date: '2026-11-13', amount: '3000.00', rule: { name: 'Weekend' } }] } },
      },
    })
    renderApp('/settings/rate-rules')
    expect(await screen.findByText('Fri, Sat')).toBeInTheDocument()
    expect(screen.getByText('+20%')).toBeInTheDocument()
    expect(await screen.findByText('ETB 5,500.00')).toBeInTheDocument()
  })
})

describe('import from Excel', () => {
  const meRooms = () => ({ ...meAdmin(), permissions: [...ALL, 'settings.rooms'] })
  const columns = ['branch', 'room_type', 'room_number', 'floor', 'nightly_price', 'day_use_price', 'hourly_price', 'guests_included', 'max_guests', 'amenities', 'notes']

  it('previews the file, shows row errors and imports when fixed', async () => {
    let fixed = false
    const fetchMock = mockApi({
      'GET /me': { body: meRooms() },
      'GET /settings/imports': { body: { data: [] } },
      'POST /settings/import/parse': { body: { file_name: 'rooms.xlsx', headers: columns, rows: [['Bole branch', 'Standard', '101', '1', 'abc', '', '', '', '', '', '']], columns } },
      'POST /settings/import/check': (init) => {
        fixed = JSON.parse(String(init?.body)).rows[0].nightly_price === '2500'
        return { body: { data: { errors: fixed ? {} : { '1': { nightly_price: 'Must be a number.' } }, general: [], summary: { rooms: 1, new_types: ['Standard (Bole branch)'] } } } }
      },
      'POST /settings/import': { status: 201, body: { data: { id: 1, file_name: 'rooms.xlsx', rooms: 1, room_types: 1, by: 'Meron', created_at: '2026-11-05T10:00:00Z', undone_at: null, can_undo: true } } },
    })
    renderApp('/settings/import')

    await userEvent.upload(await screen.findByLabelText('Upload file'), new File(['x'], 'rooms.xlsx'))
    expect(await screen.findByText('Must be a number.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Import 1 room' })).toBeDisabled()

    const price = screen.getByLabelText('Price per night 1')
    await userEvent.clear(price)
    await userEvent.type(price, '2500')
    expect(await screen.findByText('Ready to import')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Import 1 room' }))

    expect(await screen.findByText('Imported 1 rooms and 1 new room types.')).toBeInTheDocument()
    const call = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/settings/import') && init?.method === 'POST')
    expect(JSON.parse(String(call?.[1]?.body))).toMatchObject({ file_name: 'rooms.xlsx', rows: [{ branch: 'Bole branch', room_number: '101', nightly_price: '2500' }] })
  })

  it('asks to match columns for a file in another layout', async () => {
    mockApi({
      'GET /me': { body: meRooms() },
      'GET /settings/imports': { body: { data: [] } },
      'POST /settings/import/parse': { body: { file_name: 'old.csv', headers: ['Room No', 'Type', 'Rate'], rows: [['5', 'Single', '900']], columns } },
    })
    renderApp('/settings/import')
    await userEvent.upload(await screen.findByLabelText('Upload file'), new File(['x'], 'old.csv'))
    expect(await screen.findByText(/Tell us which column/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Preview' })).toBeDisabled() // room type not matched yet
    await userEvent.selectOptions(screen.getByLabelText('Room type'), 'Type')
    expect(screen.getByRole('button', { name: 'Preview' })).toBeEnabled()
  })
})
