import type { ReactNode } from 'react'
import type { IconType } from '@/app/navigation'
import { useCountUp } from '@/lib/useCountUp'
import { cn } from '@/lib/cn'

type Tone = 'accent' | 'ok' | 'info' | 'warn' | 'danger'

const tones: Record<Tone, string> = {
  accent: 'text-accent-text',
  ok: 'text-ok-text',
  info: 'text-info-text',
  warn: 'text-warn-text',
  danger: 'text-danger-text',
}

/** The template's stat card: title and icon on top, a big number, a small line under it. The number counts up when it changes. */
export function StatTile({
  label,
  value,
  icon: Icon,
  tone = 'accent',
  footer,
  suffix,
}: {
  label: string
  value: number | undefined
  icon: IconType
  tone?: Tone
  footer?: ReactNode
  /** Shown right after the number, e.g. "%". */
  suffix?: string
}) {
  const shown = useCountUp(value)
  return (
    <div data-slot="card" className="panel flex flex-col gap-2 p-6">
      <div className="flex items-center justify-between gap-2">
        <span className="text-sm font-medium">{label}</span>
        <Icon aria-hidden className={cn('size-4 shrink-0', tones[tone])} />
      </div>
      <span className="text-2xl font-bold tracking-tight tabular-nums" aria-live="polite">
        {shown ?? '—'}
        {shown !== undefined && suffix}
      </span>
      {footer && <div className={cn('text-xs', tones[tone])}>{footer}</div>}
    </div>
  )
}
