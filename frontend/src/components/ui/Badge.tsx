import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'

export type Tone = 'ok' | 'warn' | 'info' | 'danger' | 'grey' | 'accent'

// The template's badge shape (rounded-md, border, 12px); our colours keep the meaning (paid, owes, cleaning…).
const tones: Record<Tone, string> = {
  ok: 'border-ok-text/20 bg-ok-bg text-ok-text',
  warn: 'border-warn-text/25 bg-warn-bg text-warn-text',
  info: 'border-info-text/20 bg-info-bg text-info-text',
  danger: 'border-danger-text/20 bg-danger-bg text-danger-text',
  grey: 'border-grey-text/15 bg-grey-bg text-grey-text',
  accent: 'border-accent-text/20 bg-accent-soft text-accent-text',
}

export function Badge({ tone = 'grey', children }: { tone?: Tone; children: ReactNode }) {
  return (
    <span data-slot="badge" className={cn('inline-flex w-fit shrink-0 items-center justify-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap [&>svg]:size-3', tones[tone])}>
      {children}
    </span>
  )
}
