import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, del, post } from './api'
import { REMOVED } from './toast'

export type SwitchDef = { key: string; type: 'bool' | 'int'; group: 'modules' | 'features' | 'limits' | 'emergency' | 'automations' | 'smart'; default: boolean | number | null }
export type SwitchRow = { id: number; key: string; level: 'everyone' | 'plan' | 'hotel'; target_id: number | null; target_name: string | null; value: boolean | number | null; starts_at: string | null; ends_at: string | null; reason: string | null; scheduled: boolean; expired: boolean }
export type BannerRow = { id: number; message: string; tone: 'info' | 'warn' | 'danger'; hotel_ids: number[] | null; plan_ids: number[] | null; starts_at: string | null; ends_at: string | null }
export type SwitchInput = { key: string; level: SwitchRow['level']; target_id?: number | null; value: boolean | number | null; starts_at?: string | null; ends_at?: string | null; reason?: string }

const KEY = ['admin', 'switches']

function useInv<V, R>(fn: (v: V) => Promise<R>, meta?: Record<string, unknown>) {
  const qc = useQueryClient()
  return useMutation({
    meta,
    mutationFn: fn,
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: KEY })
      void qc.invalidateQueries({ queryKey: ['admin', 'hotel-switches'] })
    },
  })
}

export const useSwitches = () => useQuery({ queryKey: KEY, queryFn: () => api<{ registry: SwitchDef[]; plans: { id: number; name: string }[]; switches: SwitchRow[]; banners: BannerRow[] }>('/admin/switches') })
export const useSetSwitch = () => useInv((v: SwitchInput) => post('/admin/switches', v))
export const useRemoveSwitch = () => useInv((id: number) => del(`/admin/switches/${id}`), REMOVED)
export const useAddBanner = () => useInv((v: { message: string; tone: BannerRow['tone']; hotel_ids?: number[] | null; plan_ids?: number[] | null; starts_at?: string | null; ends_at?: string | null }) => post('/admin/banners', v))
export const useRemoveBanner = () => useInv((id: number) => del(`/admin/banners/${id}`), REMOVED)
export const useHotelSwitches = (hotelId: number) =>
  useQuery({ queryKey: ['admin', 'hotel-switches', hotelId], queryFn: () => api<{ data: { key: string; value: boolean | number | null; source: 'hotel' | 'plan' | 'everyone' | 'default' }[] }>(`/admin/hotels/${hotelId}/switches`), enabled: hotelId > 0 })
