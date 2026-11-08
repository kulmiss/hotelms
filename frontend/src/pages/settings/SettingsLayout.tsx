import { useEffect, useId, useState } from 'react'
import { NavLink, Navigate, Outlet, useLocation, useNavigate, useSearchParams } from 'react-router'
import { SearchIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { PageHeader } from '@/components/ui/PageHeader'
import { cn } from '@/lib/cn'
import { searchSettings, settingsLink, useSettingsSections, type SettingsHit } from '@/lib/settingsSearch'

/** Hotel settings: sections on the left (with a search box), the open section on the right. */
export function SettingsLayout() {
  const { t } = useTranslation()
  const { pathname } = useLocation()
  const sections = useSettingsSections()

  // Open the first allowed section; never show a section the role may not use.
  const current = pathname.replace(/\/$/, '').split('/')[2]
  if (!current || !sections.some((s) => s.key === current)) {
    return sections[0] ? <Navigate to={`/settings/${sections[0].key}`} replace /> : <Navigate to="/" replace />
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader title={t('nav.settings')} subtitle={t('settings.subtitle')} />
      <div className="flex flex-col gap-6 lg:flex-row">
        {/* 15rem: wide enough for the whole search hint ("Search settings, e.g. VAT"). */}
        <nav aria-label={t('nav.settings')} className="flex flex-col gap-3 lg:w-60 lg:shrink-0">
          <SettingsSearch allowed={sections.map((s) => s.key)} />
          <ul className="no-scrollbar flex gap-1 overflow-x-auto lg:flex-col">
            {sections.map((s) => (
              <li key={s.key}>
                <NavLink
                  to={`/settings/${s.key}`}
                  className={({ isActive }) =>
                    cn(
                      // The template's settings side menu: ghost buttons, the open one grey.
                      'flex min-h-touch items-center justify-start rounded-md px-3 text-sm font-medium whitespace-nowrap transition-colors',
                      isActive ? 'bg-sunken text-text' : 'text-muted hover:bg-sunken hover:text-text',
                    )
                  }
                >
                  {t(`settings.sections.${s.key}`)}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>
        <div className="min-w-0 flex-1">
          <Outlet />
          <FindHighlight />
        </div>
      </div>
    </div>
  )
}

/** Search box above the sections: finds a section by its name or by a setting inside it. */
function SettingsSearch({ allowed }: { allowed: string[] }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [q, setQ] = useState('')
  const [active, setActive] = useState(0)
  const hits = searchSettings(t, allowed, q)
  const listId = useId()

  const go = (h: SettingsHit) => {
    setQ('')
    navigate(settingsLink(h))
  }

  return (
    <div className="relative">
      <label className="flex min-h-touch items-center gap-2 rounded-md border border-input bg-transparent px-3 shadow-xs transition-[color,box-shadow] focus-within:border-focus focus-within:ring-[3px] focus-within:ring-focus/50 dark:bg-input/30">
        <SearchIcon aria-hidden className="size-4 shrink-0 opacity-50" />
        <input
          type="search"
          role="combobox"
          aria-expanded={hits.length > 0}
          aria-controls={listId}
          aria-label={t('settings.search.label')}
          placeholder={t('settings.search.placeholder')}
          value={q}
          onChange={(e) => {
            setQ(e.target.value)
            setActive(0)
          }}
          onKeyDown={(e) => {
            if (e.key === 'ArrowDown') {
              e.preventDefault()
              setActive((a) => Math.min(a + 1, hits.length - 1))
            } else if (e.key === 'ArrowUp') {
              e.preventDefault()
              setActive((a) => Math.max(a - 1, 0))
            } else if (e.key === 'Enter' && hits[active]) {
              e.preventDefault()
              go(hits[active])
            } else if (e.key === 'Escape') setQ('')
          }}
          className="min-w-0 flex-1 bg-transparent text-sm outline-none"
        />
      </label>
      {q.trim().length >= 2 && (
        <div className="panel absolute top-full right-0 left-0 z-30 mt-1.5 p-1.5 animate-page-in lg:w-80">
          {hits.length ? (
            <ul id={listId} role="listbox" aria-label={t('settings.search.label')} className="flex flex-col">
              {hits.map((h, i) => (
                <li
                  key={`${h.section}-${h.label}`}
                  role="option"
                  aria-selected={i === active}
                  onPointerEnter={() => setActive(i)}
                  onClick={() => go(h)}
                  className={cn('flex min-h-touch cursor-pointer flex-col justify-center rounded-md px-2 py-1', i === active && 'bg-sunken')}
                >
                  <span className="truncate text-sm font-medium">{h.label}</span>
                  {h.find && <span className="truncate text-xs text-muted">{t(`settings.sections.${h.section}`)}</span>}
                </li>
              ))}
            </ul>
          ) : (
            <p className="px-2 py-3 text-center text-sm text-muted">{t('settings.search.none')}</p>
          )}
        </div>
      )}
    </div>
  )
}

/** After a search, scroll to the setting that was found and flash it (?find=label). */
function FindHighlight() {
  const [params, setParams] = useSearchParams()
  const find = params.get('find')
  useEffect(() => {
    if (!find) return
    let tries = 0
    const timer = window.setInterval(() => {
      tries += 1
      const main = document.querySelector('main') ?? document.body
      const el = [...main.querySelectorAll<HTMLElement>('label, h2, h3, legend, th, dt, p, span, button')].find((x) => x.textContent?.trim() === find)
      if (el || tries > 20) {
        window.clearInterval(timer)
        if (el) {
          const target = el.closest<HTMLElement>('label, fieldset, tr, li, div') ?? el
          target.scrollIntoView({ block: 'center', behavior: 'smooth' })
          target.classList.add('find-flash')
          window.setTimeout(() => target.classList.remove('find-flash'), 2000)
        }
        const next = new URLSearchParams(window.location.search)
        next.delete('find')
        setParams(next, { replace: true })
      }
    }, 100)
    return () => window.clearInterval(timer)
  }, [find, setParams])
  return null
}
