import { NavLink } from 'react-router'
import { useTranslation } from 'react-i18next'
import { SidebarSimple, X } from '@phosphor-icons/react'
import { adminNav, devNav, mainNav, type NavItem } from '@/app/navigation'
import { prefetchRoute } from '@/app/prefetch'
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
      onPointerEnter={() => prefetchRoute(item.path)}
      onFocus={() => prefetchRoute(item.path)}
      title={collapsed ? label : undefined}
      className={({ isActive }) =>
        cn(
          'group relative flex min-h-touch items-center gap-3 rounded-xl px-3 text-sm font-medium',
          'transition-[background-color,color] duration-200',
          // Collapsed (desktop): icon on top, small name underneath.
          collapsed &&
            'lg:flex-col lg:justify-center lg:gap-1 lg:px-1 lg:py-2.5 lg:text-center lg:text-[11px] lg:leading-tight',
          isActive
            ? 'bg-lobby-raised text-white'
            : 'text-lobby-muted hover:bg-lobby-raised/60 hover:text-lobby-text',
        )
      }
    >
      {({ isActive }) => (
        <>
          {/* Brass key: the mark of the current page */}
          <span
            aria-hidden
            className={cn(
              'absolute top-1/2 left-0 h-6 w-1 -translate-y-1/2 rounded-r-full bg-brass transition-[opacity,transform] duration-200',
              isActive ? 'scale-y-100 opacity-100' : 'scale-y-0 opacity-0',
            )}
          />
          <Icon
            aria-hidden
            weight="duotone"
            className={cn('size-6 shrink-0 transition-colors', isActive ? 'text-brass' : 'text-current')}
          />
          <span className={cn('min-w-0 truncate', collapsed && 'lg:line-clamp-2 lg:w-full lg:whitespace-normal')}>
            {label}
          </span>
        </>
      )}
    </NavLink>
  )
}

type SidebarProps = { open: boolean; collapsed: boolean; onClose: () => void; onToggleCollapsed: () => void }

export function Sidebar({ open, collapsed, onClose, onToggleCollapsed }: SidebarProps) {
  const { t } = useTranslation()
  const me = useMe()
  const items = me.data?.user.is_super_admin ? adminNav : mainNav
  const place = me.data?.hotel?.name ?? (me.data?.user.is_super_admin ? t('nav.platform') : '')

  return (
    <>
      {/* Backdrop for the drawer on phones and tablets */}
      <div
        aria-hidden
        onClick={onClose}
        className={cn(
          'fixed inset-0 z-30 bg-lobby/50 backdrop-blur-[2px] transition-opacity duration-300 lg:hidden',
          open ? 'opacity-100' : 'pointer-events-none opacity-0',
        )}
      />
      <aside
        data-collapsed={collapsed}
        className={cn(
          'fixed inset-y-0 left-0 z-40 flex w-64 flex-col gap-6 overflow-hidden bg-lobby p-4 text-lobby-text',
          'transition-[transform,width,padding] duration-300 ease-[cubic-bezier(0.2,0.7,0.2,1)] motion-reduce:transition-none',
          'lg:static lg:translate-x-0',
          collapsed && 'lg:w-[5.5rem] lg:px-2',
          open ? 'translate-x-0' : '-translate-x-full',
        )}
      >
        <div className={cn('flex items-center gap-2', collapsed ? 'lg:flex-col' : 'justify-between')}>
          <span className="flex min-w-0 items-center gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brass text-lg font-bold text-lobby">
              H
            </span>
            <span className={cn('flex min-w-0 flex-col', collapsed && 'lg:sr-only')}>
              <span className="text-base font-semibold text-white">{t('app.name')}</span>
              <span className="truncate text-xs text-lobby-muted">{place}</span>
            </span>
          </span>
          <button
            type="button"
            onClick={onToggleCollapsed}
            aria-label={collapsed ? t('nav.expand') : t('nav.collapse')}
            aria-expanded={!collapsed}
            className="hidden size-touch shrink-0 items-center justify-center rounded-xl text-lobby-muted transition-colors hover:bg-lobby-raised hover:text-white lg:flex"
          >
            <SidebarSimple aria-hidden weight="duotone" className={cn('size-6 transition-transform duration-300', collapsed && 'rotate-180')} />
          </button>
          <button
            type="button"
            onClick={onClose}
            aria-label={t('topbar.closeMenu')}
            className="flex size-touch items-center justify-center rounded-xl text-lobby-muted hover:bg-lobby-raised lg:hidden"
          >
            <X aria-hidden weight="bold" className="size-5" />
          </button>
        </div>

        <nav aria-label={t('nav.main')} className="flex flex-1 flex-col gap-1 overflow-x-hidden overflow-y-auto">
          {items.map((item) => (
            <NavEntry key={item.key} item={item} collapsed={collapsed} onNavigate={onClose} />
          ))}
          {import.meta.env.DEV && (
            <div className="mt-auto border-t border-lobby-raised pt-3">
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
