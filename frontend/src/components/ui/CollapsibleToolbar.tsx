import { createContext, useContext, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { SlidersHorizontal, X } from 'lucide-react'
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/shadcn/collapsible'
import { Button } from '@/components/ui/Button'
import { cn } from '@/lib/cn'
import { useCompactToolbar } from '@/lib/compactToolbar'

/**
 * Filters that fold away on phones and tablets (owner, P19): below 1024px the filter, sort and other toolbar
 * controls sit behind one "Filters" button, closed at first, so the rooms or the list show first. The search box
 * stays in view, the button shows how many filters are on, and each one is a small chip with an × to remove it.
 * From 1024px the controls show as before (no button).
 *
 * Open or closed is remembered per page for this browser session only (sessionStorage): a new session starts closed.
 * Only the look changes: the page keeps its own filter state, URLs and query parameters.
 */
/** A filter that is on. Without `onRemove` it is shown for information only (e.g. a report's period) and not counted. */
export type ActiveFilter = { key: string; label: string; onRemove?: () => void }

const SearchContext = createContext<{ search: ReactNode; compact: boolean }>({ search: null, compact: false })

/** Where the search box sits on desktop. On phones and tablets it moves above the Filters button. */
export function ToolbarSearch() {
  const { search, compact } = useContext(SearchContext)
  return compact ? null : <>{search}</>
}

const storeKey = (id: string) => `hms-filters-open-${id}`

function loadOpen(id: string): boolean {
  try {
    return sessionStorage.getItem(storeKey(id)) === '1'
  } catch {
    return false // storage can be blocked
  }
}

function saveOpen(id: string, open: boolean) {
  try {
    sessionStorage.setItem(storeKey(id), open ? '1' : '0')
  } catch {
    /* storage can be blocked */
  }
}

const FOCUSABLE = 'input:not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'

type Props = {
  /** Remembers open / closed for this page (session only). */
  id: string
  /** The search box: always in view. On desktop it shows where <ToolbarSearch /> is placed in `children`. */
  search?: ReactNode
  /** Filters that are on (not the defaults): the count on the button and the removable chips. */
  active?: ActiveFilter[]
  /** Resets every filter (the page's own "clear"). Shown as "Clear all" when a filter is on. */
  onClearAll?: () => void
  /** Extra controls that fold away too on phones and tablets (e.g. the table's Excel and Columns buttons). */
  tools?: ReactNode
  /** Buttons that never fold away: next to the Filters button on phones and tablets, after the filters on desktop (e.g. Refresh). */
  actions?: ReactNode
  /** The filter and sort controls, laid out as on desktop. */
  children: ReactNode
  className?: string
}

export function CollapsibleToolbar({ id, search, active = [], onClearAll, tools, actions, children, className }: Props) {
  const compact = useCompactToolbar()

  if (!compact) {
    return (
      <SearchContext.Provider value={{ search, compact: false }}>
        {children}
        {actions}
      </SearchContext.Provider>
    )
  }
  return (
    <SearchContext.Provider value={{ search, compact: true }}>
      <Compact id={id} search={search} active={active} onClearAll={onClearAll} tools={tools} actions={actions} className={className}>
        {children}
      </Compact>
    </SearchContext.Provider>
  )
}

function Compact({ id, search, active = [], onClearAll, tools, actions, children, className }: Props) {
  const { t } = useTranslation()
  const count = active.filter((f) => f.onRemove).length
  const [open, setOpenState] = useState(() => loadOpen(id))
  const trigger = useRef<HTMLButtonElement>(null)
  const content = useRef<HTMLDivElement>(null)
  const chips = useRef<HTMLUListElement>(null)
  const byKeyboard = useRef(false)

  const setOpen = (next: boolean) => {
    setOpenState(next)
    saveOpen(id, next)
    // Opened with the keyboard: go to the first control. (Not with a tap: a phone would pop up its keyboard.)
    if (next && byKeyboard.current) requestAnimationFrame(() => content.current?.querySelector<HTMLElement>(FOCUSABLE)?.focus())
    byKeyboard.current = false
  }

  // Escape inside the open controls: close them and go back to the button.
  const onKeyDown = (e: KeyboardEvent) => {
    if (e.key !== 'Escape' || !open) return
    e.stopPropagation()
    setOpen(false)
    trigger.current?.focus()
  }

  // After an × or "Clear all", focus goes to the next chip, else to the Filters button (never lost).
  const refocus = (index: number) =>
    requestAnimationFrame(() => {
      const buttons = chips.current?.querySelectorAll<HTMLButtonElement>('[data-chip-remove]')
      const next = buttons && buttons.length > 0 ? buttons[Math.min(index, buttons.length - 1)] : null
      ;(next ?? trigger.current)?.focus()
    })

  return (
    <Collapsible open={open} onOpenChange={setOpen} data-slot="collapsible-toolbar" className={cn('flex flex-col gap-2', className)}>
      <div className="flex items-center gap-2">
        {search && <div className="min-w-0 flex-1">{search}</div>}
        <CollapsibleTrigger asChild>
          <Button
            ref={trigger}
            variant="secondary"
            className="shrink-0 px-3"
            onPointerDown={() => {
              byKeyboard.current = false
            }}
            onKeyDown={(e) => {
              if (e.key === 'Enter' || e.key === ' ') byKeyboard.current = true
            }}
          >
            <SlidersHorizontal aria-hidden />
            {t('filters.button')}
            {count > 0 && (
              <span className="flex min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-xs leading-5 font-semibold text-primary-foreground tabular-nums">
                {count}
              </span>
            )}
          </Button>
        </CollapsibleTrigger>
        {actions}
      </div>

      {active.length > 0 && (
        <div className="flex flex-wrap items-center gap-2">
          <ul ref={chips} aria-label={t('filters.active')} className="flex flex-wrap items-center gap-2">
            {active.map((f, i) => (
              <li key={f.key} className={cn('flex h-8 items-center gap-1 rounded-full border bg-sunken ps-3 text-xs font-medium text-text pointer-coarse:h-11', f.onRemove ? 'pe-1 pointer-coarse:pe-0' : 'pe-3')}>
                <span className="max-w-[14rem] truncate">{f.label}</span>
                {f.onRemove && (
                  <button
                    type="button"
                    data-chip-remove
                    aria-label={t('filters.remove', { label: f.label })}
                    onClick={() => {
                      f.onRemove?.()
                      refocus(i)
                    }}
                    className="flex size-6 items-center justify-center rounded-full text-muted transition-colors outline-none hover:bg-background hover:text-text focus-visible:ring-[3px] focus-visible:ring-focus/50 pointer-coarse:size-11"
                  >
                    <X aria-hidden className="size-3.5" />
                  </button>
                )}
              </li>
            ))}
          </ul>
          {onClearAll && count > 0 && (
            <button
              type="button"
              onClick={() => {
                onClearAll()
                refocus(0)
              }}
              className="min-h-8 rounded-md px-2 text-sm font-medium text-accent-text underline-offset-4 outline-none hover:underline focus-visible:ring-[3px] focus-visible:ring-focus/50 pointer-coarse:min-h-11"
            >
              {t('filters.clearAll')}
            </button>
          )}
        </div>
      )}

      <CollapsibleContent ref={content} aria-label={t('filters.button')} role="group" onKeyDown={onKeyDown}>
        <div className="flex flex-col gap-3">
          {children}
          {tools}
        </div>
      </CollapsibleContent>
    </Collapsible>
  )
}
