import { useRef, type KeyboardEvent, type ReactNode } from 'react'
import { CheckCircle, WarningCircle } from '@phosphor-icons/react'
import { cn } from '@/lib/cn'

export type TabItem = { key: string; label: string; state?: 'done' | 'error' }

/** Accessible tabs (arrow keys move between them), touch-sized. */
export function Tabs({
  items,
  active,
  onChange,
  label,
}: {
  items: TabItem[]
  active: string
  onChange: (key: string) => void
  label: string
}) {
  const refs = useRef<Record<string, HTMLButtonElement | null>>({})

  function onKey(e: KeyboardEvent, index: number) {
    const delta = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0
    if (!delta) return
    e.preventDefault()
    const next = items[(index + delta + items.length) % items.length]
    onChange(next.key)
    refs.current[next.key]?.focus()
  }

  return (
    <div role="tablist" aria-label={label} className="flex gap-1 overflow-x-auto rounded-2xl bg-sunken p-1">
      {items.map((item, i) => {
        const selected = item.key === active
        return (
          <button
            key={item.key}
            ref={(el) => {
              refs.current[item.key] = el
            }}
            role="tab"
            type="button"
            id={`tab-${item.key}`}
            aria-selected={selected}
            aria-controls={`panel-${item.key}`}
            tabIndex={selected ? 0 : -1}
            onClick={() => onChange(item.key)}
            onKeyDown={(e) => onKey(e, i)}
            className={cn(
              'flex min-h-touch flex-1 items-center justify-center gap-2 rounded-xl px-4 text-sm font-semibold whitespace-nowrap transition-[background-color,color,box-shadow] duration-200',
              selected ? 'bg-surface text-text shadow-soft' : 'text-muted hover:text-text',
            )}
          >
            <span
              className={cn(
                'flex size-6 items-center justify-center rounded-full text-xs',
                selected ? 'bg-accent text-on-accent' : 'bg-surface text-muted',
              )}
            >
              {i + 1}
            </span>
            {item.label}
            {item.state === 'done' && <CheckCircle aria-label="" weight="fill" className="size-5 text-ok-text" />}
            {item.state === 'error' && <WarningCircle aria-label="" weight="fill" className="size-5 text-danger-text" />}
          </button>
        )
      })}
    </div>
  )
}

export function TabPanel({ id, active, children }: { id: string; active: string; children: ReactNode }) {
  if (id !== active) return null
  return (
    <div role="tabpanel" id={`panel-${id}`} aria-labelledby={`tab-${id}`} className="animate-page-in motion-reduce:animate-none">
      {children}
    </div>
  )
}
