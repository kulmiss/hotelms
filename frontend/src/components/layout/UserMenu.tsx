import { useEffect, useId, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { useTranslation } from 'react-i18next'
import {
  ArrowLeftStartOnRectangleIcon,
  ComputerDesktopIcon,
  KeyIcon,
  LifebuoyIcon,
  MoonIcon,
  SunIcon,
  UserCircleIcon,
} from '@heroicons/react/24/outline'
import { useLogout, useMe } from '@/lib/auth'
import { cn } from '@/lib/cn'
import { useTheme, type ThemeChoice } from '@/lib/theme'

function initials(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase())
    .join('')
}

const item =
  'flex min-h-touch w-full items-center gap-x-2 rounded-md px-2 text-left text-sm text-text outline-none transition duration-75 hover:bg-sunken focus-visible:bg-sunken'

const themes: { choice: ThemeChoice; icon: typeof SunIcon }[] = [
  { choice: 'light', icon: SunIcon },
  { choice: 'dark', icon: MoonIcon },
  { choice: 'system', icon: ComputerDesktopIcon },
]

/** Avatar in the top bar: profile, theme and sign out (Filament user menu). */
export function UserMenu() {
  const { t } = useTranslation()
  const me = useMe()
  const logout = useLogout()
  const navigate = useNavigate()
  const { choice, setChoice } = useTheme()
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)
  const menuId = useId()

  useEffect(() => {
    if (!open) return
    const onDown = (e: PointerEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false)
    }
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)
    document.addEventListener('pointerdown', onDown)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('pointerdown', onDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  if (!me.data) return null
  const { user } = me.data
  const role = user.is_super_admin ? t('topbar.hotelmsTeam') : (me.data.hotel?.name ?? '')

  const signOut = () => {
    setOpen(false)
    logout.mutate(undefined, { onSettled: () => navigate('/login', { replace: true }) })
  }

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={menuId}
        aria-label={t('topbar.accountMenu')}
        onClick={() => setOpen((o) => !o)}
        className="flex size-touch items-center justify-center rounded-full outline-none"
      >
        <span className="flex size-8 items-center justify-center rounded-full bg-accent text-xs font-semibold text-on-accent ring-2 ring-surface transition hover:ring-sunken">
          {initials(user.name)}
        </span>
        <span className="sr-only">{user.name}</span>
      </button>

      {open && (
        <div
          id={menuId}
          role="menu"
          className="absolute top-full right-0 z-50 mt-2 w-64 origin-top-right animate-menu-in divide-y divide-border rounded-lg bg-surface shadow-lg ring-1 ring-ring motion-reduce:animate-none"
        >
          <div className="flex items-center gap-x-3 p-3">
            <span aria-hidden className="flex size-9 shrink-0 items-center justify-center rounded-full bg-accent text-xs font-semibold text-on-accent">
              {initials(user.name)}
            </span>
            <span className="flex min-w-0 flex-col">
              <span className="truncate text-sm font-medium text-text">{user.name}</span>
              <span className="truncate text-xs text-muted">{role}</span>
            </span>
          </div>

          <div className="flex gap-x-1 p-1" role="group" aria-label={t('topbar.theme')}>
            {themes.map(({ choice: c, icon: Icon }) => (
              <button
                key={c}
                type="button"
                role="menuitemradio"
                aria-checked={choice === c}
                aria-label={t(`topbar.themes.${c}`)}
                title={t(`topbar.themes.${c}`)}
                onClick={() => setChoice(c)}
                className={cn(
                  'flex min-h-touch flex-1 items-center justify-center rounded-md outline-none transition duration-75',
                  choice === c ? 'bg-sunken text-accent-text' : 'text-icon hover:bg-sunken hover:text-muted',
                )}
              >
                <Icon aria-hidden className="size-4" />
              </button>
            ))}
          </div>

          <div className="p-1">
            <Link role="menuitem" to="/profile" onClick={() => setOpen(false)} className={item}>
              <UserCircleIcon aria-hidden className="size-4 text-icon" />
              {t('topbar.profile')}
            </Link>
            <Link role="menuitem" to="/change-password" onClick={() => setOpen(false)} className={item}>
              <KeyIcon aria-hidden className="size-4 text-icon" />
              {t('topbar.changePassword')}
            </Link>
            {!user.is_super_admin && (
              <Link role="menuitem" to="/help" onClick={() => setOpen(false)} className={item}>
                <LifebuoyIcon aria-hidden className="size-4 text-icon" />
                {t('help.menu')}
              </Link>
            )}
          </div>

          <div className="p-1">
            <button role="menuitem" type="button" onClick={signOut} className={item}>
              <ArrowLeftStartOnRectangleIcon aria-hidden className="size-4 text-icon" />
              {t('topbar.signOut')}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
