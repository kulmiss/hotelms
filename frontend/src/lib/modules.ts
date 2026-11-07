import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, del, post, put } from './api'
import type { Page } from './front'

function useInvalidate(keys: string[]) {
  const qc = useQueryClient()
  return () => keys.forEach((k) => void qc.invalidateQueries({ queryKey: [k] }))
}

/* ------------------------------------------------------------- cashier */

export type Balance = { currency: string; opening_amount: string; expected_amount: string | null; counted_amount: string | null; difference: string | null }
export type CashierSession = {
  id: number
  status: 'open' | 'closed' | 'approved'
  user: { id: number; name: string } | null
  branch: { id: number; name: string } | null
  opened_at: string
  closed_at: string | null
  approved_by: string | null
  approved_at: string | null
  notes: string | null
  balances: Balance[]
  expected?: Record<string, { opening: string; cash_in: string; cash_out: string; expected: string }>
  payments?: { id: number; type: string; amount: string; currency: string; method: string | null; reservation: { id: number; code: string } | null; paid_at: string }[]
}

export const useCurrentShift = () => useQuery({ queryKey: ['cashier', 'current'], queryFn: () => api<{ data: CashierSession | null }>('/cashier/current'), refetchInterval: 30_000 })
export const useShifts = (f: { status?: string; short?: boolean; page?: number; from?: string; to?: string }) =>
  useQuery({ queryKey: ['cashier', 'list', f], queryFn: () => api<Page<CashierSession>>(`/cashier/sessions?${new URLSearchParams(Object.entries(f).filter(([, v]) => v).map(([k, v]) => [k, String(v)]))}`), placeholderData: keepPreviousData })

export function useCashierAction<V, R>(fn: (v: V) => Promise<R>) {
  const invalidate = useInvalidate(['cashier'])
  return useMutation({ mutationFn: fn, onSuccess: invalidate })
}
export const useOpenShift = () => useCashierAction((opening: Record<string, string>) => post<{ data: CashierSession }>('/cashier/open', { opening }))
export const useCloseShift = () => useCashierAction((v: { counted: Record<string, string>; notes: string }) => post<{ data: CashierSession }>('/cashier/close', v))
export const useApproveShift = () => useCashierAction((id: number) => post<{ data: CashierSession }>(`/cashier/sessions/${id}/approve`))

/* ------------------------------------------------------------- housekeeping */

export type Task = {
  id: number
  type: 'cleaning' | 'inspection' | 'maintenance'
  priority: 'low' | 'normal' | 'high'
  status: 'pending' | 'in_progress' | 'done'
  notes: string | null
  room: { id: number; number: string; floor: string | null; status: string } | null
  assigned_to: { id: number; name: string } | null
  due_at: string | null
  started_at: string | null
  completed_at: string | null
}
export type HkRoom = { id: number; number: string; floor: string | null; status: string; room_type: string | null; tasks: Task[] }

export const useHkBoard = (branchId: number | null) =>
  useQuery({ queryKey: ['housekeeping', 'board', branchId], queryFn: () => api<{ data: HkRoom[] }>(`/housekeeping/board${branchId ? `?branch_id=${branchId}` : ''}`), refetchInterval: 30_000 })
export const useTasks = (mine: boolean, branchId: number | null = null) =>
  useQuery({
    queryKey: ['housekeeping', 'tasks', mine, branchId],
    queryFn: () => api<{ data: Task[] }>(`/housekeeping/tasks?${new URLSearchParams({ ...(mine ? { mine: '1' } : {}), ...(branchId ? { branch_id: String(branchId) } : {}) })}`),
    refetchInterval: 30_000,
  })
export const useHkStaff = (enabled: boolean) => useQuery({ queryKey: ['housekeeping', 'staff'], queryFn: () => api<{ data: { id: number; name: string }[] }>('/housekeeping/staff'), enabled })

export function useHkAction<V, R>(fn: (v: V) => Promise<R>) {
  const invalidate = useInvalidate(['housekeeping', 'desk'])
  return useMutation({ mutationFn: fn, onSuccess: invalidate })
}
export const useStartTask = () => useHkAction((id: number) => post<{ data: Task }>(`/housekeeping/tasks/${id}/start`))
export const useDoneTask = () => useHkAction((v: { id: number; notes?: string }) => post<{ data: Task }>(`/housekeeping/tasks/${v.id}/done`, { notes: v.notes }))
export const useSaveTask = () =>
  useHkAction((v: { id?: number; room_id: number; type: string; priority: string; assigned_to: number | null; due_at?: string | null; notes?: string | null }) =>
    v.id ? put<{ data: Task }>(`/housekeeping/tasks/${v.id}`, v) : post<{ data: Task }>('/housekeeping/tasks', v),
  )

/* ------------------------------------------------------------- shifts */

export type ShiftTemplate = { id: number; branch_id: number; name: string; start_time: string; end_time: string }
export type Roster = {
  from: string
  to: string
  shifts: ShiftTemplate[]
  staff: { id: number; name: string }[]
  assignments: { id: number; user_id: number; shift_id: number; date: string; shift: { name: string; start_time: string; end_time: string } | null }[]
}

export const useMyShifts = () => useQuery({ queryKey: ['shifts', 'mine'], queryFn: () => api<{ data: { id: number; date: string; shift: { name: string; start_time: string; end_time: string } | null; branch: string | null }[] }>('/shifts/mine') })
export const useRoster = (from: string, enabled: boolean) => useQuery({ queryKey: ['shifts', 'roster', from], queryFn: () => api<Roster>(`/shifts/roster?from=${from}`), enabled, placeholderData: keepPreviousData })

export function useShiftAction<V, R>(fn: (v: V) => Promise<R>) {
  const invalidate = useInvalidate(['shifts'])
  return useMutation({ mutationFn: fn, onSuccess: invalidate })
}
export const useAssignShift = () => useShiftAction((v: { user_id: number; shift_id: number; date: string }) => post('/shifts/roster', v))
export const useUnassignShift = () => useShiftAction((id: number) => del(`/shifts/roster/${id}`))

/* ------------------------------------------------------------- attendance */

export type AttendanceRow = { id: number; user: { id: number; name: string } | null; date: string; method: string; status: string; notes: string | null; clock_in_at: string | null; clock_out_at: string | null }

export const useAttendanceStatus = () => useQuery({ queryKey: ['attendance', 'status'], queryFn: () => api<{ clocked_in_at: string | null; methods: string[]; has_pin: boolean }>('/attendance/status') })
export const useAttendanceReport = (from: string, to: string, enabled: boolean) =>
  useQuery({ queryKey: ['attendance', 'report', from, to], queryFn: () => api<{ rows: AttendanceRow[]; summary: { user: { id: number; name: string } | null; present: number; late: number; absent: number; leave: number; hours: number }[] }>(`/attendance/report?from=${from}&to=${to}`), enabled })

export function useAttendanceAction<V, R>(fn: (v: V) => Promise<R>) {
  const invalidate = useInvalidate(['attendance'])
  return useMutation({ mutationFn: fn, onSuccess: invalidate })
}
export const useClock = () => useAttendanceAction(() => post<{ data: AttendanceRow }>('/attendance/clock'))
export const useKiosk = () => useAttendanceAction((pin: string) => post<{ data: AttendanceRow; name: string; action: 'in' | 'out' }>('/attendance/kiosk', { pin }))
export const useSetPin = () => useAttendanceAction((v: { userId: number; pin: string }) => post(`/attendance/pin/${v.userId}`, { pin: v.pin }))
export const useManualAttendance = () =>
  useAttendanceAction((v: { user_id: number; date: string; clock_in?: string; clock_out?: string; status?: string; notes?: string }) => post<{ data: AttendanceRow }>('/attendance/manual', v))
