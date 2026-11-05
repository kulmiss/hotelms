import { useMutation, useQueryClient } from '@tanstack/react-query'
import { put, type Me } from './api'
import { ME_KEY } from './auth'

export function useUpdateProfile() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (input: { name: string; phone: string | null }) => put<Me>('/me', input),
    onSuccess: (me) => qc.setQueryData(ME_KEY, me),
  })
}
