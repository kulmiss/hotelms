import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react'
import { useNavigate } from 'react-router'
import { useTranslation } from 'react-i18next'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { BuildingOffice2Icon, CalendarDaysIcon, Cog6ToothIcon, DocumentTextIcon, HomeModernIcon, MagnifyingGlassIcon, SparklesIcon, UserIcon, UsersIcon } from '@heroicons/react/24/outline'
import { api } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { cn } from '@/lib/cn'
import { searchSettings, settingsLink, useSettingsSections } from '@/lib/settingsSearch'
import { useDebounced } from '@/lib/useDebounced'

type Hit = { type: 'answer' | 'booking' | 'guest' | 'room' | 'bill' | 'hotel' | 'person' | 'setting'; title: string; subtitle: string; link: string }
const MAC = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.userAgent)
const ICONS = { setting: Cog6ToothIcon, answer: SparklesIcon, booking: CalendarDaysIcon, guest: UserIcon, room: HomeModernIcon, bill: DocumentTextIcon, hotel: BuildingOffice2Icon, person: UsersIcon }

/** The search box in the top bar: guests, bookings, rooms and bills (Ctrl+K / ⌘K or "/" to jump here). */
export function GlobalSearch() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const me = useMe()
  const [q, setQ] = useState('')
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0)
  const term = useDebounced(q.trim(), 250)
  const input = useRef<HTMLInputElement>(null)
  const box = useRef<HTMLDivElement>(null)
  const listId = useId()
  const admin = Boolean(me.data?.user.is_super_admin && !me.data?.hotel)

  const res = useQuery({
    queryKey: ['search', term],
    queryFn: () => api<{ data: Hit[] }>(`/search?q=${encodeURIComponent(term)}`),
    enabled: term.length >= 2,
    placeholderData: keepPreviousData,
    staleTime: 15_000,
  })
  // Settings are found on this device (no request): the first few matches come after the server's results.
  const sections = useSettingsSections().map((s) => s.key)
  const settingHits: Hit[] = admin
    ? []
    : searchSettings(t, sections, term, 3).map((h) => ({ type: 'setting', title: h.label, subtitle: h.find ? t(`settings.sections.${h.section}`) : '', link: settingsLink(h) }))
  const hits = term.length >= 2 ? [...(res.data?.data ?? []), ...settingHits] : []

  useEffect(() => {
    const onKey = (e: globalThis.KeyboardEvent) => {
      const typing = e.target instanceof HTMLElement && e.target.closest('input, textarea, select, [contenteditable="true"]')
      if (((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') || (e.key === '/' && !typing)) {
        e.preventDefault()
        input.current?.focus()
        input.current?.select()
      }
    }
    const onDown = (e: PointerEvent) => box.current && !box.current.contains(e.target as Node) && setOpen(false)
    document.addEventListener('keydown', onKey)
    document.addEventListener('pointerdown', onDown)
    return () => {
      document.removeEventListener('keydown', onKey)
      document.removeEventListener('pointerdown', onDown)
    }
  }, [])

  const go = (hit: Hit) => {
    setOpen(false)
    setQ('')
    input.current?.blur()
    navigate(hit.link)
  }

  const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      setOpen(true)
      setActive((a) => Math.min(a + 1, hits.length - 1))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setActive((a) => Math.max(a - 1, 0))
    } else if (e.key === 'Enter' && hits[active]) {
      e.preventDefault()
      go(hits[active])
    } else if (e.key === 'Escape') {
      setOpen(false)
      input.current?.blur()
    }
  }

  const show = open && term.length >= 2

  return (
    <div ref={box} className="relative w-full max-w-xs">
      <label className="flex min-h-touch items-center gap-x-2 rounded-lg bg-sunken px-3 ring-1 ring-transparent transition duration-75 focus-within:ring-2 focus-within:ring-accent">
        <MagnifyingGlassIcon aria-hidden className="size-4 shrink-0 text-icon" />
        <span className="sr-only">{admin ? t('search.adminLabel') : t('topbar.search')}</span>
        <input
          ref={input}
          type="search"
          role="combobox"
          aria-expanded={show}
          aria-controls={listId}
          aria-autocomplete="list"
          aria-activedescendant={show && hits[active] ? `${listId}-${active}` : undefined}
          value={q}
          onChange={(e) => {
            setQ(e.target.value)
            setActive(0)
            setOpen(true)
          }}
          onFocus={() => setOpen(true)}
          onKeyDown={onKeyDown}
          placeholder={admin ? t('search.adminLabel') : t('topbar.search')}
          className="w-full bg-transparent text-base text-text placeholder:text-icon focus:outline-none sm:text-sm"
        />
        {/* The shortcut as two small keys (⌘ K on a Mac), hidden while typing. */}
        {!q && (
          <span aria-hidden className="hidden shrink-0 items-center gap-1 whitespace-nowrap lg:flex">
            {[MAC ? '⌘' : 'Ctrl', 'K'].map((k) => (
              <kbd key={k} className="flex h-5 min-w-5 items-center justify-center rounded-md bg-sunken px-1.5 font-sans text-[11px] leading-none font-medium text-muted shadow-[inset_0_-1px_0_var(--color-ring-strong)] ring-1 ring-ring">
                {k}
              </kbd>
            ))}
          </span>
        )}
      </label>
      {show && (
        <div className="panel absolute top-full right-0 left-0 z-40 mt-2 w-[min(28rem,calc(100vw-2rem))] p-1.5 animate-page-in">
          <ul id={listId} role="listbox" aria-label={t('search.results')} className="flex max-h-96 flex-col overflow-y-auto">
            {hits.map((h, i) => {
              const Icon = ICONS[h.type]
              return (
                <li
                  key={`${h.type}-${h.link}`}
                  id={`${listId}-${i}`}
                  role="option"
                  aria-selected={i === active}
                  onPointerEnter={() => setActive(i)}
                  onClick={() => go(h)}
                  className={cn('flex min-h-touch cursor-pointer items-center gap-3 rounded-md px-2 py-1.5', i === active && 'bg-sunken')}
                >
                  <Icon aria-hidden className="size-5 shrink-0 text-icon" />
                  <span className="flex min-w-0 flex-col">
                    <span className="truncate text-[13px] font-medium">{h.title}</span>
                    <span className="truncate text-xs text-muted">
                      {t(`search.types.${h.type}`)}
                      {h.subtitle && ` · ${h.subtitle}`}
                    </span>
                  </span>
                </li>
              )
            })}
          </ul>
          {!res.isFetching && hits.length === 0 && <p className="px-2 py-4 text-center text-[13px] text-muted">{t('search.none', { q: term })}</p>}
          {res.isFetching && hits.length === 0 && <p className="px-2 py-4 text-center text-[13px] text-muted">{t('common.loading')}</p>}
        </div>
      )}
    </div>
  )
}
