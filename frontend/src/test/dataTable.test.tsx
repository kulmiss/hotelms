import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { DataTable } from '@/components/ui/DataTable'
import { toCsv } from '@/lib/csv'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

type Row = { id: number; name: string; amount: number }
const rows: Row[] = [
  { id: 1, name: 'Bekele', amount: 300 },
  { id: 2, name: 'Almaz', amount: 1200 },
  { id: 3, name: 'Chala', amount: 50 },
]
const names = () => screen.getAllByRole('row').slice(1).map((r) => within(r).getAllByRole('cell')[0].textContent)

function Small({ canExport = true }: { canExport?: boolean }) {
  return (
    <DataTable<Row>
      id="test"
      rows={rows}
      rowKey={(r) => r.id}
      csvName="people"
      canExport={canExport}
      columns={[
        { key: 'name', label: 'Name', hideable: false, sortValue: (r) => r.name, render: (r) => r.name },
        { key: 'amount', label: 'Amount', align: 'right', sortValue: (r) => r.amount, render: (r) => `ETB ${r.amount}` },
      ]}
    />
  )
}

describe('data tables', () => {
  it('sorts by tapping a column, hides columns and remembers it', async () => {
    const { unmount } = render(<Small />)
    expect(names()).toEqual(['Bekele', 'Almaz', 'Chala'])
    await userEvent.click(screen.getByRole('button', { name: 'Name' }))
    expect(names()).toEqual(['Almaz', 'Bekele', 'Chala'])
    expect(screen.getByRole('columnheader', { name: 'Name' })).toHaveAttribute('aria-sort', 'ascending')
    await userEvent.click(screen.getByRole('button', { name: 'Amount' }))
    await userEvent.click(screen.getByRole('button', { name: 'Amount' })) // second tap: biggest first
    expect(names()).toEqual(['Almaz', 'Bekele', 'Chala'])
    expect(screen.getByRole('columnheader', { name: 'Amount' })).toHaveAttribute('aria-sort', 'descending')

    await userEvent.click(screen.getByRole('button', { name: 'Columns' }))
    await userEvent.click(screen.getByRole('menuitemcheckbox', { name: 'Amount' }))
    expect(screen.queryByRole('columnheader', { name: 'Amount' })).not.toBeInTheDocument()
    expect(screen.getByRole('menuitemcheckbox', { name: 'Amount' })).toHaveAttribute('aria-checked', 'false') // menu stays open while ticking
    unmount()
    render(<Small />)
    expect(screen.queryByRole('columnheader', { name: 'Amount' })).not.toBeInTheDocument() // remembered
  })

  it('closes the Columns menu on a click elsewhere', async () => {
    render(<Small />)
    await userEvent.click(screen.getByRole('button', { name: 'Columns' }))
    expect(screen.getByRole('menuitemcheckbox', { name: 'Amount' })).toBeVisible()
    await userEvent.click(document.body)
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })

  it('downloads the list for people allowed to', async () => {
    const created: Blob[] = []
    vi.stubGlobal('URL', { ...URL, createObjectURL: (b: Blob) => (created.push(b), 'blob:x'), revokeObjectURL: () => undefined })
    const { unmount } = render(<Small />)
    await userEvent.click(screen.getByRole('button', { name: 'Excel' }))
    const text = await new Promise<string>((done) => {
      const reader = new FileReader()
      reader.onload = () => done(String(reader.result))
      reader.readAsText(created[0])
    })
    expect(text).toContain('Name,Amount\r\nBekele,300')
    unmount()
    render(<Small canExport={false} />)
    expect(screen.queryByRole('button', { name: 'Excel' })).not.toBeInTheDocument()
  })

  it('never lets a cell run as a formula in Excel', () => {
    expect(toCsv(['A'], [['=1+1'], ['a,b'], ['say "hi"']])).toBe('﻿A\r\n\'=1+1\r\n"a,b"\r\n"say ""hi"""')
  })

  it('asks the server to sort, and offers Excel with the same filters', async () => {
    const page = { data: [], current_page: 1, last_page: 1, total: 0 }
    const fetchMock = mockApi({ 'GET /me': { body: makeMe({}, ['reservations.view', 'reports.export']) }, 'GET /reservations': { body: page } })
    renderApp('/reservations?view=list')

    await userEvent.click(await screen.findByRole('button', { name: 'Booking' }))
    await waitFor(() => expect(fetchMock.mock.calls.some(([u]) => String(u).includes('sort=code') && String(u).includes('dir=asc'))).toBe(true))
    const excel = screen.getByRole('link', { name: 'Excel' })
    expect(excel.getAttribute('href')).toContain('format=xlsx')
    expect(excel.getAttribute('href')).toContain('sort=code')
  })
})
