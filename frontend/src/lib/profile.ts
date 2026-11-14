import { useMutation, useQueryClient } from '@tanstack/react-query'
import { put, type CalendarMode, type Me } from './api'
import { ME_KEY } from './auth'
import { setCalendarMode } from './format'

export function useUpdateProfile() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (input: { name: string; phone: string | null; calendar?: CalendarMode | null }) => put<Me>('/me', input),
    onSuccess: (me) => {
      setCalendarMode(me.user.calendar ?? me.hotel?.calendar)
      qc.setQueryData(ME_KEY, me)
    },
  })
}

/** Platform Owner only: change the sign-in email (needs the current password). */
export function useUpdateEmail() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (input: { email: string; current_password: string }) => put<Me>('/me/email', input),
    onSuccess: (me) => qc.setQueryData(ME_KEY, me),
  })
}
