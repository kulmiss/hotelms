import { wipeOfflineCopy } from './offline'
import { SILENT } from './toast'
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

/** Sign-in answer: done, or the second step is needed ("app" or "email"). */
export type LoginResult = { message?: string; two_factor?: 'app' | 'email'; email?: string | null }

export function useLogin() {
  const qc = useQueryClient()
  return useMutation({ meta: SILENT,
    mutationFn: async (input: { email: string; password: string; remember: boolean }) => {
      await prepareSession()
      return post<LoginResult>('/auth/login', input)
    },
    onSuccess: (r) => {
      if (!r?.two_factor) void qc.invalidateQueries({ queryKey: ME_KEY })
    },
  })
}

export function useTwoFactorLogin() {
  const qc = useQueryClient()
  return useMutation({ meta: SILENT,
    mutationFn: (code: string) => post('/auth/two-factor', { code }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ME_KEY }),
  })
}

export const useResendCode = () => useMutation({ meta: SILENT, mutationFn: () => post('/auth/two-factor/resend') })

export function useLogout() {
  const qc = useQueryClient()
  return useMutation({ meta: SILENT,
    mutationFn: () => post('/auth/logout'),
    onSettled: () => {
      wipeOfflineCopy()
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
  return useMutation({ meta: SILENT,
    mutationFn: () => post('/impersonation/stop'),
    // Drop the hotel's data, then wait for "who am I" again before moving on (else /admin sees the hotel user).
    onSuccess: async () => {
      qc.removeQueries({ predicate: (q) => q.queryKey[0] !== ME_KEY[0] })
      await qc.refetchQueries({ queryKey: ME_KEY })
    },
  })
}

/** Public "Try the demo": sign in as a demo user of the chosen role. */
export function useDemoLogin() {
  const qc = useQueryClient()
  return useMutation({ meta: SILENT,
    mutationFn: async (role: string) => {
      await prepareSession()
      await post('/demo/login', { role })
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ME_KEY }),
  })
}
