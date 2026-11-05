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

/** Filament "stats overview" card; the number counts up when it changes. */
export function StatTile({
  label,
  value,
  icon: Icon,
  tone = 'accent',
  footer,
}: {
  label: string
  value: number | undefined
  icon: IconType
  tone?: Tone
  footer?: ReactNode
}) {
  const shown = useCountUp(value)
  return (
    <div className="panel flex flex-col gap-y-1.5 p-5">
      <div className="flex items-center gap-x-2">
        <Icon aria-hidden className={cn('size-4', tones[tone])} />
        <span className="text-[13px] font-medium text-muted">{label}</span>
      </div>
      <span className="text-2xl font-semibold tracking-tight" aria-live="polite">
        {shown ?? '—'}
      </span>
      {footer && <div className={cn('text-sm', tones[tone])}>{footer}</div>}
    </div>
  )
}
