import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, post, prepareSession, put, type Me } from './api'

export const ME_KEY = ['me'] as const

/** The signed-in user, or null when signed out. */
export function useMe() {
  return useQuery({
    queryKey: ME_KEY,
    queryFn: async (): Promise<Me | null> => {
      try {
        return await api<Me>('/me')
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) return null
        throw e
      }
    },
    staleTime: 5 * 60_000,
    retry: false,
  })
}

export function useLogin() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (input: { email: string; password: string; remember: boolean }) => {
      await prepareSession()
      await post('/auth/login', input)
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ME_KEY }),
  })
}

export function useLogout() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: () => post('/auth/logout'),
    onSettled: () => {
      qc.clear()
      qc.setQueryData(ME_KEY, null)
    },
  })
}

export function useChangePassword() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (input: { current_password: string; password: string; password_confirmation: string }) =>
      put('/me/password', input),
    onSuccess: () => qc.invalidateQueries({ queryKey: ME_KEY }),
  })
}

/** Leave a hotel after "Log in as" and go back to the Super Admin account. */
export function useStopImpersonation() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: () => post('/impersonation/stop'),
    onSuccess: () => qc.clear(),
  })
}

/** Public "Try the demo": sign in as a demo user of the chosen role. */
export function useDemoLogin() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (role: string) => {
      await prepareSession()
      await post('/demo/login', { role })
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ME_KEY }),
  })
}
