import { useTranslation } from 'react-i18next'
import { ChevronsUpDown } from 'lucide-react'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuTrigger,
} from '@/components/shadcn/dropdown-menu'
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/shadcn/sidebar'
import { useMe } from '@/lib/auth'
import { BrandMark, useBrand } from '@/lib/brand'
import { useCurrentBranch } from '@/lib/branch'

/**
 * Top of the sidebar (template team switcher): brand logo and name from Super Admin branding,
 * and the branch this person works in. Hotel staff choose their branch here.
 */
export function BranchSwitcher() {
  const { t } = useTranslation()
  const { isMobile } = useSidebar()
  const me = useMe()
  const brand = useBrand()
  const { branches, current, select } = useCurrentBranch(me.data)
  const subtitle = me.data?.user.is_super_admin && !me.data.hotel ? t('topbar.hotelmsTeam') : (current?.name ?? me.data?.hotel?.name ?? '')

  const title = (
    <>
      <BrandMark className="size-8 rounded-lg" />
      <div className="grid flex-1 text-start text-sm leading-tight">
        <span className="truncate font-semibold">{brand.name}</span>
        {subtitle && <span className="truncate text-xs">{subtitle}</span>}
      </div>
    </>
  )

  // No branches (Super Admin): just the brand.
  if (branches.length === 0) {
    return (
      <SidebarMenu>
        <SidebarMenuItem>
          <div className="flex h-12 items-center gap-2 overflow-hidden rounded-md p-2 text-sm group-data-[collapsible=icon]:size-8! group-data-[collapsible=icon]:p-0!">{title}</div>
        </SidebarMenuItem>
      </SidebarMenu>
    )
  }

  return (
    <SidebarMenu>
      <SidebarMenuItem>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <SidebarMenuButton size="lg" aria-label={t('topbar.chooseBranch', { branch: current?.name ?? '' })} className="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground">
              {title}
              <ChevronsUpDown aria-hidden className="ms-auto" />
            </SidebarMenuButton>
          </DropdownMenuTrigger>
          <DropdownMenuContent className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg" align="start" side={isMobile ? 'bottom' : 'right'} sideOffset={4}>
            <DropdownMenuLabel className="text-xs text-muted">{t('topbar.branch')}</DropdownMenuLabel>
            <DropdownMenuRadioGroup value={String(current?.id ?? '')} onValueChange={(v) => select(Number(v))}>
              {branches.map((b) => (
                <DropdownMenuRadioItem key={b.id} value={String(b.id)}>
                  {b.name}
                </DropdownMenuRadioItem>
              ))}
            </DropdownMenuRadioGroup>
          </DropdownMenuContent>
        </DropdownMenu>
      </SidebarMenuItem>
    </SidebarMenu>
  )
}
