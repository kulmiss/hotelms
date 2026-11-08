import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api, apiUrl } from './api'

export type Kpis = {
  rooms: number
  available_nights: number
  room_nights: number
  occupancy: number
  room_revenue: string
  adr: string
  revpar: string
  extras: string
  fees: string
  discounts: string
  cancellation_fees: string
  revenue: string
  tax: string
  payments: string
  bookings: number
  cancellation_rate: number
  lead_time: number
  average_stay: number
}

export type Dashboard = {
  date: string
  arrivals: number
  arrived: number
  departures: number
  departed: number
  in_house: number
  occupancy: number
  rooms: { total: number; by_status: Record<string, number> }
  pending_expiring: number
  late_arrivals: number
  next_7_days: { date: string; occupancy: number }[]
  revenue_today?: string
  payments_today?: string
  unpaid_balances?: string
  cashier_shortages?: number
  month?: Kpis
}

export type ReportFilters = { from: string; to: string; compare?: 'prev' | 'ly' | 'none'; branch_id?: number | null }

const qs = (f: Record<string, string | number | null | undefined>) =>
  new URLSearchParams(Object.entries(f).filter(([, v]) => v !== undefined && v !== null && v !== '').map(([k, v]) => [k, String(v)])).toString()

/** Only for people with dashboard.view (others would get 403 every minute – QA s1). */
export const useDashboard = (branchId: number | null, enabled = true) =>
  useQuery({ queryKey: ['dashboard', branchId], queryFn: () => api<{ data: Dashboard }>(`/dashboard${branchId ? `?branch_id=${branchId}` : ''}`), refetchInterval: 60_000, placeholderData: keepPreviousData, enabled })

export function useReport<T>(key: string, f: ReportFilters, enabled = true) {
  return useQuery({ queryKey: ['reports', key, f], queryFn: () => api<{ data: T & { from: string; to: string; currency: string; compare_range: [string, string] | null } }>(`/reports/${key}?${qs(f)}`), placeholderData: keepPreviousData, enabled })
}

export const reportFileUrl = (key: string, f: ReportFilters, format: 'csv' | 'xlsx') => apiUrl(`/reports/${key}?${qs({ ...f, format })}`)

export function useDrilldown(metric: 'room_nights' | 'payments', date: string | null, branchId?: number | null) {
  return useQuery({
    queryKey: ['reports', 'drilldown', metric, date, branchId],
    queryFn: () => api<{ data: Record<string, string | number | null>[] }>(`/reports/drilldown?${qs({ metric, date, branch_id: branchId })}`),
    enabled: !!date,
  })
}

/** Change vs the compared period, in percent (null when there is nothing to compare). */
export function change(now: number | string, before: number | string | undefined | null): number | null {
  const a = Number(now)
  const b = Number(before)
  if (before === undefined || before === null || !Number.isFinite(b) || b === 0) return null
  return Math.round(((a - b) / Math.abs(b)) * 1000) / 10
}
