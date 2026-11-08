import { Component, useSyncExternalStore, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { RefreshCw } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { cn } from '@/lib/cn'

/*
 * Dashboard building blocks (P18). Everything here loads only with the dashboard screens (lazy routes),
 * never in the first download. Colours come from our tokens (index.css --chart-*, room status colours).
 */

/** Series colours: 1 = the brand colour; the others differ in hue and lightness (light and dark tokens). */
export const SERIES = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)'] as const

/** Room status colours keep their meaning everywhere (same as the room board). */
export const ROOM_COLORS: Record<string, string> = {
  occupied: 'var(--room-occupied)',
  available: 'var(--room-available)',
  dirty: 'var(--room-dirty)',
  cleaning: 'var(--room-clean)',
  maintenance: 'var(--room-maintenance)',
  out_of_order: 'var(--room-maintenance)',
}

const motionQuery = '(prefers-reduced-motion: reduce)'

/** True when the person asked for less motion: charts then draw without animation. */
export function useReducedMotion(): boolean {
  return useSyncExternalStore(
    (cb) => {
      const m = window.matchMedia?.(motionQuery)
      m?.addEventListener?.('change', cb)
      return () => m?.removeEventListener?.('change', cb)
    },
    () => window.matchMedia?.(motionQuery).matches ?? false,
    () => false,
  )
}

export type WidgetState = 'loading' | 'error' | 'empty' | 'ready'

/** The state of a widget from its query (and whether it has anything to show). */
export function widgetState(q: { isPending: boolean; isError: boolean }, hasData: boolean): WidgetState {
  if (q.isError) return 'error'
  if (q.isPending) return 'loading'
  return hasData ? 'ready' : 'empty'
}

/** One card on the dashboard: title, optional actions, and its own loading / empty / error state. */
export function ChartCard({
  id,
  title,
  description,
  action,
  state = 'ready',
  onRetry,
  emptyText,
  footer,
  className,
  bodyClassName,
  children,
}: {
  id?: string
  title: ReactNode
  description?: ReactNode
  action?: ReactNode
  state?: WidgetState
  onRetry?: () => void
  emptyText?: string
  footer?: ReactNode
  className?: string
  bodyClassName?: string
  children?: ReactNode
}) {
  const { t } = useTranslation()
  return (
    <section id={id} className={cn('panel flex min-w-0 scroll-mt-4 flex-col', className)}>
      <div className="flex flex-wrap items-start gap-2 px-4 pt-4">
        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
          <h2 className="text-[15px] leading-tight font-semibold">{title}</h2>
          {description && <p className="text-xs text-muted">{description}</p>}
        </div>
        {action}
      </div>
      <div className={cn('flex min-w-0 flex-1 flex-col p-4', bodyClassName)}>
        {state === 'loading' && <div aria-hidden className="h-full min-h-32 animate-pulse rounded-lg bg-sunken motion-reduce:animate-none" />}
        {state === 'loading' && <span className="sr-only">{t('common.loading')}</span>}
        {state === 'error' && (
          <div role="alert" className="flex min-h-32 flex-col items-center justify-center gap-2 text-center">
            <p className="text-sm text-muted">{t('dash.widgetError')}</p>
            {onRetry && (
              <Button variant="secondary" onClick={onRetry}>
                <RefreshCw aria-hidden />
                {t('loadFailed.retry')}
              </Button>
            )}
          </div>
        )}
        {state === 'empty' && <p className="flex min-h-24 items-center justify-center text-center text-sm text-muted">{emptyText ?? t('dash.empty')}</p>}
        {state === 'ready' && children}
      </div>
      {footer && state === 'ready' && <div className="border-t border-border px-4 py-3 text-sm">{footer}</div>}
    </section>
  )
}

/** One failing widget never breaks the page: it shows its own error card instead. */
export class WidgetBoundary extends Component<{ title: ReactNode; children: ReactNode; className?: string }, { failed: boolean }> {
  state = { failed: false }

  static getDerivedStateFromError() {
    return { failed: true }
  }

  componentDidCatch(error: unknown) {
    console.error(error)
  }

  render() {
    if (this.state.failed) return <ChartCard title={this.props.title} state="error" className={this.props.className} onRetry={() => this.setState({ failed: false })} />
    return this.props.children
  }
}

/** Colour square + label + value, used under donut and bar charts (also readable without colour). */
export function Legend({ items, onSelect }: { items: { key: string; label: string; value: string; sub?: string; color: string }[]; onSelect?: (key: string) => void }) {
  return (
    <ul className="flex min-w-0 flex-1 flex-col gap-1.5 text-sm">
      {items.map((i) => {
        const body = (
          <>
            <span aria-hidden className="size-2.5 shrink-0 rounded-[3px]" style={{ background: i.color }} />
            <span className="min-w-0 flex-1 truncate">{i.label}</span>
            <span className="font-semibold tabular-nums">{i.value}</span>
          </>
        )
        return (
          <li key={i.key} className="flex flex-col">
            {onSelect ? (
              <button type="button" onClick={() => onSelect(i.key)} className="tap-link flex items-center gap-2 rounded-sm text-left hover:bg-sunken/60">
                {body}
              </button>
            ) : (
              <span className="flex items-center gap-2">{body}</span>
            )}
            {i.sub && <span className="ps-[18px] text-xs text-muted">{i.sub}</span>}
          </li>
        )
      })}
    </ul>
  )
}

/** Horizontal bars without a chart library (sources, room types on phones…). */
export function BarList({ items, onSelect, color = 'var(--chart-1)' }: { items: { key: string; label: string; value: number; display: string; sub?: string }[]; onSelect?: (key: string) => void; color?: string }) {
  const max = Math.max(1, ...items.map((i) => i.value))
  return (
    <ul className="flex flex-col gap-3">
      {items.map((i) => {
        const body = (
          <>
            <span className="flex w-full items-baseline justify-between gap-2 text-sm">
              <span className="min-w-0 truncate">
                {i.label}
                {i.sub && <span className="text-muted"> · {i.sub}</span>}
              </span>
              <span className="font-semibold tabular-nums">{i.display}</span>
            </span>
            <span className="block h-2.5 w-full rounded-full bg-sunken">
              <span className="block h-2.5 rounded-full transition-[width] duration-200 motion-reduce:transition-none" style={{ width: `${Math.max(2, (i.value / max) * 100)}%`, background: color }} />
            </span>
          </>
        )
        return (
          <li key={i.key}>
            {onSelect ? (
              <button type="button" onClick={() => onSelect(i.key)} className="flex w-full flex-col gap-1.5 rounded-sm text-left">
                {body}
              </button>
            ) : (
              <div className="flex flex-col gap-1.5">{body}</div>
            )}
          </li>
        )
      })}
    </ul>
  )
}
