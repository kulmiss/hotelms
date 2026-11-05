import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, post, put } from './api'
import { ME_KEY } from './auth'

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
    mutationFn: fn,
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: KEY })
      void qc.invalidateQueries({ queryKey: ME_KEY })
    },
  })
}

export const useMarkStep = () => useSetupMutation((v: { step: SetupStepKey; state: 'done' | 'skipped' }) => put(`/setup/steps/${v.step}`, { state: v.state }))
export const useFinishSetup = () => useSetupMutation(() => post('/setup/finish'))
