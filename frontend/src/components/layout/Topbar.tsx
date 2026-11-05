import { useTranslation } from 'react-i18next'
import { Bell, Menu, Moon, Search, Sun } from 'lucide-react'
import { useTheme } from '@/lib/theme'

export function Topbar({ onOpenMenu }: { onOpenMenu: () => void }) {
  const { t } = useTranslation()
  const { theme, toggleTheme } = useTheme()
  const iconButton =
    'flex size-touch shrink-0 items-center justify-center rounded-lg border border-border bg-surface text-muted hover:bg-sunken hover:text-text'

  return (
    <header className="sticky top-0 z-20 flex items-center gap-3 border-b border-border bg-surface/95 px-4 py-3 backdrop-blur">
      <button type="button" onClick={onOpenMenu} aria-label={t('topbar.openMenu')} className={`${iconButton} lg:hidden`}>
        <Menu aria-hidden className="size-5" />
      </button>

      <label className="flex min-h-touch max-w-md flex-1 items-center gap-2 rounded-lg border border-border bg-bg px-3 text-muted">
        <Search aria-hidden className="size-4 shrink-0" />
        <span className="sr-only">{t('topbar.search')}</span>
        <input
          type="search"
          placeholder={t('topbar.search')}
          className="w-full bg-transparent text-base text-text placeholder:text-muted focus:outline-none"
        />
      </label>

      <div className="ml-auto flex items-center gap-2">
        <label className="hidden items-center gap-2 text-sm text-muted md:flex">
          {t('topbar.branch')}
          <select className="min-h-touch rounded-lg border border-border bg-surface px-3 text-sm text-text">
            <option>{t('topbar.mainBranch')}</option>
          </select>
        </label>
        <button
          type="button"
          onClick={toggleTheme}
          aria-label={theme === 'dark' ? t('topbar.lightMode') : t('topbar.darkMode')}
          className={iconButton}
        >
          {theme === 'dark' ? <Sun aria-hidden className="size-5" /> : <Moon aria-hidden className="size-5" />}
        </button>
        <button type="button" aria-label={t('topbar.notifications')} className={iconButton}>
          <Bell aria-hidden className="size-5" />
        </button>
      </div>
    </header>
  )
}
