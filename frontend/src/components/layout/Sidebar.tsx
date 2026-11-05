import { NavLink } from 'react-router'
import { useTranslation } from 'react-i18next'
import { PanelLeftClose, PanelLeftOpen, X } from 'lucide-react'
import { adminNav, devNav, mainNav, type NavItem } from '@/app/navigation'
import { useMe } from '@/lib/auth'
import { cn } from '@/lib/cn'

type EntryProps = { item: NavItem; collapsed: boolean; onNavigate?: () => void }

function NavEntry({ item, collapsed, onNavigate }: EntryProps) {
  const { t } = useTranslation()
  const Icon = item.icon
  const label = t(`nav.${item.key}`)
  return (
    <NavLink
      to={item.path}
      end={item.path === '/' || item.path === '/admin'}
      onClick={onNavigate}
      title={collapsed ? label : undefined}
      className={({ isActive }) =>
        cn(
          'flex min-h-touch rounded-lg font-medium transition-colors duration-200',
          // Collapsed (desktop): icon on top, small name underneath.
          collapsed
            ? 'lg:flex-col lg:items-center lg:justify-center lg:gap-1 lg:px-1 lg:py-2 lg:text-center lg:text-[11px] lg:leading-tight'
            : '',
          'items-center gap-3 px-3 text-sm',
          isActive ? 'bg-accent-soft font-semibold text-accent-text' : 'text-muted hover:bg-sunken hover:text-text',
        )
      }
    >
      <Icon aria-hidden className="size-5 shrink-0" />
      <span className={cn('min-w-0', collapsed && 'lg:line-clamp-2 lg:w-full lg:break-words')}>{label}</span>
    </NavLink>
  )
}

type SidebarProps = { open: boolean; collapsed: boolean; onClose: () => void; onToggleCollapsed: () => void }

export function Sidebar({ open, collapsed, onClose, onToggleCollapsed }: SidebarProps) {
  const { t } = useTranslation()
  const me = useMe()
  const items = me.data?.user.is_super_admin ? adminNav : mainNav

  return (
    <>
      {/* Backdrop for the drawer on phones and tablets */}
      <div
        aria-hidden
        onClick={onClose}
        className={cn(
          'fixed inset-0 z-30 bg-black/40 transition-opacity duration-300 lg:hidden',
          open ? 'opacity-100' : 'pointer-events-none opacity-0',
        )}
      />
      <aside
        data-collapsed={collapsed}
        className={cn(
          'fixed inset-y-0 left-0 z-40 flex w-64 flex-col gap-4 overflow-hidden border-r border-border bg-surface p-4',
          'transition-[transform,width,padding] duration-300 ease-in-out motion-reduce:transition-none',
          'lg:static lg:translate-x-0',
          collapsed && 'lg:w-24 lg:px-2',
          open ? 'translate-x-0' : '-translate-x-full',
        )}
      >
        <div className={cn('flex items-center justify-between gap-2', collapsed ? 'lg:flex-col lg:px-0' : 'px-2')}>
          <span className="flex items-center gap-2 text-lg font-bold whitespace-nowrap text-text">
            <img src="/favicon.svg" alt="" className="size-8 shrink-0" />
            <span className={cn(collapsed && 'lg:sr-only')}>{t('app.name')}</span>
          </span>
          <button
            type="button"
            onClick={onToggleCollapsed}
            aria-label={collapsed ? t('nav.expand') : t('nav.collapse')}
            aria-expanded={!collapsed}
            className="hidden size-touch shrink-0 items-center justify-center rounded-lg text-muted hover:bg-sunken hover:text-text lg:flex"
          >
            {collapsed ? <PanelLeftOpen aria-hidden className="size-5" /> : <PanelLeftClose aria-hidden className="size-5" />}
          </button>
          <button
            type="button"
            onClick={onClose}
            aria-label={t('topbar.closeMenu')}
            className="flex size-touch items-center justify-center rounded-lg text-muted hover:bg-sunken lg:hidden"
          >
            <X aria-hidden className="size-5" />
          </button>
        </div>

        <nav aria-label={t('nav.main')} className="flex flex-1 flex-col gap-1 overflow-x-hidden overflow-y-auto">
          {items.map((item) => (
            <NavEntry key={item.key} item={item} collapsed={collapsed} onNavigate={onClose} />
          ))}
          {import.meta.env.DEV && (
            <div className="mt-auto border-t border-border pt-3">
              {devNav.map((item) => (
                <NavEntry key={item.key} item={item} collapsed={collapsed} onNavigate={onClose} />
              ))}
            </div>
          )}
        </nav>
      </aside>
    </>
  )
}
