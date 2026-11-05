import { NavLink } from 'react-router'
import { useTranslation } from 'react-i18next'
import { X } from 'lucide-react'
import { devNav, mainNav, type NavItem } from '@/app/navigation'
import { cn } from '@/lib/cn'

function NavEntry({ item, onNavigate }: { item: NavItem; onNavigate?: () => void }) {
  const { t } = useTranslation()
  const Icon = item.icon
  return (
    <NavLink
      to={item.path}
      end={item.path === '/'}
      onClick={onNavigate}
      className={({ isActive }) =>
        cn(
          'flex min-h-touch items-center gap-3 rounded-lg px-3 text-sm font-medium transition-colors',
          isActive ? 'bg-accent-soft font-semibold text-accent-text' : 'text-muted hover:bg-sunken hover:text-text',
        )
      }
    >
      <Icon aria-hidden className="size-5 shrink-0" />
      {t(`nav.${item.key}`)}
    </NavLink>
  )
}

type SidebarProps = { open: boolean; onClose: () => void }

export function Sidebar({ open, onClose }: SidebarProps) {
  const { t } = useTranslation()
  return (
    <>
      {/* Backdrop for the drawer on phones and tablets */}
      <div
        aria-hidden
        onClick={onClose}
        className={cn('fixed inset-0 z-30 bg-black/40 lg:hidden', open ? 'block' : 'hidden')}
      />
      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-40 flex w-64 flex-col gap-4 border-r border-border bg-surface p-4 transition-transform',
          'lg:static lg:translate-x-0',
          open ? 'translate-x-0' : '-translate-x-full',
        )}
      >
        <div className="flex items-center justify-between px-2">
          <span className="flex items-center gap-2 text-lg font-bold text-text">
            <img src="/favicon.svg" alt="" className="size-8" />
            {t('app.name')}
          </span>
          <button
            type="button"
            onClick={onClose}
            aria-label={t('topbar.closeMenu')}
            className="flex size-touch items-center justify-center rounded-lg text-muted hover:bg-sunken lg:hidden"
          >
            <X aria-hidden className="size-5" />
          </button>
        </div>

        <nav aria-label={t('nav.main')} className="flex flex-1 flex-col gap-1 overflow-y-auto">
          {mainNav.map((item) => (
            <NavEntry key={item.key} item={item} onNavigate={onClose} />
          ))}
          {import.meta.env.DEV && (
            <div className="mt-auto border-t border-border pt-3">
              {devNav.map((item) => (
                <NavEntry key={item.key} item={item} onNavigate={onClose} />
              ))}
            </div>
          )}
        </nav>
      </aside>
    </>
  )
}
