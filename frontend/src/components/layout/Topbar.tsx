import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { Bell, List, MagnifyingGlass, Moon, SignOut, Sun } from '@phosphor-icons/react'
import { useLogout, useMe } from '@/lib/auth'
import { useCurrentBranch } from '@/lib/branch'
import { useTheme } from '@/lib/theme'

function initials(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase())
    .join('')
}

export function Topbar({ onOpenMenu }: { onOpenMenu: () => void }) {
  const { t } = useTranslation()
  const { theme, toggleTheme } = useTheme()
  const me = useMe()
  const logout = useLogout()
  const navigate = useNavigate()
  const { branches, current, select } = useCurrentBranch(me.data)
  const iconButton =
    'flex size-touch shrink-0 items-center justify-center rounded-xl text-muted transition-colors hover:bg-sunken hover:text-text'

  const signOut = () => logout.mutate(undefined, { onSettled: () => navigate('/login', { replace: true }) })
  const role = me.data?.user.is_super_admin ? t('topbar.hotelmsTeam') : (me.data?.hotel?.name ?? '')

  return (
    <header className="sticky top-0 z-20 flex items-center gap-3 border-b border-border/70 bg-bg/80 px-4 py-3 backdrop-blur-md md:px-6">
      <button type="button" onClick={onOpenMenu} aria-label={t('topbar.openMenu')} className={`${iconButton} lg:hidden`}>
        <List aria-hidden weight="bold" className="size-6" />
      </button>

      <label className="flex min-h-touch max-w-md flex-1 items-center gap-2 rounded-xl border border-border/70 bg-surface px-3 text-muted shadow-soft focus-within:border-accent">
        <MagnifyingGlass aria-hidden weight="bold" className="size-4 shrink-0" />
        <span className="sr-only">{t('topbar.search')}</span>
        <input
          type="search"
          placeholder={t('topbar.search')}
          className="w-full bg-transparent text-base text-text placeholder:text-muted focus:outline-none"
        />
      </label>

      <div className="ml-auto flex items-center gap-1">
        {branches.length > 0 && (
          <label className="mr-2 hidden items-center gap-2 text-sm text-muted md:flex">
            <span className="sr-only">{t('topbar.branch')}</span>
            <select
              value={current?.id ?? ''}
              onChange={(e) => select(Number(e.target.value))}
              aria-label={t('topbar.branch')}
              className="min-h-touch rounded-xl border border-border/70 bg-surface px-3 text-sm font-medium text-text shadow-soft"
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
          {theme === 'dark' ? (
            <Sun aria-hidden weight="duotone" className="size-6" />
          ) : (
            <Moon aria-hidden weight="duotone" className="size-6" />
          )}
        </button>
        <button type="button" aria-label={t('topbar.notifications')} className={iconButton}>
          <Bell aria-hidden weight="duotone" className="size-6" />
        </button>

        {me.data && (
          <div className="ml-2 flex items-center gap-3 border-l border-border pl-3">
            <span aria-hidden className="flex size-10 items-center justify-center rounded-full bg-accent-soft text-sm font-semibold text-accent-text">
              {initials(me.data.user.name)}
            </span>
            <span className="hidden max-w-44 flex-col leading-tight sm:flex">
              <span className="truncate text-sm font-semibold text-text">{me.data.user.name}</span>
              <span className="truncate text-xs text-muted">{role}</span>
            </span>
            <button type="button" onClick={signOut} aria-label={t('topbar.signOut')} className={iconButton}>
              <SignOut aria-hidden weight="duotone" className="size-6" />
            </button>
          </div>
        )}
      </div>
    </header>
  )
}
