import { ArrowDown, ArrowUp } from 'lucide-react'
import { cn } from '@/lib/cn'

/** ▲/▼ change vs the compared period: green when it is good (for "lower is better" pass invert). */
export function Delta({ value, invert = false }: { value: number | null; invert?: boolean }) {
  if (value === null) return <span className="text-xs text-muted">—</span>
  const good = invert ? value < 0 : value > 0
  const Icon = value >= 0 ? ArrowUp : ArrowDown
  return (
    <span className={cn('inline-flex items-center gap-0.5 text-xs font-medium tabular-nums', value === 0 ? 'text-muted' : good ? 'text-ok-text' : 'text-danger-text')}>
      <Icon aria-hidden className="size-3" />
      {Math.abs(value)}%
    </span>
  )
}

/** Simple bar chart (CSS only, no chart library): one bar per item, tap a bar to open it. */
export function Bars({
  items,
  format = (v) => String(v),
  onSelect,
  selected,
  label,
}: {
  items: { key: string; label: string; value: number }[]
  format?: (v: number) => string
  onSelect?: (key: string) => void
  selected?: string | null
  label: string
}) {
  const max = Math.max(1, ...items.map((i) => i.value))
  return (
    <div role="list" aria-label={label} className="flex h-44 items-end gap-[3px]">
      {items.map((i) => (
        <button
          key={i.key}
          type="button"
          role="listitem"
          title={`${i.label}: ${format(i.value)}`}
          aria-label={`${i.label}: ${format(i.value)}`}
          onClick={() => onSelect?.(i.key)}
          className="group flex h-full min-w-0 flex-1 flex-col justify-end"
        >
          <span
            className={cn('block w-full rounded-t-[4px] transition-all duration-150', selected === i.key ? 'bg-primary' : 'bg-primary/45 group-hover:bg-primary/70')}
            style={{ height: `${Math.max(2, (i.value / max) * 100)}%` }}
          />
        </button>
      ))}
    </div>
  )
}
