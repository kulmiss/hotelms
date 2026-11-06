import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, del, post, put } from './api'
import { REMOVED, SILENT } from './toast'

/* ---------- Support inbox (both sides) ---------- */

export type SupportStatus = 'open' | 'waiting' | 'solved'
export type SupportSummary = {
  id: number
  subject: string
  status: SupportStatus
  priority: 'normal' | 'urgent'
  channel: 'app' | 'telegram' | 'phone'
  from: string | null
  hotel?: { id: number; name: string } | null
  assigned_to?: { id: number; name: string } | null
  last_message_at: string | null
  created_at: string | null
}
export type SupportMessage = { id: number; kind: 'message' | 'reply' | 'note' | 'system'; body: string; author: string | null; from_hotelms?: boolean; created_at: string | null }
export type SupportDetail = SupportSummary & {
  messages: SupportMessage[]
  contact?: { name: string; email: string; phone: string | null } | null
  hotel_context?: { status: string; plan: string | null; city: string | null; phone: string | null; email: string | null } | null
  team?: { id: number; name: string }[]
}

function useInvalidate<V, R>(fn: (v: V) => Promise<R>, keys: unknown[][], meta?: Record<string, unknown>) {
  const qc = useQueryClient()
  return useMutation({ meta, mutationFn: fn, onSuccess: () => keys.forEach((queryKey) => void qc.invalidateQueries({ queryKey })) })
}

// Hotel side ("Help")
export const useMySupport = () => useQuery({ queryKey: ['support'], queryFn: () => api<{ data: SupportSummary[] }>('/support') })
export const useMySupportRequest = (id: number | null) =>
  useQuery({ queryKey: ['support', id], queryFn: () => api<{ data: SupportDetail }>(`/support/${id}`), enabled: Boolean(id), refetchInterval: 60_000 })
export const useAskSupport = () => useInvalidate((v: { subject: string; body: string; priority: 'normal' | 'urgent' }) => post<{ data: SupportDetail }>('/support', v), [['support']])
export const useAnswerSupport = (id: number) => useInvalidate((body: string) => post(`/support/${id}/messages`, { body }), [['support']])

// Super Admin side
export type InboxFilters = { status?: SupportStatus; mine?: 1; search?: string; page?: number }
const qs = (o: Record<string, string | number | undefined>) => {
  const p = new URLSearchParams()
  Object.entries(o).forEach(([k, v]) => v !== undefined && v !== '' && p.set(k, String(v)))
  const s = p.toString()
  return s ? `?${s}` : ''
}
export const useInbox = (f: InboxFilters) =>
  useQuery({
    queryKey: ['admin', 'support', f],
    queryFn: () => api<{ data: SupportSummary[]; counts: Record<SupportStatus, number>; meta: { total: number; last_page: number; current_page: number } }>('/admin/support' + qs(f)),
    placeholderData: keepPreviousData,
    refetchInterval: 60_000,
  })
export const useInboxRequest = (id: number | null) =>
  useQuery({ queryKey: ['admin', 'support', 'one', id], queryFn: () => api<{ data: SupportDetail }>(`/admin/support/${id}`), enabled: Boolean(id) })
export const useInboxMessage = (id: number) =>
  useInvalidate((v: { body: string; kind: 'reply' | 'note'; solve?: boolean }) => post(`/admin/support/${id}/messages`, v), [['admin', 'support']])
export const useInboxUpdate = (id: number) =>
  useInvalidate((v: { status?: SupportStatus; priority?: 'normal' | 'urgent'; assigned_to?: number | null }) => put(`/admin/support/${id}`, v), [['admin', 'support']])
export const useLogCall = () =>
  useInvalidate((v: { hotel_id: number; channel: 'phone' | 'telegram'; subject: string; body: string; priority?: 'normal' | 'urgent' }) => post<{ data: SupportDetail }>('/admin/support', v), [['admin', 'support']])

/* ---------- Hotel page tabs ---------- */

export type HotelOverview = {
  usage: Record<'rooms' | 'branches' | 'staff', { used: number; limit: number | null }>
  staff: { id: number; name: string; email: string; status: string; role: string | null; last_login_at: string | null; two_factor: boolean }[]
  devices: { id: number; name: string; user: string; status: string; ip: string | null; last_seen_at: string | null }[]
  notes: { id: number; body: string; channel: string | null; author: string | null; created_at: string | null }[]
  access: { id: number; what: 'live_data' | 'log_in_as'; reason: string; by: string | null; at: string | null }[]
}
export type HotelLive = {
  today: { rooms: number; occupied: number; occupancy: number; arrivals: number; departures: number; bookings_made: number; payments: Record<string, string>; staff_online: number }
  activity: { id: number; event: string; description: string; user: string | null; at: string | null }[]
}
export const useHotelOverview = (id: number) => useQuery({ queryKey: ['admin', 'hotel', id, 'overview'], queryFn: () => api<{ data: HotelOverview }>(`/admin/hotels/${id}/overview`) })
export const useHotelLive = (id: number) => {
  const qc = useQueryClient()
  return useMutation({ meta: SILENT, mutationFn: (reason: string) => post<{ data: HotelLive }>(`/admin/hotels/${id}/live`, { reason }), onSuccess: () => void qc.invalidateQueries({ queryKey: ['admin', 'hotel', id, 'overview'] }) })
}
export const useAddNote = (id: number) => useInvalidate((v: { body: string; channel?: string | null }) => post(`/admin/hotels/${id}/notes`, v), [['admin', 'hotel', id, 'overview']])
export const useRemoveNote = (id: number) => useInvalidate((noteId: number) => del(`/admin/hotels/${id}/notes/${noteId}`), [['admin', 'hotel', id, 'overview']], REMOVED)

/* ---------- System health, activity, team ---------- */

type Check = { ok: boolean; problem?: string | null; [k: string]: unknown }
export type SystemHealth = {
  checks: {
    database: Check & { ms?: number }
    cache: Check & { driver: string }
    scheduler: Check & { last_run: string | null }
    jobs: Check & { waiting: number; failed: number; recent_failures: { id: number; queue: string; at: string; error: string }[] }
    backups: Check & { daily: { file: string; at: string; size_kb: number; count: number } | null; small: { file: string; at: string; size_kb: number; count: number } | null }
    disk: Check & { free_mb: number | null }
  }
  errors: { at: string; level: string; message: string }[]
  versions: { app: string; php: string; laravel: string; database: string | null }
  checked_at: string
}
export const useSystemHealth = () => useQuery({ queryKey: ['admin', 'system'], queryFn: () => api<{ data: SystemHealth }>('/admin/system'), refetchInterval: 60_000 })

export type PlatformActivity = { id: number; event: string; description: string; user: string | null; hotel: { id: number; name: string } | null; ip: string | null; at: string | null }
export const usePlatformActivity = (f: { search?: string; event?: string; page?: number }) =>
  useQuery({
    queryKey: ['admin', 'activity', f],
    queryFn: () => api<{ data: PlatformActivity[]; meta: { total: number; last_page: number; current_page: number } }>('/admin/activity' + qs(f)),
    placeholderData: keepPreviousData,
  })

export type TeamMember = { id: number; name: string; email: string; phone: string | null; role: string; status: string; two_factor: boolean; last_login_at: string | null; invited: boolean }
export const useTeam = () => useQuery({ queryKey: ['admin', 'team'], queryFn: () => api<{ data: TeamMember[]; roles: string[]; permissions: Record<string, string[]> }>('/admin/team') })
export const useAddMember = () => useInvalidate((v: { name: string; email: string; phone?: string; role: string }) => post<{ data: { id: number }; temporary_password: string }>('/admin/team', v), [['admin', 'team']])
export const useUpdateMember = () => useInvalidate((v: { id: number; role?: string; status?: string }) => put(`/admin/team/${v.id}`, { role: v.role, status: v.status }), [['admin', 'team']])
export const useSavePermissions = () => {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (permissions: Record<string, string[]>) => put('/admin/team/permissions', { permissions }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['admin', 'team'] })
      void qc.invalidateQueries({ queryKey: ['me'] })
    },
  })
}
