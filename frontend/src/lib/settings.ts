import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, del, post, put, upload } from './api'
import { ME_KEY } from './auth'

/** Generic list + save + delete for a settings list (e.g. /settings/taxes). */
export function useSettingsList<T>(endpoint: string) {
  return useQuery({ queryKey: ['settings', endpoint], queryFn: () => api<{ data: T[] } & Record<string, unknown>>(endpoint) })
}

export function useSettingsSave<T extends { id?: number }>(endpoint: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (record: T) => (record.id ? put(`${endpoint}/${record.id}`, record) : post(endpoint, record)),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['settings'] })
      void qc.invalidateQueries({ queryKey: ME_KEY })
    },
  })
}

export function useSettingsDelete(endpoint: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => del(`${endpoint}/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['settings'] }),
  })
}

/** A single settings object (e.g. /settings/booking-rules). */
export function useSettingsObject<T>(endpoint: string) {
  return useQuery({ queryKey: ['settings', endpoint], queryFn: () => api<{ data: T }>(endpoint) })
}

export function useSettingsObjectSave<T>(endpoint: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: Partial<T>) => put<{ data: T }>(endpoint, data),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['settings', endpoint] })
      void qc.invalidateQueries({ queryKey: ME_KEY })
    },
  })
}

export function useHotelLogo() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (file: File | null) => upload('/settings/hotel/logo', 'logo', file),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['settings', '/settings/hotel'] }),
  })
}
