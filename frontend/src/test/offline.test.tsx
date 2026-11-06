import { screen } from '@testing-library/react'
import { onlineManager, QueryClient } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError, post } from '@/lib/api'
import { startOfflineCopy, wipeOfflineCopy } from '@/lib/offline'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.useRealTimers()
  onlineManager.setOnline(true)
  localStorage.clear()
})

describe('offline mode', () => {
  it('refuses changes while offline, with a clear message', async () => {
    const fetchMock = mockApi({})
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false)
    await expect(post('/reservations', {})).rejects.toBeInstanceOf(ApiError)
    await expect(post('/reservations', {})).rejects.toMatchObject({ code: 'offline' })
    expect(fetchMock).not.toHaveBeenCalled()
    vi.restoreAllMocks()
  })

  it('keeps a read-only copy for the signed-in person and wipes it on sign-out', async () => {
    vi.useFakeTimers()
    const a = new QueryClient()
    startOfflineCopy(a)
    a.setQueryData(['me'], makeMe({ id: 7 }))
    a.setQueryData(['desk', 'today', 1], { arrivals: [{ code: 'BL-26-00001' }], departures: [], in_house: [] })
    a.setQueryData(['reservations', 'list', {}], { data: ['not kept'] })
    // setQueryData counts as fresh data; the copy is written a moment later.
    vi.advanceTimersByTime(1500)

    const saved = localStorage.getItem('hms_offline:7') ?? ''
    expect(saved).toContain('BL-26-00001')
    expect(saved).not.toContain('not kept')

    const b = new QueryClient()
    startOfflineCopy(b)
    expect(b.getQueryData(['desk', 'today', 1])).toMatchObject({ arrivals: [{ code: 'BL-26-00001' }] })

    wipeOfflineCopy()
    expect(localStorage.getItem('hms_offline:7')).toBeNull()
  })

  it('shows the offline strip', async () => {
    mockApi({ 'GET /me': { body: makeMe() } })
    renderApp('/profile')
    await screen.findByRole('heading', { name: 'Profile' })
    onlineManager.setOnline(false)
    expect(await screen.findByText("You're offline.")).toBeInTheDocument()
  })
})
