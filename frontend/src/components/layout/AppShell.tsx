import { useState } from 'react'
import { Outlet, useLocation } from 'react-router'
import { useMe } from '@/lib/auth'
import { useSidebarCollapsed } from '@/lib/sidebar'
import { HotelBanners } from './HotelBanners'
import { Maintenance, ReadOnlyNotice } from './Maintenance'
import { ScreenLock } from './ScreenLock'
import { ConnectionBanner } from './ConnectionBanner'
import { SessionBanner } from './SessionBanner'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'

export function AppShell() {
  const [menuOpen, setMenuOpen] = useState(false)
  const { collapsed, toggle } = useSidebarCollapsed()
  const { pathname } = useLocation()
  const me = useMe()
  const support = Boolean(me.data?.impersonation)
  if (me.data?.emergency?.maintenance && !support) return <Maintenance />
  return (
    <div className="flex min-h-full">
      <Sidebar open={menuOpen} collapsed={collapsed} onClose={() => setMenuOpen(false)} onToggleCollapsed={toggle} />
      <div className="flex min-w-0 flex-1 flex-col">
        <ConnectionBanner />
        <SessionBanner />
        {me.data?.emergency?.read_only && !support && <ReadOnlyNotice />}
        <HotelBanners />
        <Topbar onOpenMenu={() => setMenuOpen(true)} />
        <main className="flex-1 px-4 py-8 md:px-6 lg:px-8">
          {/* Each page fades in when it opens. */}
          <div key={pathname} className="mx-auto w-full max-w-7xl animate-page-in motion-reduce:animate-none">
            <Outlet />
          </div>
        </main>
      </div>
      <ScreenLock />
    </div>
  )
}
