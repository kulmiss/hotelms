import { Link, useNavigate } from 'react-router'
import { useTranslation } from 'react-i18next'
import { ChevronsUpDown, CircleUser, Gift, KeyRound, LayoutGrid, LifeBuoy, LogOut } from 'lucide-react'
import { Button } from '@/components/shadcn/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/shadcn/dropdown-menu'
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/shadcn/sidebar'
import { useLogout, useMe } from '@/lib/auth'
import { cn } from '@/lib/cn'
import { useFrontDeskMode } from '@/lib/frontDeskMode'
import { useHasWhatsNew } from '@/lib/whatsNew'

function initials(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase())
    .join('')
}

/** Round avatar with the person's initials (no photos yet). */
function Avatar({ name, className }: { name: string; className?: string }) {
  return (
    <span aria-hidden className={cn('flex size-8 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-semibold text-primary-foreground', className)}>
      {initials(name)}
    </span>
  )
}

/** Who is signed in: name and hotel (or "HotelMS team"). */
function useAccount() {
  const { t } = useTranslation()
  const me = useMe()
  if (!me.data) return null
  const { user } = me.data
  return { me: me.data, user, role: user.is_super_admin ? t('topbar.hotelmsTeam') : (me.data.hotel?.name ?? '') }
}

/** The same items in the header menu and the sidebar menu: profile, password, front desk mode, what's new, help, sign out. */
function AccountItems() {
  const { t } = useTranslation()
  const account = useAccount()
  const logout = useLogout()
  const navigate = useNavigate()
  const [, setFrontDesk] = useFrontDeskMode(account?.user.id)
  const hasNews = useHasWhatsNew(account?.user.id)
  if (!account) return null
  const { me, user, role } = account

  const signOut = () => logout.mutate(undefined, { onSettled: () => navigate('/login', { replace: true }) })

  return (
    <>
      <DropdownMenuLabel className="p-0 font-normal">
        <div className="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
          <Avatar name={user.name} />
          <div className="grid flex-1 text-start text-sm leading-tight">
            <span className="truncate font-semibold">{user.name}</span>
            <span className="truncate text-xs text-muted">{role}</span>
          </div>
        </div>
      </DropdownMenuLabel>
      <DropdownMenuSeparator />
      <DropdownMenuGroup>
        <DropdownMenuItem asChild>
          <Link to="/profile">
            <CircleUser aria-hidden />
            {t('topbar.profile')}
          </Link>
        </DropdownMenuItem>
        <DropdownMenuItem asChild>
          <Link to="/change-password">
            <KeyRound aria-hidden />
            {t('topbar.changePassword')}
          </Link>
        </DropdownMenuItem>
        {!user.is_super_admin && me.permissions.includes('frontdesk.view') && (
          <DropdownMenuItem
            onSelect={() => {
              setFrontDesk(true)
              navigate('/front-desk')
            }}
          >
            <LayoutGrid aria-hidden />
            {t('frontDesk.turnOn')}
          </DropdownMenuItem>
        )}
        <DropdownMenuItem asChild>
          <Link to="/whats-new">
            <Gift aria-hidden />
            <span className="flex-1">{t('whatsNew.title')}</span>
            {hasNews && <span aria-label={t('whatsNew.new')} className="size-2 rounded-full bg-primary" />}
          </Link>
        </DropdownMenuItem>
        {!user.is_super_admin && (
          <DropdownMenuItem asChild>
            <Link to="/help">
              <LifeBuoy aria-hidden />
              {t('help.menu')}
            </Link>
          </DropdownMenuItem>
        )}
      </DropdownMenuGroup>
      <DropdownMenuSeparator />
      <DropdownMenuItem variant="destructive" onSelect={signOut}>
        <LogOut aria-hidden />
        {t('topbar.signOut')}
      </DropdownMenuItem>
    </>
  )
}

/** Avatar at the top right (template ProfileDropdown). */
export function ProfileDropdown() {
  const { t } = useTranslation()
  const account = useAccount()
  if (!account) return null
  return (
    <DropdownMenu modal={false}>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" aria-label={t('topbar.accountMenu')} className="relative size-8 rounded-full p-0 pointer-coarse:size-11">
          <Avatar name={account.user.name} />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent className="w-60" align="end">
        <AccountItems />
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

/** The person at the bottom of the sidebar (template NavUser): same menu as the avatar. */
export function NavUser() {
  const { isMobile } = useSidebar()
  const account = useAccount()
  if (!account) return null
  return (
    <SidebarMenu>
      <SidebarMenuItem>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <SidebarMenuButton size="lg" className="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground">
              <Avatar name={account.user.name} className="rounded-lg" />
              <div className="grid flex-1 text-start text-sm leading-tight">
                <span className="truncate font-semibold">{account.user.name}</span>
                <span className="truncate text-xs">{account.role}</span>
              </div>
              <ChevronsUpDown aria-hidden className="ms-auto size-4" />
            </SidebarMenuButton>
          </DropdownMenuTrigger>
          <DropdownMenuContent className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg" side={isMobile ? 'bottom' : 'right'} align="end" sideOffset={4}>
            <AccountItems />
          </DropdownMenuContent>
        </DropdownMenu>
      </SidebarMenuItem>
    </SidebarMenu>
  )
}
