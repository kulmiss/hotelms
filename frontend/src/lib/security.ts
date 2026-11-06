import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { Tone } from '@/components/ui/Badge'
import { api, ApiError, post, put } from './api'
import { SILENT } from './toast'
import { ME_KEY } from './auth'
import { date } from './format'
import { localDate, localTime } from './front'

export type Device = { id: number; name: string; ip: string | null; status: 'active' | 'pending' | 'signed_out' | 'blocked'; approved: boolean; last_seen: string | null; this_device: boolean; user: { id: number; name: string | null } | null }
export type MySecurity = { two_factor: 'app' | 'email' | null; two_factor_required: boolean; recovery_codes_left: number; has_pin: boolean; devices: Device[] }
export type TwoFactorStart = { method: 'app'; secret: string; uri: string } | { method: 'email'; email: string }
export type SecuritySettings = { require_two_factor_staff: boolean; approved_devices_only: boolean; screen_lock_minutes: number }

function useInv<V, R>(keys: (readonly unknown[])[], fn: (v: V) => Promise<R>) {
  const qc = useQueryClient()
  return useMutation({ mutationFn: fn, onSuccess: () => keys.forEach((k) => void qc.invalidateQueries({ queryKey: k })) })
}

export const useMySecurity = () => useQuery({ queryKey: ['security', 'me'], queryFn: () => api<{ data: MySecurity }>('/me/security') })
export const useBeginTwoFactor = () => useMutation({ meta: SILENT, mutationFn: (v: { method: 'app' | 'email'; password: string }) => post<{ data: TwoFactorStart }>('/me/security/two-factor', v) })
export const useConfirmTwoFactor = () => useInv([['security'], ME_KEY], (code: string) => post<{ recovery_codes: string[] }>('/me/security/two-factor/confirm', { code }))
export const useDisableTwoFactor = () => useInv([['security'], ME_KEY], (password: string) => post('/me/security/two-factor/disable', { password }))
export const useNewRecoveryCodes = () => useInv([['security']], (password: string) => post<{ recovery_codes: string[] }>('/me/security/recovery-codes', { password }))
export const useSetPin = () => useInv([['security'], ME_KEY], (v: { pin: string; password: string }) => put('/me/security/pin', v))
export const useSignOutMyDevice = () => useInv([['security']], (id: number) => post(`/me/security/devices/${id}/sign-out`))
export const useSignOutOthers = () => useInv([['security']], () => post('/me/security/devices/sign-out-others'))

export const useHotelDevices = () => useQuery({ queryKey: ['security', 'hotel-devices'], queryFn: () => api<{ data: Device[] }>('/devices'), refetchInterval: 60_000 })
export const useDeviceAction = () => useInv([['security']], (v: { id: number; action: 'approve' | 'sign-out' | 'block' | 'unblock' }) => post(`/devices/${v.id}/${v.action}`))

export const useSecuritySettings = () => useQuery({ queryKey: ['security', 'settings'], queryFn: () => api<{ data: SecuritySettings }>('/settings/security') })
export const useSaveSecuritySettings = () => useInv([['security'], ME_KEY], (v: SecuritySettings) => put<{ data: SecuritySettings }>('/settings/security', v))

/** Screen lock: take over with a PIN (another staff member), or unlock with your password. */
export function usePinSwitch() {
  const qc = useQueryClient()
  return useMutation({
    meta: SILENT,
    mutationFn: (pin: string) => post('/auth/pin-switch', { pin }),
    onSuccess: () => {
      qc.removeQueries({ predicate: (q) => q.queryKey[0] !== 'me' && q.queryKey[0] !== 'branding' })
      void qc.invalidateQueries({ queryKey: ME_KEY })
    },
  })
}
export const useUnlock = () => useMutation({ meta: SILENT, mutationFn: (password: string) => post('/auth/unlock', { password }) })

export const errorText = (e: unknown): string | null => (e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : e ? String(e) : null)

export const STATUS_TONE: Record<Device['status'], Tone> = { active: 'ok', pending: 'warn', blocked: 'danger', signed_out: 'grey' }

export const lastSeen = (iso: string | null) => (iso ? `${date(localDate(iso))} ${localTime(iso)}` : '—')
