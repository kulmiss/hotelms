import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'

export type Tone = 'ok' | 'warn' | 'info' | 'danger' | 'grey' | 'accent'

// Filament badges: soft background, coloured text, inset ring.
const tones: Record<Tone, string> = {
  ok: 'bg-ok-bg text-ok-text ring-ok-text/20',
  warn: 'bg-warn-bg text-warn-text ring-warn-text/20',
  info: 'bg-info-bg text-info-text ring-info-text/20',
  danger: 'bg-danger-bg text-danger-text ring-danger-text/20',
  grey: 'bg-grey-bg text-grey-text ring-grey-text/20',
  accent: 'bg-accent-soft text-accent-text ring-accent-text/20',
}

export function Badge({ tone = 'grey', children }: { tone?: Tone; children: ReactNode }) {
  return (
    <span className={cn('inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset', tones[tone])}>
      {children}
    </span>
  )
}
