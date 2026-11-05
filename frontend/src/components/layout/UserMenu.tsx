import { useEffect, useId, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { useTranslation } from 'react-i18next'
import { CaretDown, Key, Moon, SignOut, Sun, UserCircle } from '@phosphor-icons/react'
import { useLogout, useMe } from '@/lib/auth'
import { cn } from '@/lib/cn'
import { useTheme } from '@/lib/theme'

function initials(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase())
    .join('')
}

const item =
  'flex min-h-touch w-full items-center gap-3 rounded-xl px-3 text-left text-sm font-medium text-text transition-colors hover:bg-sunken focus-visible:bg-sunken'

/** Avatar button in the top bar that opens profile, theme and sign out. */
export function UserMenu() {
  const { t } = useTranslation()
  const me = useMe()
  const logout = useLogout()
  const navigate = useNavigate()
  const { theme, toggleTheme } = useTheme()
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
    <div ref={ref} className="relative ml-2 border-l border-border pl-3">
      <button
        type="button"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={menuId}
        aria-label={t('topbar.accountMenu')}
        onClick={() => setOpen((o) => !o)}
        className="flex min-h-touch items-center gap-3 rounded-xl px-1.5 transition-colors hover:bg-sunken"
      >
        <span aria-hidden className="flex size-10 items-center justify-center rounded-full bg-accent-soft text-sm font-semibold text-accent-text">
          {initials(user.name)}
        </span>
        <span className="hidden max-w-44 flex-col text-left leading-tight sm:flex">
          <span className="truncate text-sm font-semibold text-text">{user.name}</span>
          <span className="truncate text-xs text-muted">{role}</span>
        </span>
        <CaretDown aria-hidden weight="bold" className={cn('size-4 text-muted transition-transform duration-200', open && 'rotate-180')} />
      </button>

      {open && (
      <div
        id={menuId}
        role="menu"
        className="absolute top-full right-0 z-50 mt-2 w-72 origin-top-right animate-menu-in rounded-2xl border border-border/70 bg-surface p-2 shadow-lift motion-reduce:animate-none"
      >
        <div className="flex items-center gap-3 px-3 py-3">
          <span aria-hidden className="flex size-11 items-center justify-center rounded-full bg-accent-soft font-semibold text-accent-text">
            {initials(user.name)}
          </span>
          <span className="flex min-w-0 flex-col">
            <span className="truncate font-semibold">{user.name}</span>
            <span className="truncate text-sm text-muted">{user.email}</span>
          </span>
        </div>
        <div className="my-1 border-t border-border/70" />
        <Link role="menuitem" to="/profile" onClick={() => setOpen(false)} className={item}>
          <UserCircle aria-hidden weight="duotone" className="size-5 text-muted" />
          {t('topbar.profile')}
        </Link>
        <Link role="menuitem" to="/change-password" onClick={() => setOpen(false)} className={item}>
          <Key aria-hidden weight="duotone" className="size-5 text-muted" />
          {t('topbar.changePassword')}
        </Link>
        <button role="menuitem" type="button" onClick={toggleTheme} className={item}>
          {theme === 'dark' ? (
            <Sun aria-hidden weight="duotone" className="size-5 text-muted" />
          ) : (
            <Moon aria-hidden weight="duotone" className="size-5 text-muted" />
          )}
          {theme === 'dark' ? t('topbar.lightMode') : t('topbar.darkMode')}
        </button>
        <div className="my-1 border-t border-border/70" />
        <button role="menuitem" type="button" onClick={signOut} className={cn(item, 'text-danger-text hover:bg-danger-bg')}>
          <SignOut aria-hidden weight="duotone" className="size-5" />
          {t('topbar.signOut')}
        </button>
      </div>
      )}
    </div>
  )
}
