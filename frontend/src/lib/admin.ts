import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, post, put, upload } from './api'
import { BRANDING_KEY } from './brand'

export type Plan = {
  id: number
  name: string
  code: string | null
  description: string | null
  price_per_room: string
  minimum_monthly: string
  yearly_free_months: number
  currency: string
  is_custom: boolean
  max_rooms: number | null
  max_branches: number | null
  max_staff: number | null
  modules: string[]
  is_active: boolean
  hotels_count?: number
}

export type Hotel = {
  id: number
  name: string
  email: string | null
  phone: string | null
  address: string | null
  city: string | null
  default_currency: string
  status: 'trial' | 'active' | 'grace' | 'suspended'
  plan: { id: number; name: string } | null
  trial_plan: { id: number; name: string } | null
  billing_cycle: 'monthly' | 'yearly'
  custom_price: string | null
  trial_ends_at: string | null
  subscription_ends_at: string | null
  suspend_reason: string | null
  branches_count?: number
  users_count?: number
}

export type HotelDetail = {
  data: Hotel
  branches: { id: number; name: string; city: string | null; status: string }[]
  admins: { id: number; name: string; email: string; phone: string | null; last_login_at: string | null; must_change_password: boolean }[]
  payments: {
    id: number
    amount: string
    currency: string
    method: string
    reference: string | null
    months: number
    period_start: string
    period_end: string
    paid_at: string
    plan: { id: number; name: string } | null
    recorder: { id: number; name: string } | null
  }[]
}

export type PlatformSettings = {
  platform_name: string
  support_email: string | null
  support_phone: string | null
  default_trial_days: number
  default_trial_plan_id: number | null
  grace_days: number
  brand_color: string
  brand_logo_path: string | null
  brand_logo_url: string | null
  login_message: string | null
  email_from_name: string
  email_footer: string | null
}

export type AdminDashboard = {
  hotels: { total: number; by_status: Record<string, number> }
  income_this_month: Record<string, string>
  trials_ending_soon: { id: number; name: string; trial_ends_at: string }[]
  needs_attention: { id: number; name: string; status: string; subscription_ends_at: string | null; trial_ends_at: string | null }[]
}

const qs = (params: Record<string, string | number | undefined>) => {
  const p = new URLSearchParams()
  Object.entries(params).forEach(([k, v]) => v !== undefined && v !== '' && p.set(k, String(v)))
  const s = p.toString()
  return s ? `?${s}` : ''
}

export const useAdminDashboard = () => useQuery({ queryKey: ['admin', 'dashboard'], queryFn: () => api<AdminDashboard>('/admin/dashboard') })

export const useHotels = (filters: { search?: string; status?: string; page?: number }) =>
  useQuery({
    queryKey: ['admin', 'hotels', filters],
    queryFn: () =>
      api<{ data: Hotel[]; counts: Record<string, number>; meta: { total: number; last_page: number; current_page: number } }>(
        '/admin/hotels' + qs(filters),
      ),
    placeholderData: keepPreviousData,
  })

export const useHotel = (id: number) =>
  useQuery({ queryKey: ['admin', 'hotel', id], queryFn: () => api<HotelDetail>(`/admin/hotels/${id}`) })

export const usePlans = () => useQuery({ queryKey: ['admin', 'plans'], queryFn: () => api<{ data: Plan[] }>('/admin/plans') })

export const usePlatformSettings = () =>
  useQuery({ queryKey: ['admin', 'settings'], queryFn: () => api<{ data: PlatformSettings }>('/admin/settings') })

function useAdminMutation<TInput, TOutput = unknown>(fn: (input: TInput) => Promise<TOutput>) {
  const qc = useQueryClient()
  return useMutation({ mutationFn: fn, onSuccess: () => qc.invalidateQueries({ queryKey: ['admin'] }) })
}

export type NewHotelInput = {
  name: string
  email?: string
  phone?: string
  city?: string
  address?: string
  default_currency: string
  plan_id: number
  billing_cycle: 'monthly' | 'yearly'
  trial_days?: number
  admin: { name: string; email: string; phone?: string }
}

export type NewHotelResult = {
  data: Hotel
  admin: { name: string; email: string; temporary_password: string; email_sent: boolean }
}

export const useCreateHotel = () => useAdminMutation((input: NewHotelInput) => post<NewHotelResult>('/admin/hotels', input))
export const useSuspendHotel = (id: number) =>
  useAdminMutation((reason: string) => post(`/admin/hotels/${id}/suspend`, { reason }))
export const useActivateHotel = (id: number) => useAdminMutation(() => post(`/admin/hotels/${id}/activate`))
export const useSetTrial = (id: number) =>
  useAdminMutation((input: { trial_ends_at: string; reason: string }) => post(`/admin/hotels/${id}/trial`, input))

export type PaymentInput = {
  plan_id: number
  amount: string
  currency: string
  method: string
  reference?: string
  billing_cycle: 'monthly' | 'yearly'
  periods: number
}
export const useRecordPayment = (id: number) =>
  useAdminMutation((input: PaymentInput) => post(`/admin/hotels/${id}/payments`, input))
export const getQuote = (id: number, plan_id: number, billing_cycle: string, periods: number) =>
  api<{ amount: string; currency: string; months: number }>(
    `/admin/hotels/${id}/payments/quote` + qs({ plan_id, billing_cycle, periods }),
  )

export const useSavePlan = () =>
  useAdminMutation((plan: Partial<Plan> & { id?: number }) =>
    plan.id ? put(`/admin/plans/${plan.id}`, plan) : post('/admin/plans', plan),
  )
export function useSaveSettings() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (s: Partial<PlatformSettings>) => put<{ data: PlatformSettings }>('/admin/settings', s),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['admin'] })
      void qc.invalidateQueries({ queryKey: BRANDING_KEY })
    },
  })
}

export function useUploadLogo() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (file: File | null) => upload<{ data: PlatformSettings }>('/admin/settings/logo', 'logo', file),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['admin', 'settings'] })
      void qc.invalidateQueries({ queryKey: BRANDING_KEY })
    },
  })
}

export const useResetUserPassword = (hotelId: number) =>
  useAdminMutation((input: { userId: number; send_email: boolean }) =>
    post<{ temporary_password: string; email_sent: boolean }>(
      `/admin/hotels/${hotelId}/users/${input.userId}/reset-password`,
      { send_email: input.send_email },
    ),
  )
