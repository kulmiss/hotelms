import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, del, post, put } from './api'
import { SILENT } from './toast'

export type SavedView = { id: number; report_key: string; name: string; filters: Record<string, string | number | null>; is_pinned: boolean; shared_with_roles: number[] | null; mine: boolean }
export type CustomReport = { id: number; name: string; measures: string[]; group_by: string; chart_type: 'table' | 'bar'; shared_with_roles: number[] | null; mine: boolean }
export type CustomResult = { columns: string[]; rows: ({ key: string; label: string } & Record<string, string | number | null>)[]; currency: string }
export type Schedule = {
  id: number
  name: string
  report_key: string
  custom_report_id: number | null
  branch_id: number | null
  frequency: 'daily' | 'weekly' | 'monthly'
  day: number | null
  time: string
  channels: string[]
  recipients: number[]
  extra_emails: string[] | null
  is_active: boolean
  next_run_local: string | null
  last_sent_local: string | null
  last_error: string | null
}
export type AlertRule = { id: number; type: string; threshold: Record<string, number>; channels: string[]; recipients: number[]; is_active: boolean; last_checked_at: string | null }
export type AlertEvent = { id: number; title: string; link: string | null; fired_at: string; acknowledged_at: string | null }
export type Person = { id: number; name: string; email: string }

function useInv<V, R>(keys: string[], fn: (v: V) => Promise<R>) {
  const qc = useQueryClient()
  return useMutation({ mutationFn: fn, onSuccess: () => keys.forEach((k) => void qc.invalidateQueries({ queryKey: [k] })) })
}

export const useSavedViews = (reportKey: string) => useQuery({ queryKey: ['views', reportKey], queryFn: () => api<{ data: SavedView[] }>(`/saved-views?report_key=${reportKey}`) })
export const useSaveView = () => useInv(['views'], (v: Partial<SavedView> & { report_key: string; name: string; filters: object }) => (v.id ? put(`/saved-views/${v.id}`, v) : post('/saved-views', v)))
export const useDeleteView = () => useInv(['views'], (id: number) => del(`/saved-views/${id}`))

export const useCustomReports = (enabled: boolean) =>
  useQuery({ queryKey: ['custom'], queryFn: () => api<{ data: CustomReport[]; measures: string[]; groups: string[] }>('/custom-reports'), enabled })
export const useSaveCustomReport = () => useInv(['custom'], (v: Partial<CustomReport> & { name: string; measures: string[]; group_by: string; chart_type: string }) => (v.id ? put(`/custom-reports/${v.id}`, v) : post('/custom-reports', v)))
export const useDeleteCustomReport = () => useInv(['custom'], (id: number) => del(`/custom-reports/${id}`))
export const useRunCustom = () => useMutation({ meta: SILENT, mutationFn: (v: { measures: string[]; group_by: string; from: string; to: string; branch_id?: number | null }) => post<{ data: CustomResult }>('/custom-reports/run', v) })

export const useSchedules = () => useQuery({ queryKey: ['schedules'], queryFn: () => api<{ data: Schedule[]; reports: string[]; people: Person[] }>('/report-schedules') })
export const useSaveSchedule = () => useInv(['schedules'], (v: Partial<Schedule>) => (v.id ? put(`/report-schedules/${v.id}`, v) : post('/report-schedules', v)))
export const useDeleteSchedule = () => useInv(['schedules'], (id: number) => del(`/report-schedules/${id}`))
export const useTestSchedule = () => useInv(['schedules'], (id: number) => post<{ problems: string[]; sent: boolean }>(`/report-schedules/${id}/test`))

export const useAlertRules = (enabled: boolean) => useQuery({ queryKey: ['alerts', 'rules'], queryFn: () => api<{ data: AlertRule[]; people: Person[] }>('/alert-rules'), enabled })
export const useSaveAlertRule = () => useInv(['alerts'], (v: AlertRule) => put(`/alert-rules/${v.id}`, v))
export const useAlertEvents = (enabled: boolean) =>
  useQuery({ queryKey: ['alerts', 'events'], queryFn: () => api<{ data: AlertEvent[]; unread: number }>('/alert-events'), enabled, refetchInterval: 60_000, retry: false })
export const useAckAlert = () => useInv(['alerts'], (id: number) => post(`/alert-events/${id}/ack`))

export const useTelegram = () => useQuery({ queryKey: ['telegram'], queryFn: () => api<{ enabled: boolean; bot: string | null; linked: boolean; chat_name: string | null }>('/telegram') })
export const useTelegramLink = () => useInv(['telegram'], () => post<{ data: { code: string; url: string | null; bot: string | null } }>('/telegram/link'))
export const useTelegramUnlink = () => useInv(['telegram'], () => del('/telegram/link'))
