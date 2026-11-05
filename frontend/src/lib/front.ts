import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, post, put } from './api'

/* ------------------------------------------------------------- types */

export type GuestType = { id: number; name: string }

export type Guest = {
  id: number
  first_name: string
  last_name: string | null
  full_name: string
  phone: string | null
  email: string | null
  nationality: string | null
  id_type: string | null
  id_number: string | null
  gender: string | null
  date_of_birth: string | null
  notes: string | null
  guest_type_id: number | null
  guest_type?: GuestType | null
  is_blacklisted: boolean
  blacklist_reason: string | null
  reservations_count?: number
}

export type GuestInput = Partial<Omit<Guest, 'id' | 'full_name' | 'guest_type' | 'is_blacklisted' | 'blacklist_reason'>> & { first_name: string }

export type Page<T> = { data: T[]; current_page: number; last_page: number; total: number; per_page: number }

export type StayType = 'nightly' | 'day_use' | 'hourly'
export type ReservationStatus = 'pending' | 'confirmed' | 'checked_in' | 'checked_out' | 'cancelled' | 'no_show'

export type ReservationSummary = {
  id: number
  code: string
  status: ReservationStatus
  stay_type: StayType
  source: string
  branch: { id: number; name: string } | null
  guest: { id: number; name: string; phone: string | null } | null
  check_in_at: string
  check_out_at: string
  expires_at: string | null
  rooms: { room_type: string | null; room_number: string | null }[]
  adults: number
  children: number
  currency: string
  total: string
  paid_total: string
  deposit_required: string
}

export type PriceLine = { date: string; label: string; amount: string; rule_name: string | null }

export type ReservationDetail = Omit<ReservationSummary, 'guest' | 'rooms'> & {
  guest: Guest
  notes: string | null
  subtotal: string
  discount_percent: string
  discount_amount: string
  tax_total: string
  balance: string
  cancellation_fee: string | null
  cancellation_policy_id: number | null
  policy: { name: string; free_until_hours: number | null; penalty_type: string; penalty_value: string; applies_to_no_show: boolean } | null
  cancelled_at: string | null
  cancel_reason: string | null
  created_by: string | null
  created_at: string
  rooms: {
    id: number
    room_type_id: number
    room_type: string | null
    room_id: number | null
    room_number: string | null
    adults: number
    children: number
    total: string
    status: string
    lines: PriceLine[]
    guests: { id: number; name: string }[]
  }[]
  invoices?: { id: number; number: string; status: string; total: string; paid_total: string; balance: string; reservation_room_id: number | null }[]
  payments: { id: number; type: 'deposit' | 'payment' | 'refund'; amount: string; currency: string; amount_in_base: string; method: string | null; reference: string | null; refund_reason: string | null; paid_at: string; by: string | null }[]
}

export type AvailableType = {
  room_type_id: number
  name: string
  max_capacity: number
  base_capacity: number
  total_rooms: number
  available: number
  offered: boolean
  price: string | null
  lines: PriceLine[]
  currency: string
  free_rooms: { id: number; number: string; floor: string | null; status: string }[]
}

export type StayInput = { branch_id: number; stay_type: StayType; check_in: string; check_out?: string | null; hours?: number | null }
export type RoomInput = { room_type_id: number; room_id?: number | null; adults: number; children: number }
export type BookingInput = StayInput & {
  guest_id: number | null
  rooms: RoomInput[]
  discount_percent?: string
  cancellation_policy_id?: number | null
  source?: string
  notes?: string | null
  walk_in?: boolean
}

export type Quote = {
  stay_type: StayType
  check_in_at: string
  check_out_at: string
  currency: string
  rooms: { room_type_id: number; room_type: string; room_id: number | null; room_number: string | null; adults: number; children: number; total: string; lines: PriceLine[] }[]
  subtotal: string
  discount_percent: string
  discount_amount: string
  tax_total: string
  total: string
  deposit_required: string
  policy_name: string | null
}

export type CancellationTerms = { fee: string; refund_due: string; free: boolean; hours_before: number; policy: ReservationDetail['policy'] }

export type CalendarData = {
  from: string
  days: number
  rooms: { id: number; number: string; floor: string | null; status: string; room_type_id: number; room_type: string | null }[]
  bookings: { id: number; reservation_id: number; code: string; guest: string | null; status: ReservationStatus; stay_type: StayType; room_id: number | null; room_type_id: number; room_type: string | null; check_in_at: string; check_out_at: string }[]
}

/* ------------------------------------------------------------- hooks */

const qs = (params: Record<string, string | number | boolean | null | undefined>) =>
  new URLSearchParams(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== '').map(([k, v]) => [k, String(v)])).toString()

function useInvalidate() {
  const qc = useQueryClient()
  return () => {
    void qc.invalidateQueries({ queryKey: ['guests'] })
    void qc.invalidateQueries({ queryKey: ['reservations'] })
  }
}

export const useGuests = (q: string, page = 1, enabled = true) =>
  useQuery({ queryKey: ['guests', q, page], queryFn: () => api<Page<Guest>>(`/guests?${qs({ q, page })}`), placeholderData: keepPreviousData, enabled })

export const useGuest = (id: number) =>
  useQuery({ queryKey: ['guests', 'one', id], queryFn: () => api<{ data: Guest; stays: (Omit<ReservationSummary, 'guest' | 'rooms' | 'source' | 'expires_at' | 'adults' | 'children' | 'deposit_required'> & { branch: string | null })[] }>(`/guests/${id}`), enabled: id > 0 })

export const useGuestTypes = () => useQuery({ queryKey: ['guest-types'], queryFn: () => api<{ data: GuestType[] }>('/guest-types'), staleTime: 60_000 })

export function useSaveGuest(id?: number) {
  const invalidate = useInvalidate()
  return useMutation({
    mutationFn: (g: GuestInput) => (id ? put<{ data: Guest }>(`/guests/${id}`, g) : post<{ data: Guest; possible_duplicate: { id: number; name: string } | null }>('/guests', g)),
    onSuccess: invalidate,
  })
}

export function useBlacklist(id: number) {
  const invalidate = useInvalidate()
  return useMutation({ mutationFn: (v: { is_blacklisted: boolean; reason?: string }) => post(`/guests/${id}/blacklist`, v), onSuccess: invalidate })
}

export type ReservationFilters = { q?: string; status?: string; branch_id?: number | null; from?: string; to?: string; expiring?: boolean; sort?: string; page?: number }

export const useReservations = (f: ReservationFilters) =>
  useQuery({ queryKey: ['reservations', 'list', f], queryFn: () => api<Page<ReservationSummary>>(`/reservations?${qs(f)}`), placeholderData: keepPreviousData })

export const useReservation = (id: number) =>
  useQuery({ queryKey: ['reservations', 'one', id], queryFn: () => api<{ data: ReservationDetail }>(`/reservations/${id}`), enabled: id > 0 })

export const useAvailability = (stay: StayInput | null, reservationId?: number) =>
  useQuery({
    queryKey: ['reservations', 'availability', stay, reservationId],
    queryFn: () => api<{ data: AvailableType[]; check_in_at: string; check_out_at: string }>(`/reservations/availability?${qs({ ...stay, reservation_id: reservationId })}`),
    enabled: !!stay,
    placeholderData: keepPreviousData,
    retry: false,
  })

export const useQuote = (input: BookingInput | null, reservationId?: number) =>
  useQuery({
    queryKey: ['reservations', 'quote', input, reservationId],
    queryFn: () => post<{ data: Quote }>('/reservations/quote', { ...input, reservation_id: reservationId }),
    enabled: !!input && input.rooms.length > 0,
    placeholderData: keepPreviousData,
    retry: false,
  })

export function useSaveReservation(id?: number) {
  const invalidate = useInvalidate()
  return useMutation({
    mutationFn: (b: BookingInput) => (id ? put<{ data: ReservationDetail }>(`/reservations/${id}`, b) : post<{ data: ReservationDetail }>('/reservations', b)),
    onSuccess: invalidate,
  })
}

export const useCancellationTerms = (id: number, noShow: boolean, enabled: boolean) =>
  useQuery({ queryKey: ['reservations', 'terms', id, noShow], queryFn: () => api<{ data: CancellationTerms }>(`/reservations/${id}/cancellation-terms?${qs({ no_show: noShow ? 1 : undefined })}`), enabled })

export function useCancelReservation(id: number) {
  const invalidate = useInvalidate()
  return useMutation({
    mutationFn: (v: { reason: string; noShow: boolean }) => post<{ data: ReservationDetail }>(`/reservations/${id}/${v.noShow ? 'no-show' : 'cancel'}`, { reason: v.reason }),
    onSuccess: invalidate,
  })
}

export type PaymentInput = { type: 'deposit' | 'payment' | 'refund'; payment_method_id: number; amount: string; currency: string; reference?: string; refund_reason?: string }

export function useRecordPayment(id: number) {
  const invalidate = useInvalidate()
  return useMutation({ mutationFn: (p: PaymentInput) => post<{ data: ReservationDetail }>(`/reservations/${id}/payments`, p), onSuccess: invalidate })
}

export const useCalendar = (branchId: number | null, from: string, days: number) =>
  useQuery({
    queryKey: ['reservations', 'calendar', branchId, from, days],
    queryFn: () => api<CalendarData>(`/reservations/calendar?${qs({ branch_id: branchId, from, days })}`),
    enabled: !!branchId,
    placeholderData: keepPreviousData,
  })

export type Lookups = {
  policies: { id: number; name: string; free_until_hours: number | null; penalty_type: string; penalty_value: string; is_default: boolean }[]
  payment_methods: { id: number; name: string; type: string }[]
  currencies: { code: string; symbol: string; is_default: boolean }[]
}

export const useLookups = () => useQuery({ queryKey: ['reservations', 'lookups'], queryFn: () => api<Lookups>('/reservations/lookups'), staleTime: 60_000 })

/* ------------------------------------------------------------- dates (hotel time) */

/** "2026-11-12T14:00:00+03:00" → "2026-11-12" (the hotel's own date). */
export const localDate = (iso: string) => iso.slice(0, 10)
/** → "14:00" */
export const localTime = (iso: string) => iso.slice(11, 16)

export function addDays(isoDate: string, n: number): string {
  const [y, m, d] = isoDate.split('-').map(Number)
  const dt = new Date(Date.UTC(y, m - 1, d + n))
  return dt.toISOString().slice(0, 10)
}

export function daysBetween(a: string, b: string): number {
  const [y1, m1, d1] = a.split('-').map(Number)
  const [y2, m2, d2] = b.split('-').map(Number)
  return Math.round((Date.UTC(y2, m2 - 1, d2) - Date.UTC(y1, m1 - 1, d1)) / 86_400_000)
}

/** Today's date on this device (Ethiopia uses one time zone). */
export function today(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export const STATUS_TONES: Record<ReservationStatus, 'warn' | 'ok' | 'info' | 'grey' | 'danger' | 'accent'> = {
  pending: 'warn',
  confirmed: 'ok',
  checked_in: 'info',
  checked_out: 'grey',
  cancelled: 'danger',
  no_show: 'danger',
}
