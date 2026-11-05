import { useState } from 'react'
import { Outlet, useLocation } from 'react-router'
import { useSidebarCollapsed } from '@/lib/sidebar'
import { SessionBanner } from './SessionBanner'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'

export function AppShell() {
  const [menuOpen, setMenuOpen] = useState(false)
  const { collapsed, toggle } = useSidebarCollapsed()
  const { pathname } = useLocation()
  return (
    <div className="flex min-h-full">
      <Sidebar open={menuOpen} collapsed={collapsed} onClose={() => setMenuOpen(false)} onToggleCollapsed={toggle} />
      <div className="flex min-w-0 flex-1 flex-col">
        <SessionBanner />
        <Topbar onOpenMenu={() => setMenuOpen(true)} />
        <main className="flex-1 px-4 py-8 md:px-6 lg:px-8">
          {/* Each page fades in when it opens. */}
          <div key={pathname} className="mx-auto w-full max-w-7xl animate-page-in motion-reduce:animate-none">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}
