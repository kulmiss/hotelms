import { Outlet, useLocation } from 'react-router'
import { useTranslation } from 'react-i18next'
import { readSidebarOpen, SidebarInset, SidebarProvider } from '@/components/shadcn/sidebar'
import { useMe } from '@/lib/auth'
import { AppSidebar } from './AppSidebar'
import { ConnectionBanner } from './ConnectionBanner'
import { Header } from './Header'
import { HotelBanners } from './HotelBanners'
import { Maintenance, ReadOnlyNotice } from './Maintenance'
import { ScreenLock } from './ScreenLock'
import { SessionBanner } from './SessionBanner'
import { WeakPasswordBanner } from './WeakPasswordBanner'

/** Keyboard users jump past the menu straight to the page (template). */
function SkipToMain() {
  const { t } = useTranslation()
  return (
    <a
      href="#content"
      className="fixed inset-s-44 z-999 -translate-y-52 bg-primary px-4 py-2 text-sm font-medium whitespace-nowrap text-primary-foreground opacity-95 shadow-sm transition hover:bg-primary/90 focus:translate-y-3 focus:transform focus-visible:ring-1 focus-visible:ring-focus"
    >
      {t('topbar.skipToMain')}
    </a>
  )
}

/** The signed-in app (hotel staff and Super Admin): sidebar, banners, header and the page. */
export function AppShell() {
  const { pathname } = useLocation()
  const me = useMe()
  const support = Boolean(me.data?.impersonation)
  if (me.data?.emergency?.maintenance && !support) return <Maintenance />
  return (
    <SidebarProvider defaultOpen={readSidebarOpen()}>
      <SkipToMain />
      <AppSidebar />
      <SidebarInset className="@container/content">
        {/* Notices from HotelMS, support, demo, offline: strips above the header. */}
        <div className="flex flex-col overflow-hidden empty:hidden md:rounded-t-xl">
          <ConnectionBanner />
          <SessionBanner />
          <WeakPasswordBanner />
          {me.data?.emergency?.read_only && !support && <ReadOnlyNotice />}
          <HotelBanners />
        </div>
        <Header />
        <main id="content" tabIndex={-1} className="flex-1 px-4 py-6 outline-none @7xl/content:mx-auto @7xl/content:w-full @7xl/content:max-w-7xl">
          {/* Each page fades in when it opens. */}
          <div key={pathname} className="animate-page-in motion-reduce:animate-none">
            <Outlet />
          </div>
        </main>
      </SidebarInset>
      <ScreenLock />
    </SidebarProvider>
  )
}
