import { lazy, Suspense, type ReactNode } from 'react'
import { createBrowserRouter, Navigate, Outlet } from 'react-router'
import { AppShell } from '@/components/layout/AppShell'
import { RequireAuth } from '@/components/layout/RequireAuth'
import { useMe } from '@/lib/auth'
import { ChangePasswordPage } from '@/pages/ChangePasswordPage'
import { LoginPage } from '@/pages/LoginPage'
import { NotFoundPage } from '@/pages/NotFoundPage'
import { PlaceholderPage } from '@/pages/PlaceholderPage'
import { mainNav } from './navigation'
import { pages } from './prefetch'

const DashboardPage = lazy(() => pages.dashboard().then((m) => ({ default: m.DashboardPage })))
const AdminDashboardPage = lazy(() => pages.adminDashboard().then((m) => ({ default: m.AdminDashboardPage })))
const HotelsPage = lazy(() => pages.hotels().then((m) => ({ default: m.HotelsPage })))
const NewHotelPage = lazy(() => pages.newHotel().then((m) => ({ default: m.NewHotelPage })))
const HotelPage = lazy(() => pages.hotel().then((m) => ({ default: m.HotelPage })))
const PlansPage = lazy(() => pages.plans().then((m) => ({ default: m.PlansPage })))
const PlatformSettingsPage = lazy(() => pages.platformSettings().then((m) => ({ default: m.PlatformSettingsPage })))
const DesignSystemPage = lazy(() => pages.designSystem().then((m) => ({ default: m.DesignSystemPage })))

/** Shown for the split second a screen is still downloading. */
const page = (node: ReactNode) => <Suspense fallback={<div className="min-h-40" aria-busy="true" />}>{node}</Suspense>

/** The HotelMS team goes to the Super Admin area; hotel staff to their hotel. */
function HomeRedirect() {
  const me = useMe()
  return me.data?.user.is_super_admin ? <Navigate to="/admin" replace /> : page(<DashboardPage />)
}

/** Only HotelMS team members may open /admin. */
function RequireSuperAdmin() {
  const me = useMe()
  return me.data?.user.is_super_admin ? <Outlet /> : <Navigate to="/" replace />
}

export const routes = [
  { path: '/login', element: <LoginPage /> },
  {
    element: <RequireAuth />,
    children: [
      { path: '/change-password', element: <ChangePasswordPage /> },
      {
        path: '/',
        element: <AppShell />,
        children: [
          { index: true, element: <HomeRedirect /> },
          ...mainNav
            .filter((item) => item.path !== '/')
            .map((item) => ({ path: item.path.slice(1), element: <PlaceholderPage item={item} /> })),
          {
            path: 'admin',
            element: <RequireSuperAdmin />,
            children: [
              { index: true, element: page(<AdminDashboardPage />) },
              { path: 'hotels', element: page(<HotelsPage />) },
              { path: 'hotels/new', element: page(<NewHotelPage />) },
              { path: 'hotels/:id', element: page(<HotelPage />) },
              { path: 'plans', element: page(<PlansPage />) },
              { path: 'settings', element: page(<PlatformSettingsPage />) },
            ],
          },
          { path: 'design-system', element: page(<DesignSystemPage />) },
          { path: '*', element: <NotFoundPage /> },
        ],
      },
    ],
  },
]

export const router = createBrowserRouter(routes)
