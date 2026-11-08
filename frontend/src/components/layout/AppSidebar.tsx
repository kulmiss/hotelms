import { useEffect } from 'react'
import { Link, useLocation, useMatch } from 'react-router'
import { useTranslation } from 'react-i18next'
import { adminNav, devNav, mainNav, NAV_MODULES, type NavItem } from '@/app/navigation'
import { prefetchRoute } from '@/app/prefetch'
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarRail,
  useSidebar,
} from '@/components/shadcn/sidebar'
import { useMe } from '@/lib/auth'
import { useCan } from '@/lib/hotel'
import { NavUser } from './AccountMenu'
import { BranchSwitcher } from './BranchSwitcher'

/** One menu entry: grey background when its page (or a page under it) is open; name as a tooltip when collapsed. */
function NavEntry({ item }: { item: NavItem }) {
  const { t } = useTranslation()
  const { setOpenMobile } = useSidebar()
  const end = item.path === '/' || item.path === '/admin'
  const active = Boolean(useMatch({ path: item.path, end }))
  const label = t(`nav.${item.key}`)
  return (
    <SidebarMenuItem>
      <SidebarMenuButton asChild isActive={active} tooltip={label}>
        <Link
          to={item.path}
          aria-current={active ? 'page' : undefined}
          onClick={() => setOpenMobile(false)}
          onPointerEnter={() => prefetchRoute(item.path)}
          onFocus={() => prefetchRoute(item.path)}
        >
          <item.icon aria-hidden />
          <span>{label}</span>
        </Link>
      </SidebarMenuButton>
    </SidebarMenuItem>
  )
}

/** The menu items this person may see, in groups (same rules as before: permissions, plan modules, Super Admin). */
function useNavGroups() {
  const me = useMe()
  const can = useCan()
  const modules = me.data?.hotel?.modules ?? []
  const items = [
    ...(me.data?.user.is_super_admin
      ? adminNav.filter((item) => !item.permission || [item.permission].flat().some((p) => me.data?.platform?.permissions.includes(p)))
      : mainNav.filter((item) => can(item.permission) && (!item.module || modules.includes(NAV_MODULES[item.module])))),
    ...(import.meta.env.DEV ? devNav : []),
  ]
  // Keep the order, start a new group whenever the group name changes.
  const groups: { name?: string; items: NavItem[] }[] = []
  for (const item of items) {
    const last = groups[groups.length - 1]
    if (last && last.name === item.group) last.items.push(item)
    else groups.push({ name: item.group, items: [item] })
  }
  return groups
}

/** The template's sidebar: inset, collapses to icons, a drawer on phones. */
export function AppSidebar() {
  const { t } = useTranslation()
  const { setOpenMobile } = useSidebar()
  const { pathname } = useLocation()
  const groups = useNavGroups()

  // Close the phone drawer when a page opens (also from the account menu inside it).
  useEffect(() => setOpenMobile(false), [pathname, setOpenMobile])

  return (
    <Sidebar collapsible="icon" variant="inset">
      <SidebarHeader>
        <BranchSwitcher />
      </SidebarHeader>
      <SidebarContent>
        <nav aria-label={t('nav.main')} className="flex flex-col gap-2">
          {groups.map((group, gi) => (
            <SidebarGroup key={group.name ?? `g${gi}`}>
              {group.name && <SidebarGroupLabel>{t(`nav.groups.${group.name}`)}</SidebarGroupLabel>}
              <SidebarMenu>
                {group.items.map((item) => (
                  <NavEntry key={item.key} item={item} />
                ))}
              </SidebarMenu>
            </SidebarGroup>
          ))}
        </nav>
      </SidebarContent>
      <SidebarFooter>
        <NavUser />
      </SidebarFooter>
      <SidebarRail />
    </Sidebar>
  )
}
