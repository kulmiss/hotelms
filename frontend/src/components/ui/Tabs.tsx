import { useRef, type KeyboardEvent, type ReactNode } from 'react'
import { CircleAlert, CircleCheck } from 'lucide-react'
import { cn } from '@/lib/cn'

export type TabItem = { key: string; label: string; state?: 'done' | 'error' }

/** The template's tabs (grey track, the open tab on a white chip); arrow keys move between them; 44px on touch. */
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
    <div
      role="tablist"
      aria-label={label}
      data-slot="tabs-list"
      className="no-scrollbar inline-flex h-9 w-fit max-w-full items-center justify-start overflow-x-auto rounded-lg bg-sunken p-[3px] text-muted pointer-coarse:h-12"
    >
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
            data-state={selected ? 'active' : 'inactive'}
            className={cn(
              'inline-flex h-[calc(100%-1px)] flex-1 items-center justify-center gap-1.5 rounded-md border border-transparent px-3 py-1 text-sm font-medium whitespace-nowrap text-text outline-none transition-[color,box-shadow] focus-visible:border-focus focus-visible:ring-[3px] focus-visible:ring-focus/50 dark:text-muted',
              selected ? 'bg-background shadow-sm dark:border-input dark:bg-input/30 dark:text-text' : 'hover:text-text',
            )}
          >
            {item.label}
            {item.state === 'done' && <CircleCheck aria-hidden className="size-4 text-ok-text" />}
            {item.state === 'error' && <CircleAlert aria-hidden className="size-4 text-danger-text" />}
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
