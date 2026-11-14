import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useRef } from 'react'
import { api, apiUrl, newIdempotencyKey, post, put } from './api'

/* ------------------------------------------------------------- types */

export type GuestType = { id: number; name: string }

export type Guest = {
  id: number
  first_name: string
  last_name: string | null
  full_name: string
  /** ID number or nationality still to fill in (quick check-in); null when not known. */
  details_missing?: boolean | null
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
  /** Walk-in who did not know how long they stay: a night is added each day (Q16). */
  open_stay?: boolean
  source: string
  /** Rooms only, as booked; `total` is the bills' total once there is a bill (extras included). */
  booking_total?: string
  branch: { id: number; name: string } | null
  /** When the booking was made. */
  booked_at?: string
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
  /** Allowed to pay everything at check-out, and by whom. */
  pay_later?: { by: string | null; at: string | null } | null
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
    price_override?: string | null
    status: string
    lines: PriceLine[]
    guests: { id: number; name: string }[]
  }[]
  invoices?: { id: number; number: string; status: string; total: string; paid_total: string; balance: string; reservation_room_id: number | null }[]
  payments: { id: number; type: 'deposit' | 'payment' | 'refund'; amount: string; currency: string; amount_in_base: string; method: string | null; reference: string | null; refund_reason: string | null; paid_at: string; by: string | null; proof?: { id: number; mime: string } | null }[]
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
export type RoomInput = {
  room_type_id: number
  room_id?: number | null
  adults: number
  children: number
  /** A price per night set by hand (permission reservations.override_price; logged). */
  price_override?: string | null
}
export type BookingInput = StayInput & {
  guest_id: number | null
  rooms: RoomInput[]
  discount_percent?: string
  cancellation_policy_id?: number | null
  source?: string
  notes?: string | null
  walk_in?: boolean
  /** No deposit or up-front payment: the guest pays at check-out. */
  pay_later?: boolean
  /** Why the price was set by hand (needed with a price_override). */
  price_override_reason?: string | null
  /** Book a stay shorter than a season's minimum (permission reservations.override_price). */
  allow_short_stay?: boolean
}

export type Quote = {
  stay_type: StayType
  check_in_at: string
  check_out_at: string
  currency: string
  rooms: {
    room_type_id: number
    room_type: string
    room_id: number | null
    room_number: string | null
    adults: number
    children: number
    total: string
    lines: PriceLine[]
    price_override?: string | null
    calculated_total?: string | null
    min_stay?: { name: string; nights: number } | null
  }[]
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
  /** Busy dates from Airbnb, Booking.com… (calendar sync): on a room, or on one room of a type (room_id null). */
  blocks?: { id: number; source: string | null; summary: string | null; room_id: number | null; room_type_id: number | null; check_in_at: string; check_out_at: string }[]
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

/** `stayed`: only guests who stayed on a day between from and to (hotel days). */
export const useGuests = (q: string, page = 1, enabled = true, missing = false, stayed: { from?: string; to?: string } = {}, opts: { sort?: string; dir?: string; per_page?: number } = {}) =>
  useQuery({
    queryKey: ['guests', q, page, missing, stayed, opts],
    queryFn: () => api<Page<Guest>>(`/guests?${qs({ q, page, missing: missing ? 1 : undefined, stayed_from: stayed.from, stayed_to: stayed.to, ...opts })}`),
    placeholderData: keepPreviousData,
    enabled,
  })

export const useGuest = (id: number) =>
  useQuery({ queryKey: ['guests', 'one', id], queryFn: () => api<{ data: Guest; stays: (Omit<ReservationSummary, 'guest' | 'rooms' | 'source' | 'expires_at' | 'adults' | 'children' | 'deposit_required'> & { branch: string | null })[]; id_photo?: { mime: string; at: string | null } | null }>(`/guests/${id}`), enabled: id > 0 })

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

export type ReservationFilters = { q?: string; status?: string; branch_id?: number | null; from?: string; to?: string; expiring?: boolean; sort?: string; dir?: 'asc' | 'desc'; page?: number; per_page?: number }

/** A list as an Excel file, with the same filters and order (all rows, not one page). */
export const listFileUrl = (path: string, params: Record<string, string | number | boolean | null | undefined>) => apiUrl(`${path}?${qs({ ...params, page: undefined, per_page: undefined, format: 'xlsx' })}`)

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

/** Confirm a pending booking without a deposit: the guest pays everything at check-out. */
export function usePayLater(id: number) {
  const invalidate = useInvalidate()
  return useMutation({
    mutationFn: () => post<{ data: ReservationDetail }>(`/reservations/${id}/pay-later`, {}),
    onSuccess: invalidate,
    meta: { toast: 'payLater.done' },
  })
}

export type PaymentInput = { type: 'deposit' | 'payment' | 'refund'; payment_method_id: number; amount: string; currency: string; reference?: string; refund_reason?: string; proof_id?: number }

/** Same key until the server says yes (no double payment on a bad network, see useMoneyAction). */
export function useRecordPayment(id: number) {
  const invalidate = useInvalidate()
  const key = useRef(newIdempotencyKey())
  return useMutation({
    mutationFn: (p: PaymentInput) => post<{ data: ReservationDetail }>(`/reservations/${id}/payments`, p, { 'Idempotency-Key': key.current }),
    onSuccess: () => {
      key.current = newIdempotencyKey()
      invalidate()
    },
  })
}

export const useCalendar = (branchId: number | null, from: string, days: number, roomId?: number) =>
  useQuery({
    queryKey: ['reservations', 'calendar', branchId, from, days, roomId ?? null],
    queryFn: () => api<CalendarData>(`/reservations/calendar?${qs({ branch_id: branchId, from, days, room_id: roomId })}`),
    enabled: !!branchId,
    placeholderData: keepPreviousData,
  })

export type Lookups = {
  stay_types?: ('nightly' | 'day_use' | 'hourly')[]
  policies: { id: number; name: string; free_until_hours: number | null; penalty_type: string; penalty_value: string; is_default: boolean }[]
  payment_methods: { id: number; name: string; type: string }[]
  currencies: { code: string; symbol: string; is_default: boolean }[]
  /** May this person let a guest pay everything at check-out (hotel allows it + permission)? */
  pay_later?: boolean
  /** Guest ID photo: turned on by the hotel, and needed before check-in? */
  id_photo?: { enabled: boolean; required: boolean; keep_months: number }
}

export const useLookups = () => useQuery({ queryKey: ['reservations', 'lookups'], queryFn: () => api<Lookups>('/reservations/lookups'), staleTime: 60_000 })

/* ------------------------------------------------------------- dates (hotel time) */

/** "2026-11-12T14:00:00+03:00" → "2026-11-12" (the hotel's own date). */
export const localDate = (iso: string) => iso.slice(0, 10)

/** The booking that has the room on `day` (local date): staying that night, or a day-use / hourly stay on that day. */
export function sleepingOn<B extends { check_in_at: string; check_out_at: string; status: ReservationStatus }>(bookings: B[], day: string): B | undefined {
  return bookings.find((b) => {
    if (b.status === 'cancelled' || b.status === 'no_show') return false
    const inD = localDate(b.check_in_at)
    const outD = localDate(b.check_out_at)
    return (inD <= day && day < outD) || (inD === day && outD === day)
  })
}
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
