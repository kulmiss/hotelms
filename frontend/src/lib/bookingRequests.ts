import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, post } from './api'
import { SILENT } from './toast'

/** A guest's request from the hotel's public booking page (/h/{link}). */
export type BookingRequest = {
  id: number
  status: 'new' | 'accepted' | 'declined'
  name: string
  phone: string
  email: string | null
  note: string | null
  branch: { id: number; name: string } | null
  room_type: { id: number; name: string } | null
  check_in: string
  check_out: string
  adults: number
  children: number
  reservation_id: number | null
  created_at: string | null
  handled_at: string | null
}

export const useBookingRequests = (status: string) =>
  useQuery({ queryKey: ['booking-requests', status], queryFn: () => api<{ data: BookingRequest[]; new: number }>(`/booking-requests?status=${status}`), refetchInterval: 60_000 })

export const useBookingRequest = (id: number) =>
  useQuery({ queryKey: ['booking-request', id], queryFn: () => api<{ data: BookingRequest }>(`/booking-requests/${id}`).then((r) => r.data), enabled: id > 0 })

export function useHandleBookingRequest() {
  const qc = useQueryClient()
  return useMutation({
    meta: SILENT,
    mutationFn: ({ id, action, reservation_id }: { id: number; action: 'accept' | 'decline'; reservation_id?: number }) =>
      post<{ data: BookingRequest }>(`/booking-requests/${id}/${action}`, reservation_id ? { reservation_id } : {}),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['booking-requests'] }),
  })
}
