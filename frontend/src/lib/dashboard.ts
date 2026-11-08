import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router'
import { api } from './api'
import { addDays, today } from './front'
import type { ApprovalItem } from './automation'
import type { Dashboard, Kpis } from './reports'

/** Dashboard period (P18): one choice at the top updates every widget. */
export const DASH_RANGES = ['today', '7', '30', '90', 'custom'] as const
export type DashRangeKey = (typeof DASH_RANGES)[number]
export type DashFilters = { range: DashRangeKey; from: string; to: string; branchId: number | null }

/** From and to (both included) for a quick choice, counted back from today. */
export function rangeDates(key: DashRangeKey, now: string = today()): { from: string; to: string } {
  if (key === 'today') return { from: now, to: now }
  const days = key === 'custom' ? 30 : Number(key)
  return { from: addDays(now, -(days - 1)), to: now }
}

/** The filters live in the address (?range=30&branch=2), so a refresh or a shared link keeps them. */
export function useDashFilters(): [DashFilters, (next: Partial<DashFilters>) => void] {
  const [params, setParams] = useSearchParams()
  const raw = params.get('range') as DashRangeKey | null
  const range: DashRangeKey = raw && (DASH_RANGES as readonly string[]).includes(raw) ? raw : '30'
  const quick = rangeDates(range)
  const from = range === 'custom' ? (params.get('from') ?? quick.from) : quick.from
  const to = range === 'custom' ? (params.get('to') ?? quick.to) : quick.to
  const branch = Number(params.get('branch'))
  const filters: DashFilters = { range, from, to, branchId: Number.isInteger(branch) && branch > 0 ? branch : null }
  const set = (next: Partial<DashFilters>) => {
    const merged = { ...filters, ...next }
    setParams(
      (p) => {
        const out = new URLSearchParams(p)
        out.set('range', merged.range)
        if (merged.range === 'custom') {
          out.set('from', merged.from)
          out.set('to', merged.to)
        } else {
          out.delete('from')
          out.delete('to')
        }
        if (merged.branchId) out.set('branch', String(merged.branchId))
        else out.delete('branch')
        return out
      },
      { replace: true },
    )
  }
  return [filters, set]
}

const qs = (f: Record<string, string | number | null | undefined>) =>
  new URLSearchParams(Object.entries(f).filter(([, v]) => v !== undefined && v !== null && v !== '').map(([k, v]) => [k, String(v)])).toString()

/** Every dashboard question starts with 'dash', so one refresh button reloads them all. */
export function useRefreshDashboard() {
  const qc = useQueryClient()
  return () => qc.invalidateQueries({ queryKey: ['dash'] })
}

/** Today at a glance (counts, rooms, pace; money only for roles with dashboard.view_revenue). Shared by several widgets. */
export const useDashToday = (branchId: number | null, enabled = true) =>
  useQuery({
    queryKey: ['dash', 'today-summary', branchId],
    queryFn: () => api<{ data: Dashboard }>(`/dashboard${branchId ? `?branch_id=${branchId}` : ''}`),
    refetchInterval: 60_000,
    placeholderData: keepPreviousData,
    enabled,
  })

export type TrendRow = {
  date: string
  arrivals: number
  departures: number
  in_house: number
  occupancy: number
  /** Money: only sent to roles with dashboard.view_revenue. */
  revenue?: string
  room_revenue?: string
  adr?: string
  revpar?: string
  payments?: string
}

export const useTrend = (f: DashFilters, enabled = true) =>
  useQuery({
    queryKey: ['dash', 'trend', f.from, f.to, f.branchId],
    queryFn: () => api<{ data: TrendRow[]; rooms: number; currency: string }>(`/dashboard/trend?${qs({ from: f.from, to: f.to, branch_id: f.branchId })}`),
    placeholderData: keepPreviousData,
    enabled,
  })

/** The period just before, same length (for the trend arrows). */
export function previousRange(from: string, to: string): { from: string; to: string } {
  const days = Math.round((Date.parse(`${to}T12:00:00Z`) - Date.parse(`${from}T12:00:00Z`)) / 86_400_000) + 1
  return { from: addDays(from, -days), to: addDays(from, -1) }
}

export const usePerformance = (f: DashFilters, enabled = true) =>
  useQuery({
    queryKey: ['dash', 'performance', f.from, f.to, f.branchId],
    queryFn: () =>
      api<{ data: { kpis: Kpis; compare: Kpis | null; room_types: { room_type_id: number; name: string; room_nights: number; occupancy: number; room_revenue: string }[]; sources: { source: string; bookings: number; value: string }[]; currency: string } }>(
        `/reports/performance?${qs({ from: f.from, to: f.to, compare: 'prev', branch_id: f.branchId })}`,
      ),
    placeholderData: keepPreviousData,
    enabled,
  })

export type MethodRow = { method: string; method_type: string; currency: string; count: number; amount: string; amount_in_base: string }
export const usePaymentsSplit = (f: DashFilters, enabled = true) =>
  useQuery({
    queryKey: ['dash', 'payments', f.from, f.to, f.branchId],
    queryFn: () => api<{ data: { by_method: MethodRow[]; total: string; currency: string } }>(`/reports/payments?${qs({ from: f.from, to: f.to, branch_id: f.branchId })}`),
    placeholderData: keepPreviousData,
    enabled,
  })

export type OutstandingRow = { id: number; number: string; status: string; guest: string; phone: string | null; reservation: string | null; reservation_id: number | null; stay_status: string | null; total: string; balance: string; currency: string; issued_at: string; age_days: number }
export const useOutstanding = (f: DashFilters, enabled = true) =>
  useQuery({
    queryKey: ['dash', 'outstanding', f.branchId],
    queryFn: () => api<{ data: { rows: OutstandingRow[]; currency: string } }>(`/reports/outstanding?${qs({ branch_id: f.branchId })}`),
    placeholderData: keepPreviousData,
    enabled,
  })

export const usePendingApprovals = (enabled = true) =>
  useQuery({ queryKey: ['dash', 'approvals'], queryFn: () => api<{ data: ApprovalItem[] }>('/approvals?status=pending'), enabled })
