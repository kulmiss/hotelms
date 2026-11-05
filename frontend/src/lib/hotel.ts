import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, del, post, put } from './api'
import { useMe } from './auth'

/** Does the signed-in hotel user have this permission? */
export function useCan() {
  const me = useMe()
  const perms = new Set(me.data?.permissions ?? [])
  return (permission?: string) => !permission || perms.has(permission)
}

export type Role = {
  id: number
  key: string | null
  name: string
  description: string | null
  is_default: boolean
  is_locked: boolean
  max_discount_percent: string
  permissions: string[]
  users_count: number | null
}

export type StaffMember = {
  id: number
  name: string
  email: string
  phone: string | null
  status: 'active' | 'disabled'
  must_change_password: boolean
  last_login_at: string | null
  role: { id: number; name: string; is_locked: boolean } | null
  branches: { id: number; name: string }[]
}

export type PermissionGroup = { group: string; permissions: { key: string; module: string | null }[] }

export type ActivityEntry = {
  id: number
  event: string
  description: string
  subject_type: string | null
  properties: { old?: Record<string, unknown>; new?: Record<string, unknown>; [k: string]: unknown } | null
  created_at: string
  user: { id: number; name: string } | null
}

export const useRoles = () => useQuery({ queryKey: ['hotel', 'roles'], queryFn: () => api<{ data: Role[] }>('/roles') })
export const useRole = (id: number) =>
  useQuery({ queryKey: ['hotel', 'role', id], queryFn: () => api<{ data: Role }>(`/roles/${id}`), enabled: id > 0 })
export const usePermissionCatalog = () =>
  useQuery({ queryKey: ['hotel', 'permissions'], queryFn: () => api<{ data: PermissionGroup[] }>('/permissions'), staleTime: Infinity })
export const useStaff = () => useQuery({ queryKey: ['hotel', 'staff'], queryFn: () => api<{ data: StaffMember[] }>('/staff') })
export const useStaffMember = (id: number) =>
  useQuery({ queryKey: ['hotel', 'staff', id], queryFn: () => api<{ data: StaffMember }>(`/staff/${id}`), enabled: id > 0 })
export const useActivityLog = (search: string, page: number) =>
  useQuery({
    queryKey: ['hotel', 'activity', search, page],
    queryFn: () =>
      api<{ data: ActivityEntry[]; current_page: number; last_page: number }>(
        `/activity-log?page=${page}${search ? `&search=${encodeURIComponent(search)}` : ''}`,
      ),
    placeholderData: keepPreviousData,
  })

function useHotelMutation<TInput, TOutput = unknown>(fn: (input: TInput) => Promise<TOutput>) {
  const qc = useQueryClient()
  return useMutation({ mutationFn: fn, onSuccess: () => qc.invalidateQueries({ queryKey: ['hotel'] }) })
}

export type RoleInput = { name: string; description: string | null; max_discount_percent: string; permissions: string[] }
export const useSaveRole = (id?: number) =>
  useHotelMutation((input: RoleInput) => (id ? put<{ data: Role }>(`/roles/${id}`, input) : post<{ data: Role }>('/roles', input)))
export const useDeleteRole = (id: number) => useHotelMutation(() => del(`/roles/${id}`))

export type StaffInput = { name: string; email: string; phone: string | null; role_id: number; branch_ids: number[]; send_email?: boolean }
export type NewStaffResult = { data: StaffMember; temporary_password: string; email_sent: boolean }
export const useSaveStaff = (id?: number) =>
  useHotelMutation((input: StaffInput) =>
    id ? put<{ data: StaffMember }>(`/staff/${id}`, input) : post<NewStaffResult>('/staff', input),
  )
export const useSetStaffActive = (id: number) =>
  useHotelMutation((active: boolean) => post(`/staff/${id}/${active ? 'enable' : 'disable'}`))
export const useResetStaffPassword = (id: number) =>
  useHotelMutation((send_email: boolean) =>
    post<{ temporary_password: string; email_sent: boolean }>(`/staff/${id}/reset-password`, { send_email }),
  )
