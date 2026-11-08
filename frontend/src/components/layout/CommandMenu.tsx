import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router'
import { useTranslation } from 'react-i18next'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { ArrowRight, Building2, CalendarDays, FileText, Hotel, Laptop, Moon, Settings, Sparkles, Sun, User, Users } from 'lucide-react'
import { adminNav, mainNav, NAV_MODULES } from '@/app/navigation'
import { Command, CommandDialog, commandDialogClass, CommandGroup, CommandInput, CommandItem, CommandList, CommandSeparator } from '@/components/shadcn/command'
import { api } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { useCan } from '@/lib/hotel'
import { searchSettings, settingsLink, useSettingsSections } from '@/lib/settingsSearch'
import { useTheme, type ThemeChoice } from '@/lib/theme'
import { useDebounced } from '@/lib/useDebounced'

type Hit = { type: 'answer' | 'booking' | 'guest' | 'room' | 'bill' | 'hotel' | 'person' | 'setting'; title: string; subtitle: string; link: string }
const ICONS = { setting: Settings, answer: Sparkles, booking: CalendarDays, guest: User, room: Hotel, bill: FileText, hotel: Building2, person: Users }
const THEMES: { choice: ThemeChoice; icon: typeof Sun }[] = [
  { choice: 'light', icon: Sun },
  { choice: 'dark', icon: Moon },
  { choice: 'system', icon: Laptop },
]

type Props = { open: boolean; onOpenChange: (open: boolean) => void; admin: boolean; placeholder: string }

/**
 * The command menu (template, cmdk): guests, bookings, rooms and bills from the server, matching settings
 * (found on this device), the pages this person may open, and the theme. Loaded only when first opened.
 */
export function CommandMenu({ open, onOpenChange, admin, placeholder }: Props) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const me = useMe()
  const can = useCan()
  const { setChoice } = useTheme()
  const [q, setQ] = useState('')
  const term = useDebounced(q.trim(), 250)
  const typed = q.trim()

  const res = useQuery({
    queryKey: ['search', term],
    queryFn: () => api<{ data: Hit[] }>(`/search?q=${encodeURIComponent(term)}`),
    enabled: open && term.length >= 2,
    placeholderData: keepPreviousData,
    staleTime: 15_000,
  })
  const sections = useSettingsSections().map((s) => s.key)
  const settingHits: Hit[] = admin
    ? []
    : searchSettings(t, sections, term, 3).map((h) => ({ type: 'setting', title: h.label, subtitle: h.find ? t(`settings.sections.${h.section}`) : '', link: settingsLink(h) }))
  const hits = term.length >= 2 ? [...(res.data?.data ?? []), ...settingHits] : []

  // Enter opens the first search result as soon as the results arrive (even if a page name matched first).
  const [selected, setSelected] = useState('')
  const firstHit = hits[0] ? `${hits[0].type}-${hits[0].link}` : ''
  useEffect(() => {
    if (firstHit) setSelected(firstHit)
  }, [firstHit])

  // Pages from the menu (same rules as the sidebar), filtered by what is typed.
  const modules = me.data?.hotel?.modules ?? []
  const pages = (
    me.data?.user.is_super_admin
      ? adminNav.filter((item) => !item.permission || [item.permission].flat().some((p) => me.data?.platform?.permissions.includes(p)))
      : mainNav.filter((item) => can(item.permission) && (!item.module || modules.includes(NAV_MODULES[item.module])))
  )
    .map((item) => ({ ...item, label: t(`nav.${item.key}`) }))
    .filter((item) => !typed || item.label.toLowerCase().includes(typed.toLowerCase()))

  const run = (action: () => void) => {
    onOpenChange(false)
    setQ('')
    action()
  }

  const searching = term.length >= 2
  const nothing = searching && !res.isFetching && hits.length === 0 && pages.length === 0

  return (
    <CommandDialog open={open} onOpenChange={onOpenChange} title={placeholder} description={t('search.description')}>
      {/* The server finds the results; cmdk only handles the keyboard. */}
      <Command shouldFilter={false} label={placeholder} value={selected} onValueChange={setSelected} className={commandDialogClass}>
        <CommandInput value={q} onValueChange={setQ} placeholder={placeholder} />
        <CommandList className="max-h-[min(24rem,60svh)]">
          {searching && hits.length > 0 && (
            <CommandGroup heading={t('search.results')}>
              {hits.map((h) => {
                const Icon = ICONS[h.type]
                return (
                  <CommandItem key={`${h.type}-${h.link}`} value={`${h.type}-${h.link}`} onSelect={() => run(() => navigate(h.link))}>
                    <Icon aria-hidden />
                    <span className="flex min-w-0 flex-col">
                      <span className="truncate font-medium">{h.title}</span>
                      <span className="truncate text-xs text-muted">
                        {t(`search.types.${h.type}`)}
                        {h.subtitle && ` · ${h.subtitle}`}
                      </span>
                    </span>
                  </CommandItem>
                )
              })}
            </CommandGroup>
          )}
          {searching && res.isFetching && hits.length === 0 && <p className="py-6 text-center text-sm text-muted">{t('common.loading')}</p>}
          {nothing && <p className="py-6 text-center text-sm text-muted">{t('search.none', { q: term })}</p>}
          {pages.length > 0 && (
            <CommandGroup heading={t('search.pages')}>
              {pages.map((item) => (
                <CommandItem key={item.path} value={`page-${item.path}`} onSelect={() => run(() => navigate(item.path))}>
                  <div className="flex size-4 items-center justify-center">
                    <ArrowRight aria-hidden className="size-2 text-muted/80" />
                  </div>
                  {item.label}
                </CommandItem>
              ))}
            </CommandGroup>
          )}
          {!typed && (
            <>
              <CommandSeparator />
              <CommandGroup heading={t('topbar.theme')}>
                {THEMES.map(({ choice, icon: Icon }) => (
                  <CommandItem key={choice} value={`theme-${choice}`} onSelect={() => run(() => setChoice(choice))}>
                    <Icon aria-hidden />
                    <span>{t(`topbar.themes.${choice}`)}</span>
                  </CommandItem>
                ))}
              </CommandGroup>
            </>
          )}
        </CommandList>
      </Command>
    </CommandDialog>
  )
}
