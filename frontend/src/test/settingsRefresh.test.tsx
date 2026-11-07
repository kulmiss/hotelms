import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { useSettingsObjectSave } from '@/lib/settings'
import { mockApi } from './mockApi'

afterEach(() => vi.unstubAllGlobals())

describe('saving settings', () => {
  it('refreshes the booking rules the other screens use (e.g. the ID photo box shows at once)', async () => {
    mockApi({ 'PUT /settings/booking-rules': { body: { data: { id_photo_enabled: true } } } })
    const qc = new QueryClient()
    qc.setQueryData(['reservations', 'lookups'], { id_photo: { enabled: false } })
    const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={qc}>{children}</QueryClientProvider>
    const { result } = renderHook(() => useSettingsObjectSave<Record<string, boolean>>('/settings/booking-rules'), { wrapper })

    await act(() => result.current.mutateAsync({ id_photo_enabled: true }))
    await waitFor(() => expect(qc.getQueryState(['reservations', 'lookups'])?.isInvalidated).toBe(true))
  })
})
