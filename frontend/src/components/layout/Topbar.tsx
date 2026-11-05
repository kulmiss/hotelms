import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { Bell, LogOut, Menu, Moon, Search, Sun } from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import { useLogout, useMe } from '@/lib/auth'
import { useCurrentBranch } from '@/lib/branch'
import { useTheme } from '@/lib/theme'

export function Topbar({ onOpenMenu }: { onOpenMenu: () => void }) {
  const { t } = useTranslation()
  const { theme, toggleTheme } = useTheme()
  const me = useMe()
  const logout = useLogout()
  const navigate = useNavigate()
  const { branches, current, select } = useCurrentBranch(me.data)
  const iconButton =
    'flex size-touch shrink-0 items-center justify-center rounded-lg border border-border bg-surface text-muted hover:bg-sunken hover:text-text'

  const signOut = () => logout.mutate(undefined, { onSettled: () => navigate('/login', { replace: true }) })

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
        {me.data?.user.is_super_admin && <Badge tone="accent">{t('topbar.superAdmin')}</Badge>}

        {branches.length > 0 && (
          <label className="hidden items-center gap-2 text-sm text-muted md:flex">
            {t('topbar.branch')}
            <select
              value={current?.id ?? ''}
              onChange={(e) => select(Number(e.target.value))}
              className="min-h-touch rounded-lg border border-border bg-surface px-3 text-sm text-text"
            >
              {branches.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.name}
                </option>
              ))}
            </select>
          </label>
        )}

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

        {me.data && (
          <div className="flex items-center gap-2 border-l border-border pl-2">
            <span className="hidden max-w-40 truncate text-sm font-medium text-text sm:block">{me.data.user.name}</span>
            <button type="button" onClick={signOut} aria-label={t('topbar.signOut')} className={iconButton}>
              <LogOut aria-hidden className="size-5" />
            </button>
          </div>
        )}
      </div>
    </header>
  )
}
