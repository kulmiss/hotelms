import { Fragment } from 'react'
import { NavLink } from 'react-router'
import { useTranslation } from 'react-i18next'
import { ChevronLeftIcon, ChevronRightIcon, XMarkIcon } from '@heroicons/react/24/outline'
import { adminNav, devNav, mainNav, type NavItem } from '@/app/navigation'
import { prefetchRoute } from '@/app/prefetch'
import { useMe } from '@/lib/auth'
import { useCan } from '@/lib/hotel'
import { BrandMark, useBrand } from '@/lib/brand'
import { cn } from '@/lib/cn'

type EntryProps = { item: NavItem; collapsed: boolean; onNavigate?: () => void }

function NavEntry({ item, collapsed, onNavigate }: EntryProps) {
  const { t } = useTranslation()
  const label = t(`nav.${item.key}`)
  return (
    <li className="group/item relative">
      <NavLink
        to={item.path}
        end={item.path === '/' || item.path === '/admin'}
        onClick={onNavigate}
        onPointerEnter={() => prefetchRoute(item.path)}
        onFocus={() => prefetchRoute(item.path)}
        aria-label={collapsed ? label : undefined}
        className={({ isActive }) =>
          cn(
            'flex min-h-touch items-center gap-x-2.5 rounded-lg px-2 text-[13px] font-medium outline-none transition duration-75',
            'focus-visible:bg-sunken',
            collapsed && 'lg:justify-center lg:px-0',
            isActive ? 'bg-sunken text-accent-text' : 'text-grey-text hover:bg-sunken',
          )
        }
      >
        {({ isActive }) => {
          const Icon = isActive ? item.activeIcon : item.icon
          return (
            <>
              <Icon aria-hidden className={cn('size-5 shrink-0', isActive ? 'text-accent-text' : 'text-icon')} />
              {/* Collapsed: icon only (the name shows as a tooltip). */}
              <span className={cn('flex-1 truncate', collapsed && 'lg:hidden')}>{label}</span>
            </>
          )
        }}
      </NavLink>
      {collapsed && (
        <span
          role="tooltip"
          className="pointer-events-none absolute top-1/2 left-full z-50 ml-3 hidden -translate-y-1/2 rounded-lg bg-text px-2.5 py-1.5 text-xs font-medium whitespace-nowrap text-bg opacity-0 shadow-lg transition-opacity duration-100 group-hover/item:opacity-100 group-focus-within/item:opacity-100 lg:block"
        >
          {label}
        </span>
      )}
    </li>
  )
}

type SidebarProps = { open: boolean; collapsed: boolean; onClose: () => void; onToggleCollapsed: () => void }

export function Sidebar({ open, collapsed, onClose, onToggleCollapsed }: SidebarProps) {
  const { t } = useTranslation()
  const me = useMe()
  const can = useCan()
  const brand = useBrand()
  const items = [
    ...(me.data?.user.is_super_admin ? adminNav : mainNav.filter((item) => can(item.permission))),
    ...(import.meta.env.DEV ? devNav : []),
  ]

  // Keep the order, start a new group whenever the group name changes.
  const groups: { name?: string; items: NavItem[] }[] = []
  for (const item of items) {
    const last = groups[groups.length - 1]
    if (last && last.name === item.group) last.items.push(item)
    else groups.push({ name: item.group, items: [item] })
  }

  return (
    <>
      {/* Backdrop for the drawer on phones and tablets */}
      <div
        aria-hidden
        onClick={onClose}
        className={cn(
          'fixed inset-0 z-30 bg-zinc-950/50 transition-opacity duration-300 lg:hidden dark:bg-zinc-950/75',
          open ? 'opacity-100' : 'pointer-events-none opacity-0',
        )}
      />
      <aside
        data-collapsed={collapsed}
        className={cn(
          'fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-surface shadow-xl ring-1 ring-ring',
          'transition-[transform,width] duration-300 ease-in-out motion-reduce:transition-none',
          'lg:sticky lg:top-0 lg:h-screen lg:translate-x-0 lg:shadow-none',
          collapsed && 'lg:w-16',
          open ? 'translate-x-0' : '-translate-x-full',
        )}
      >
        <header className={cn('flex h-14 shrink-0 items-center gap-x-3 px-4', collapsed && 'lg:justify-center lg:px-0')}>
          {!collapsed && (
            <span className="flex min-w-0 flex-1 items-center gap-x-2.5 text-[15px] font-semibold tracking-tight">
              <BrandMark className="size-7" />
              <span className="truncate">{brand.name}</span>
            </span>
          )}
          <button
            type="button"
            onClick={onToggleCollapsed}
            aria-label={collapsed ? t('nav.expand') : t('nav.collapse')}
            aria-expanded={!collapsed}
            className="hidden size-touch shrink-0 items-center justify-center rounded-lg text-icon transition duration-75 hover:bg-sunken hover:text-muted lg:flex"
          >
            {collapsed ? <ChevronRightIcon aria-hidden className="size-5" /> : <ChevronLeftIcon aria-hidden className="size-5" />}
          </button>
          <button
            type="button"
            onClick={onClose}
            aria-label={t('topbar.closeMenu')}
            className={cn('flex size-touch items-center justify-center rounded-lg text-icon hover:bg-sunken lg:hidden', collapsed && 'ml-auto')}
          >
            <XMarkIcon aria-hidden className="size-5" />
          </button>
        </header>

        <nav aria-label={t('nav.main')} className="flex flex-1 flex-col overflow-x-visible overflow-y-auto px-3 py-4 lg:overflow-y-visible">
          <ul className="flex flex-col gap-y-5">
            {groups.map((group, gi) => (
              <Fragment key={group.name ?? `g${gi}`}>
                <li className="flex flex-col gap-y-1">
                  {group.name && (
                    <span className={cn('mb-1 px-2 text-xs font-medium text-muted', collapsed && 'lg:sr-only')}>
                      {t(`nav.groups.${group.name}`)}
                    </span>
                  )}
                  <ul className="flex flex-col gap-y-0.5">
                    {group.items.map((item) => (
                      <NavEntry key={item.key} item={item} collapsed={collapsed} onNavigate={onClose} />
                    ))}
                  </ul>
                </li>
              </Fragment>
            ))}
          </ul>
        </nav>
      </aside>
    </>
  )
}
