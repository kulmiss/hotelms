import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useRef } from 'react'
import { api, del, newIdempotencyKey, post } from './api'
import type { Page, ReservationDetail, ReservationSummary } from './front'

/* ------------------------------------------------------------- front desk */

export type StayInfo = { reservation_id: number; code: string | null; guest: string | null; check_in_at: string; check_out_at: string; balance: string; overdue: boolean }
export type BoardRoom = { id: number; number: string; floor: string | null; status: string; room_type_id: number; room_type: string | null; notes: string | null; stay: StayInfo | null; arriving: StayInfo | null }
/** The hotel block printed at the top of invoices, receipts and registration cards. */
export type PrintHotel = { name: string; legal_name: string | null; tin: string | null; vat_number: string | null; address: string | null; city: string | null; phone: string | null; email: string | null; logo_url: string | null; footer: string | null; receipt_paper?: 'a4' | 'receipt' }

export type TodayRow = ReservationSummary & { late: boolean; overdue: boolean; balance: string }

export type CheckInInfo = {
  reservation: ReservationDetail
  rooms: { id: number; room_type: string | null; room_id: number | null; room_number: string | null; room_status: string | null; free_rooms: { id: number; number: string; floor: string | null }[]; early_fee: string | null }[]
  deposit_due: string
}
export type CheckOutInfo = { reservation: ReservationDetail; late_fee: string | null; balance: string }
export type FreeRoom = { id: number; number: string; floor: string | null; room_type_id: number; room_type: string | null; nightly_price: string | null; day_use_price: string | null; hourly_price: string | null; max_capacity: number | null; currency: string | null }
export type PaymentFields = { payment_method_id: number; amount: string; currency?: string; reference?: string }

const branchQs = (branchId: number | null) => (branchId ? `?branch_id=${branchId}` : '')

function useDeskInvalidate() {
  const qc = useQueryClient()
  return () => {
    void qc.invalidateQueries({ queryKey: ['desk'] })
    void qc.invalidateQueries({ queryKey: ['reservations'] })
    void qc.invalidateQueries({ queryKey: ['invoices'] })
  }
}

/** Live screens poll (no WebSocket server on shared hosting). */
const LIVE = 30_000

export const useBoard = (branchId: number | null) =>
  useQuery({ queryKey: ['desk', 'board', branchId], queryFn: () => api<{ data: BoardRoom[]; counts: Record<string, number>; refreshed_at: string }>(`/front-desk/board${branchQs(branchId)}`), refetchInterval: LIVE, placeholderData: keepPreviousData })

export const useToday = (branchId: number | null) =>
  useQuery({ queryKey: ['desk', 'today', branchId], queryFn: () => api<{ arrivals: TodayRow[]; departures: TodayRow[]; in_house: TodayRow[] }>(`/front-desk/today${branchQs(branchId)}`), refetchInterval: LIVE, placeholderData: keepPreviousData })

/** Clean rooms free from now for the whole stay (check-out date for nights, hours for hourly). */
export type FreeFor = { stay_type: 'nightly' | 'day_use' | 'hourly'; check_out?: string; hours?: number }

export const useFreeRooms = (branchId: number | null, enabled = true, stay?: FreeFor) => {
  const params = new URLSearchParams()
  if (branchId) params.set('branch_id', String(branchId))
  if (stay) {
    params.set('stay_type', stay.stay_type)
    if (stay.stay_type === 'nightly' && stay.check_out) params.set('check_out', stay.check_out)
    if (stay.stay_type === 'hourly' && stay.hours) params.set('hours', String(stay.hours))
  }
  const qs = params.toString()
  return useQuery({
    queryKey: ['desk', 'free', branchId, stay ?? null],
    queryFn: () => api<{ data: FreeRoom[]; walk_in_payment: 'full' | 'deposit'; now: string }>(`/front-desk/walk-in/rooms${qs ? `?${qs}` : ''}`),
    enabled,
    placeholderData: keepPreviousData,
  })
}

export const useCheckInInfo = (id: number, enabled: boolean) => useQuery({ queryKey: ['desk', 'check-in', id], queryFn: () => api<{ data: CheckInInfo }>(`/reservations/${id}/check-in`), enabled })
export const useCheckOutInfo = (id: number, enabled: boolean) => useQuery({ queryKey: ['desk', 'check-out', id], queryFn: () => api<{ data: CheckOutInfo }>(`/reservations/${id}/check-out`), enabled })

export function useDeskAction<V, R>(fn: (v: V) => Promise<R>) {
  const invalidate = useDeskInvalidate()
  return useMutation({ mutationFn: fn, onSuccess: invalidate })
}

/**
 * Like useDeskAction, for anything that takes money: the same Idempotency-Key is sent again
 * until the server has said yes, so a second tap or a retry after a dropped connection never
 * takes the money twice. After a success the next action gets a new key.
 */
export function useMoneyAction<V, R>(fn: (v: V, headers: Record<string, string>) => Promise<R>) {
  const invalidate = useDeskInvalidate()
  const key = useRef(newIdempotencyKey())
  return useMutation({
    mutationFn: (v: V) => fn(v, { 'Idempotency-Key': key.current }),
    onSuccess: () => {
      key.current = newIdempotencyKey()
      invalidate()
    },
  })
}

export const useCheckIn = (id: number) =>
  useMoneyAction((v: { rooms: { id: number; room_id: number | null }[]; waive_early_fee: boolean; payment?: PaymentFields | null }, h) => post<{ data: ReservationDetail }>(`/reservations/${id}/check-in`, v, h))
export const useCheckOut = (id: number) =>
  useMoneyAction((v: { waive_late_fee: boolean; approve_balance: boolean; payment?: PaymentFields | null }, h) => post<{ data: ReservationDetail }>(`/reservations/${id}/check-out`, v, h))
export const useMoveRoom = (id: number) => useDeskAction((v: { stay: number; room_id: number }) => post<{ data: ReservationDetail }>(`/reservations/${id}/rooms/${v.stay}/move`, { room_id: v.room_id }))
export const useExtend = (id: number) => useDeskAction((v: { check_out?: string; hours?: number }) => post<{ data: ReservationDetail }>(`/reservations/${id}/extend`, v))
export const useApproveBalance = (id: number) => useDeskAction(() => post<{ data: ReservationDetail }>(`/reservations/${id}/approve-balance`))
export const useRoomStatus = () => useDeskAction((v: { id: number; status: string }) => post(`/rooms/${v.id}/status`, { status: v.status }))
export const useWalkIn = () =>
  useMoneyAction((v: { branch_id: number; stay_type: string; check_out?: string | null; hours?: number | null; guest_id?: number | null; quick_guest?: { name: string; phone: string }; rooms: { room_type_id: number; room_id: number; adults: number; children: number }[]; discount_percent?: string; payment?: PaymentFields; pay_later?: boolean }, h: Record<string, string>) =>
    post<{ data: ReservationDetail }>('/front-desk/walk-in', v, h),
  )

/* ------------------------------------------------------------- billing */

export type InvoiceSummary = {
  id: number
  number: string
  status: 'open' | 'partially_paid' | 'paid' | 'outstanding' | 'void'
  reservation: { id: number; code: string } | null
  guest: { id: number; name: string; phone: string | null } | null
  branch: { id: number; name: string } | null
  currency: string
  total: string
  paid_total: string
  balance: string
  issued_at: string
}

export type InvoiceItem = { id: number; type: string; description: string; quantity: string; unit_price: string; amount: string; is_waived: boolean; service_date: string; reservation_room_id: number | null }

export type InvoiceDetail = InvoiceSummary & {
  subtotal: string
  discount_total: string
  tax_total: string
  reservation_room_id: number | null
  guest: { id: number; full_name: string; phone: string | null; email: string | null; id_number: string | null; nationality: string | null }
  outstanding_approved_at: string | null
  voided_at: string | null
  void_reason: string | null
  stay: { check_in_at: string; check_out_at: string; status: string; rooms: { id: number; room_number: string | null; room_type: string | null }[] } | null
  items: InvoiceItem[]
  taxes: { name: string; rate: string; is_inclusive: boolean; amount: string }[]
  payments: { id: number; type: string; amount: string; currency: string; amount_in_base: string; method: string | null; reference: string | null; refund_reason: string | null; paid_at: string; by: string | null }[]
  hotel: PrintHotel
}

export type PaymentRow = { id: number; type: string; amount: string; currency: string; amount_in_base: string; method: string | null; reference: string | null; by: string | null; paid_at: string; reservation: { id: number; code: string; guest: string | null } | null; invoice: { id: number; number: string } | null }

const qs = (p: Record<string, string | number | boolean | null | undefined>) =>
  new URLSearchParams(Object.entries(p).filter(([, v]) => v !== undefined && v !== null && v !== '' && v !== false).map(([k, v]) => [k, String(v)])).toString()

export const useInvoices = (f: { q?: string; status?: string; unpaid?: boolean; page?: number }) =>
  useQuery({ queryKey: ['invoices', 'list', f], queryFn: () => api<Page<InvoiceSummary>>(`/invoices?${qs(f)}`), placeholderData: keepPreviousData })
export const useInvoice = (id: number) => useQuery({ queryKey: ['invoices', 'one', id], queryFn: () => api<{ data: InvoiceDetail }>(`/invoices/${id}`), enabled: id > 0 })
export const useChargeTypes = () => useQuery({ queryKey: ['invoices', 'charge-types'], queryFn: () => api<{ data: { id: number; name: string; default_price: string | null; currency: string }[] }>('/charge-types'), staleTime: 60_000 })
export const usePayments = (f: { type?: string; from?: string; to?: string; page?: number }) =>
  useQuery({ queryKey: ['invoices', 'payments', f], queryFn: () => api<Page<PaymentRow>>(`/payments?${qs(f)}`), placeholderData: keepPreviousData })

export const useAddCharge = (id: number) =>
  useDeskAction((v: { type: 'extra' | 'discount'; charge_type_id?: number | null; description?: string; quantity?: string; unit_price?: string; discount_percent?: string }) => post<{ data: InvoiceDetail }>(`/invoices/${id}/items`, v))
export const useRemoveItem = (id: number) => useDeskAction((itemId: number) => del(`/invoices/${id}/items/${itemId}`))
export const useInvoicePayment = (id: number) =>
  useMoneyAction((v: { type: 'payment' | 'refund'; payment_method_id: number; amount: string; currency: string; reference?: string; refund_reason?: string }, h) => post<{ data: InvoiceDetail }>(`/invoices/${id}/payments`, v, h))
export const useVoidInvoice = (id: number) => useDeskAction((reason: string) => post<{ data: InvoiceDetail }>(`/invoices/${id}/void`, { reason }))
export const useSplitInvoice = (id: number) => useDeskAction(() => post<{ data: InvoiceSummary[] }>(`/invoices/${id}/split`))

export const INVOICE_TONES: Record<InvoiceSummary['status'], 'warn' | 'ok' | 'info' | 'grey' | 'danger'> = {
  open: 'info',
  partially_paid: 'warn',
  paid: 'ok',
  outstanding: 'danger',
  void: 'grey',
}

export const ROOM_TONES: Record<string, string> = {
  available: 'bg-room-available/15 ring-room-available/40',
  occupied: 'bg-room-occupied/15 ring-room-occupied/40',
  dirty: 'bg-room-dirty/15 ring-room-dirty/40',
  cleaning: 'bg-room-clean/15 ring-room-clean/40',
  maintenance: 'bg-room-maintenance/15 ring-room-maintenance/40',
  out_of_order: 'bg-room-maintenance/25 ring-room-maintenance/60',
}
export const ROOM_DOTS: Record<string, string> = {
  available: 'bg-room-available',
  occupied: 'bg-room-occupied',
  dirty: 'bg-room-dirty',
  cleaning: 'bg-room-clean',
  maintenance: 'bg-room-maintenance',
  out_of_order: 'bg-room-maintenance',
}
