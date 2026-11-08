import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

// Owner (QA 8 Nov): one OPEN task per room and kind. The refusal says so and links to the open task.
const me = () => {
  const m = makeMe({}, ['housekeeping.view', 'housekeeping.assign'])
  return { ...m, hotel: { ...(m.hotel as NonNullable<typeof m.hotel>), modules: ['housekeeping'] } }
}
const room = { id: 2, number: '106', floor: '1', status: 'dirty', room_type: 'Standard', tasks: [] }
const open = { id: 31, type: 'cleaning', priority: 'normal', status: 'pending', notes: null, room: { id: 2, number: '106', floor: '1', status: 'dirty' }, assigned_to: null, due_at: null, started_at: null, completed_at: null }
const message = 'Room 106 already has an open cleaning task. Change that task instead of adding another one.'

describe('housekeeping: no second open task of the same kind', () => {
  it('shows the refusal with a link to the open task', async () => {
    mockApi({
      'GET /me': { body: me() },
      'GET /housekeeping/board': { body: { data: [room] } },
      'GET /housekeeping/tasks': { body: { data: [open] } },
      'GET /housekeeping/staff': { body: { data: [] } },
      'POST /housekeeping/tasks': { status: 422, body: { message, errors: { room_id: [message] }, existing_task_id: 31 } },
    })
    renderApp('/housekeeping?tab=assign')

    await screen.findByRole('option', { name: /^106/ }) // the rooms have loaded
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Room' }), '2')
    await userEvent.click(screen.getByRole('button', { name: 'Add task' }))

    expect(await screen.findByText(message, { exact: false })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Show the open task' })).toHaveAttribute('href', '#task-31')
    expect(document.getElementById('task-31')).not.toBeNull()
  })
})
