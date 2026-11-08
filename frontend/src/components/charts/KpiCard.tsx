import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { ArrowDown, ArrowUp } from 'lucide-react'
import { cn } from '@/lib/cn'
import { Sparkline } from './charts'

/**
 * A KPI card: label, value, change vs the period before (green when it is good) and a small trend line.
 * The whole card is a button that opens the details in place (onOpen).
 */
export function KpiCard({
  label,
  value,
  change,
  changeText,
  invert = false,
  sub,
  spark,
  loading,
  onOpen,
}: {
  label: string
  value: ReactNode
  /** Percent change (or points when changeText says so); null = nothing to compare. */
  change?: number | null
  /** Overrides the change text ("▼ 4.3 pts"). */
  changeText?: string
  invert?: boolean
  sub?: ReactNode
  spark?: number[]
  loading?: boolean
  onOpen?: () => void
}) {
  const { t } = useTranslation()
  const good = change == null || change === 0 ? null : invert ? change < 0 : change > 0
  const Icon = (change ?? 0) >= 0 ? ArrowUp : ArrowDown
  const body = (
    <>
      <span className="text-[13px] font-medium text-muted">{label}</span>
      {loading ? (
        <span aria-hidden className="h-7 w-28 animate-pulse rounded bg-sunken motion-reduce:animate-none" />
      ) : (
        <span className="text-2xl font-bold tracking-tight tabular-nums">{value}</span>
      )}
      <span className="flex min-h-8 items-end justify-between gap-2">
        <span className="text-xs">
          {change != null && (
            <span className={cn('inline-flex items-center gap-0.5 font-medium tabular-nums', good === null ? 'text-muted' : good ? 'text-ok-text' : 'text-danger-text')}>
              <Icon aria-hidden className="size-3" />
              {changeText ?? `${Math.abs(change)}%`}
              <span className="sr-only">{good ? t('dash.better') : t('dash.worse')}</span>
            </span>
          )}
          {sub && <span className="text-muted"> {sub}</span>}
        </span>
        {!loading && spark && <Sparkline values={spark} label={t('dash.sparkAria', { label })} />}
      </span>
    </>
  )
  const cls = 'panel flex min-w-0 flex-col gap-1.5 p-4 text-left'
  return onOpen ? (
    <button type="button" onClick={onOpen} className={cn(cls, 'transition-colors duration-150 hover:bg-sunken/40 focus-visible:ring-[3px] focus-visible:ring-focus/50 focus-visible:outline-none')}>
      {body}
    </button>
  ) : (
    <div className={cls}>{body}</div>
  )
}
