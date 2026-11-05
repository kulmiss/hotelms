import type { ReactNode } from 'react'
import type { Icon } from '@phosphor-icons/react'
import { useCountUp } from '@/lib/useCountUp'
import { cn } from '@/lib/cn'

type Tone = 'accent' | 'ok' | 'info' | 'warn' | 'danger'

const tiles: Record<Tone, string> = {
  accent: 'bg-accent-soft text-accent-text',
  ok: 'bg-ok-bg text-ok-text',
  info: 'bg-info-bg text-info-text',
  warn: 'bg-warn-bg text-warn-text',
  danger: 'bg-danger-bg text-danger-text',
}

/** A key number with a duotone icon tile; the number counts up when it changes. */
export function StatTile({
  label,
  value,
  icon: IconCmp,
  tone = 'accent',
  footer,
}: {
  label: string
  value: number | undefined
  icon: Icon
  tone?: Tone
  footer?: ReactNode
}) {
  const shown = useCountUp(value)
  return (
    <div className="flex flex-col gap-4 rounded-2xl border border-border/70 bg-surface p-5 shadow-soft">
      <div className="flex items-center justify-between gap-3">
        <span className="text-sm font-medium text-muted">{label}</span>
        <span className={cn('flex size-11 items-center justify-center rounded-xl', tiles[tone])}>
          <IconCmp aria-hidden weight="duotone" className="size-6" />
        </span>
      </div>
      <span className="text-4xl font-semibold tracking-tight" aria-live="polite">
        {shown ?? '—'}
      </span>
      {footer && <div className="text-sm text-muted">{footer}</div>}
    </div>
  )
}
