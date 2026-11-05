import { useState } from 'react'
import { Outlet, useLocation } from 'react-router'
import { useSidebarCollapsed } from '@/lib/sidebar'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'

export function AppShell() {
  const [menuOpen, setMenuOpen] = useState(false)
  const { collapsed, toggle } = useSidebarCollapsed()
  const { pathname } = useLocation()
  return (
    <div className="flex h-full">
      <Sidebar open={menuOpen} collapsed={collapsed} onClose={() => setMenuOpen(false)} onToggleCollapsed={toggle} />
      <div className="flex min-w-0 flex-1 flex-col">
        <Topbar onOpenMenu={() => setMenuOpen(true)} />
        <main className="flex-1 overflow-y-auto px-4 py-6 md:px-8 md:py-8">
          {/* Each page glides in once when it opens. */}
          <div key={pathname} className="animate-page-in motion-reduce:animate-none">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}
