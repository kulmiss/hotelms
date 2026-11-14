import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, post, put } from './api'
import { ME_KEY } from './auth'
import { SILENT } from './toast'

export const SETUP_STEPS = ['account', 'profile', 'branches', 'room_types', 'rooms', 'payments', 'taxes', 'booking_rules', 'cancellation', 'modules', 'team', 'review'] as const
export type SetupStepKey = (typeof SETUP_STEPS)[number]
export type StepState = 'done' | 'skipped' | 'todo'

export type Setup = {
  completed: boolean
  started: boolean
  skipped: number
  remaining: number
  steps: { key: SetupStepKey; required: boolean; state: StepState }[]
  hotel: { name: string; status: string; plan: string | null; trial_ends_at: string | null; max_rooms: number | null }
}

const KEY = ['setup'] as const

export const useSetup = () => useQuery({ queryKey: KEY, queryFn: () => api<{ data: Setup }>('/setup') })

function useSetupMutation<V>(fn: (v: V) => Promise<unknown>) {
  const qc = useQueryClient()
  return useMutation({
    meta: SILENT,
    mutationFn: fn,
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: KEY })
      void qc.invalidateQueries({ queryKey: ME_KEY })
    },
  })
}

export const useMarkStep = () => useSetupMutation((v: { step: SetupStepKey; state: 'done' | 'skipped' }) => put(`/setup/steps/${v.step}`, { state: v.state }))
export const useFinishSetup = () => useSetupMutation(() => post('/setup/finish'))

/** The "Getting started" checklist on the Hotel Admin's dashboard. */
export type GettingStarted = { items: { key: string; done: boolean; link: string }[]; done: number; total: number; dismissed: boolean }
const GS_KEY = ['getting-started'] as const
export const useGettingStarted = (enabled: boolean) =>
  useQuery({ queryKey: GS_KEY, queryFn: () => api<{ data: GettingStarted }>('/getting-started').then((r) => r.data), enabled, retry: false, meta: SILENT })
export function useDismissGettingStarted() {
  const qc = useQueryClient()
  return useMutation({ meta: SILENT, mutationFn: () => post<{ data: GettingStarted }>('/getting-started/dismiss'), onSuccess: (r) => qc.setQueryData(GS_KEY, r.data) })
}
