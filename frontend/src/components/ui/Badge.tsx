import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'

export type Tone = 'ok' | 'warn' | 'info' | 'danger' | 'grey' | 'accent'

const tones: Record<Tone, string> = {
  ok: 'bg-ok-bg text-ok-text',
  warn: 'bg-warn-bg text-warn-text',
  info: 'bg-info-bg text-info-text',
  danger: 'bg-danger-bg text-danger-text',
  grey: 'bg-grey-bg text-grey-text',
  accent: 'bg-accent-soft text-accent-text',
}

export function Badge({ tone = 'grey', children }: { tone?: Tone; children: ReactNode }) {
  return (
    <span className={cn('inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold', tones[tone])}>
      {children}
    </span>
  )
}
