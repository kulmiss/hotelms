import { useState } from 'react'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { DataTable } from '@/components/ui/DataTable'
import { CollapsibleToolbar, ToolbarSearch } from '@/components/ui/CollapsibleToolbar'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

// P19 (owner): below 1024px the filters fold behind one "Filters" button, closed at first.
const screenWidth = (width: number) =>
  vi.spyOn(window, 'matchMedia').mockImplementation((query: string) => {
    const max = Number(/max-width:\s*(\d+)px/.exec(query)?.[1] ?? Infinity)
    const min = Number(/min-width:\s*(\d+)px/.exec(query)?.[1] ?? 0)
    return { matches: width <= max && width >= min, media: query, onchange: null, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {}, dispatchEvent: () => false } as MediaQueryList
  })

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  sessionStorage.clear()
  localStorage.clear()
})

type Row = { id: number; name: string; status: string }
const rows: Row[] = [
  { id: 1, name: 'Bekele', status: 'paid' },
  { id: 2, name: 'Almaz', status: 'open' },
]

/** A list with a search box, a status filter and a branch filter (like the bills list). */
function List() {
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('')
  const [branch, setBranch] = useState('')
  const active = [
    ...(status ? [{ key: 'status', label: `Status: ${status}`, onRemove: () => setStatus('') }] : []),
    ...(branch ? [{ key: 'branch', label: `Branch: ${branch}`, onRemove: () => setBranch('') }] : []),
  ]
  return (
    <DataTable<Row>
      id="test-list"
      rows={rows.filter((r) => (!status || r.status === status) && r.name.toLowerCase().includes(q.toLowerCase()))}
      rowKey={(r) => r.id}
      csvName="people"
      canExport
      search={<input type="search" aria-label="Search" value={q} onChange={(e) => setQ(e.target.value)} />}
      filters={
        <div>
          <ToolbarSearch />
          <select aria-label="Status" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All</option>
            <option value="paid">paid</option>
            <option value="open">open</option>
          </select>
          <select aria-label="Branch" value={branch} onChange={(e) => setBranch(e.target.value)}>
            <option value="">All</option>
            <option value="Main">Main</option>
          </select>
        </div>
      }
      activeFilters={active}
      onClearFilters={() => {
        setStatus('')
        setBranch('')
      }}
      columns={[
        { key: 'name', label: 'Name', hideable: false, sortValue: (r) => r.name, render: (r) => r.name },
        { key: 'status', label: 'Status', sortValue: (r) => r.status, render: (r) => r.status },
      ]}
    />
  )
}

const filtersButton = () => screen.getByRole('button', { name: /^Filters/ })
const panel = () => document.getElementById(filtersButton().getAttribute('aria-controls')!)!

describe('collapsible filters (P19)', () => {
  it.each([375, 768, 1023])('starts closed at %ipx, with the search box still in view', (width) => {
    screenWidth(width)
    render(<List />)
    expect(filtersButton()).toHaveAttribute('aria-expanded', 'false')
    expect(panel()).toHaveAttribute('data-state', 'closed')
    expect(panel()).toHaveAttribute('inert')
    expect(screen.getByRole('searchbox', { name: 'Search' })).toBeInTheDocument()
    expect(screen.getAllByRole('searchbox')).toHaveLength(1) // not twice
    // Excel and Columns fold away too.
    expect(within(panel()).getByRole('button', { name: /Excel/ })).toBeInTheDocument()
    expect(within(panel()).getByRole('button', { name: /Columns/ })).toBeInTheDocument()
  })

  it('shows everything as before on desktop (1024px and up), with no Filters button', () => {
    screenWidth(1280)
    render(<List />)
    expect(screen.queryByRole('button', { name: /^Filters/ })).not.toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: 'Status' })).toBeVisible()
    expect(screen.getByRole('searchbox', { name: 'Search' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Columns/ })).toBeInTheDocument()
  })

  it.each([375, 1280])('keeps the "actions" buttons (e.g. Refresh) in view at %ipx', (width) => {
    screenWidth(width)
    render(
      <CollapsibleToolbar id="test-actions" actions={<button type="button">Refresh</button>}>
        <select aria-label="Period" />
      </CollapsibleToolbar>,
    )
    const refresh = screen.getByRole('button', { name: 'Refresh' })
    if (width < 1024) expect(panel()).not.toContainElement(refresh) // next to Filters, not folded away
    else expect(screen.queryByRole('button', { name: /^Filters/ })).not.toBeInTheDocument()
  })

  it('opens and closes, and remembers it for this session only', async () => {
    screenWidth(768)
    const { unmount } = render(<List />)
    await userEvent.click(filtersButton())
    expect(filtersButton()).toHaveAttribute('aria-expanded', 'true')
    expect(panel()).not.toHaveAttribute('inert')
    unmount()

    render(<List />) // the same session: still open
    expect(filtersButton()).toHaveAttribute('aria-expanded', 'true')
    await userEvent.click(filtersButton())
    expect(filtersButton()).toHaveAttribute('aria-expanded', 'false')
  })

  it('starts closed in a new session even after it was left open', () => {
    screenWidth(375)
    sessionStorage.setItem('hms-filters-open-test-list', '1')
    sessionStorage.clear() // a new session has nothing saved
    render(<List />)
    expect(filtersButton()).toHaveAttribute('aria-expanded', 'false')
  })

  it('counts the filters that are on and shows each as a chip to remove', async () => {
    screenWidth(768)
    render(<List />)
    expect(filtersButton()).toHaveTextContent(/^Filters$/)
    expect(screen.queryByRole('button', { name: 'Clear all' })).not.toBeInTheDocument()

    await userEvent.click(filtersButton())
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Status' }), 'paid')
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Branch' }), 'Main')
    expect(filtersButton()).toHaveTextContent('Filters2')
    const chips = screen.getByRole('list', { name: 'Filters that are on' })
    expect(within(chips).getAllByRole('listitem').map((c) => c.textContent)).toEqual(['Status: paid', 'Branch: Main'])
    expect(screen.getAllByRole('row')).toHaveLength(2) // header + Bekele

    await userEvent.click(screen.getByRole('button', { name: 'Remove filter: Status: paid' }))
    expect(filtersButton()).toHaveTextContent('Filters1')
    expect(screen.getAllByRole('row')).toHaveLength(3)
    // Focus goes to the next chip, not lost.
    await vi.waitFor(() => expect(screen.getByRole('button', { name: 'Remove filter: Branch: Main' })).toHaveFocus())
  })

  it('"Clear all" turns every filter off in one tap', async () => {
    screenWidth(375)
    render(<List />)
    await userEvent.click(filtersButton())
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Status' }), 'open')
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Branch' }), 'Main')
    await userEvent.click(screen.getByRole('button', { name: 'Clear all' }))
    expect(filtersButton()).toHaveTextContent(/^Filters$/)
    expect(screen.queryByRole('list', { name: 'Filters that are on' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Clear all' })).not.toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: 'Status' })).toHaveValue('')
    await vi.waitFor(() => expect(filtersButton()).toHaveFocus())
  })

  it('works with the keyboard: Enter opens and goes to the first control, Escape closes and goes back', async () => {
    screenWidth(768)
    render(<List />)
    filtersButton().focus()
    await userEvent.keyboard('{Enter}')
    expect(filtersButton()).toHaveAttribute('aria-expanded', 'true')
    await vi.waitFor(() => expect(screen.getByRole('combobox', { name: 'Status' })).toHaveFocus())
    await userEvent.keyboard('{Escape}')
    expect(filtersButton()).toHaveAttribute('aria-expanded', 'false')
    expect(filtersButton()).toHaveFocus()
  })
})

describe('room board filters on a phone (P19)', () => {
  const stay = { reservation_id: 9, code: 'BL-26-00001', guest: 'Abebe Kebede', check_in_at: '2026-11-12T14:00:00+03:00', check_out_at: '2026-11-14T12:00:00+03:00', balance: '3220.00', overdue: false }
  const board = {
    data: [
      { id: 1, number: '101', floor: '1', status: 'occupied', room_type_id: 3, room_type: 'Standard', notes: null, stay, arriving: null },
      { id: 2, number: '102', floor: '1', status: 'dirty', room_type_id: 3, room_type: 'Standard', notes: null, stay: null, arriving: null },
    ],
    counts: { occupied: 1, dirty: 1 },
    refreshed_at: '2026-11-12T15:00:00+03:00',
  }

  it('shows the rooms first: tabs and search stay, the status and sort controls fold away', async () => {
    screenWidth(375)
    mockApi({ 'GET /me': { body: makeMe({}, ['frontdesk.view', 'rooms.change_status']) }, 'GET /front-desk/board': { body: board }, 'GET /front-desk/today': { body: { arrivals: [], departures: [], in_house: [] } } })
    renderApp('/rooms')

    expect(await screen.findByRole('button', { name: 'Room 102, Dirty' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Room board' })).toBeInTheDocument() // main navigation, not a filter
    expect(screen.getByRole('searchbox', { name: 'Room or guest' })).toBeInTheDocument()
    expect(filtersButton()).toHaveAttribute('aria-expanded', 'false')
    expect(panel()).toHaveAttribute('inert')
    expect(within(panel()).getByRole('combobox', { name: 'Sort' })).toBeInTheDocument()

    await userEvent.click(filtersButton())
    await userEvent.click(within(panel()).getByRole('button', { name: 'Dirty 1' }))
    expect(filtersButton()).toHaveTextContent('Filters1')
    expect(within(screen.getByRole('list', { name: 'Filters that are on' })).getByText('Dirty')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Room 101/ })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Clear all' }))
    expect(await screen.findByRole('button', { name: /Room 101/ })).toBeInTheDocument()
    expect(filtersButton()).toHaveTextContent(/^Filters$/)
  })
})
