import { lazy, Suspense, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { SearchIcon } from 'lucide-react'
import { Button } from '@/components/shadcn/button'
import { useMe } from '@/lib/auth'
import { cn } from '@/lib/cn'
import { useCan } from '@/lib/hotel'
import { useSettingsSections } from '@/lib/settingsSearch'

// The command menu (and cmdk) is downloaded only when someone opens it, or hovers the search button.
const loadMenu = () => import('./CommandMenu')
const CommandMenu = lazy(() => loadMenu().then((m) => ({ default: m.CommandMenu })))

const MAC = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.userAgent)

/** Search button in the header (template): opens the command menu. Ctrl+K / ⌘K or "/" open it from anywhere. */
export function Search({ className }: { className?: string }) {
  const { t } = useTranslation()
  const me = useMe()
  const can = useCan()
  const sections = useSettingsSections()
  const [open, setOpen] = useState(false)
  const [loaded, setLoaded] = useState(false)
  const admin = Boolean(me.data?.user.is_super_admin && !me.data?.hotel)
  // Nothing to find for this role (e.g. Restaurant / Bar): no search at all.
  const allowed = admin || sections.length > 0 || can(['reservations.view', 'guests.view', 'frontdesk.view', 'invoices.view'])

  const show = () => {
    setLoaded(true)
    setOpen(true)
  }

  useEffect(() => {
    if (!allowed) return
    const onKey = (e: KeyboardEvent) => {
      const typing = e.target instanceof HTMLElement && e.target.closest('input, textarea, select, [contenteditable="true"]')
      if (((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') || (e.key === '/' && !typing)) {
        e.preventDefault()
        setLoaded(true)
        setOpen((o) => !o)
      }
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [allowed])

  if (!allowed) return null
  const placeholder = admin ? t('search.adminLabel') : t('topbar.search')

  return (
    <>
      <Button
        variant="outline"
        aria-label={placeholder}
        aria-keyshortcuts="Control+K Meta+K"
        aria-haspopup="dialog"
        onClick={show}
        onPointerEnter={() => void loadMenu()}
        onFocus={() => void loadMenu()}
        className={cn(
          'group relative h-8 w-full flex-1 justify-start rounded-md bg-sunken/25 text-sm font-normal text-muted shadow-none hover:bg-sunken sm:w-40 sm:pe-12 md:flex-none lg:w-52 xl:w-64 pointer-coarse:h-11',
          className,
        )}
      >
        <SearchIcon aria-hidden className="absolute inset-s-1.5 top-1/2 size-4 -translate-y-1/2" />
        <span className="ms-4 truncate">{placeholder}</span>
        <kbd aria-hidden className="pointer-events-none absolute inset-e-[0.3rem] top-1/2 hidden h-5 -translate-y-1/2 items-center gap-1 rounded border bg-sunken px-1.5 font-mono text-[10px] font-medium opacity-100 select-none group-hover:bg-surface sm:flex">
          {MAC ? <span className="text-xs">⌘</span> : 'Ctrl'}K
        </kbd>
      </Button>
      {loaded && (
        <Suspense fallback={null}>
          <CommandMenu open={open} onOpenChange={setOpen} admin={admin} placeholder={placeholder} />
        </Suspense>
      )}
    </>
  )
}
