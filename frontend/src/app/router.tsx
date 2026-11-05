import { createBrowserRouter, Navigate, Outlet } from 'react-router'
import { AppShell } from '@/components/layout/AppShell'
import { RequireAuth } from '@/components/layout/RequireAuth'
import { useMe } from '@/lib/auth'
import { AdminDashboardPage } from '@/pages/admin/AdminDashboardPage'
import { HotelPage } from '@/pages/admin/HotelPage'
import { HotelsPage } from '@/pages/admin/HotelsPage'
import { NewHotelPage } from '@/pages/admin/NewHotelPage'
import { PlansPage } from '@/pages/admin/PlansPage'
import { PlatformSettingsPage } from '@/pages/admin/PlatformSettingsPage'
import { ChangePasswordPage } from '@/pages/ChangePasswordPage'
import { DashboardPage } from '@/pages/DashboardPage'
import { DesignSystemPage } from '@/pages/DesignSystemPage'
import { LoginPage } from '@/pages/LoginPage'
import { NotFoundPage } from '@/pages/NotFoundPage'
import { PlaceholderPage } from '@/pages/PlaceholderPage'
import { mainNav } from './navigation'

/** The HotelMS team goes to the Super Admin area; hotel staff to their hotel. */
function HomeRedirect() {
  const me = useMe()
  return me.data?.user.is_super_admin ? <Navigate to="/admin" replace /> : <DashboardPage />
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
              { index: true, element: <AdminDashboardPage /> },
              { path: 'hotels', element: <HotelsPage /> },
              { path: 'hotels/new', element: <NewHotelPage /> },
              { path: 'hotels/:id', element: <HotelPage /> },
              { path: 'plans', element: <PlansPage /> },
              { path: 'settings', element: <PlatformSettingsPage /> },
            ],
          },
          { path: 'design-system', element: <DesignSystemPage /> },
          { path: '*', element: <NotFoundPage /> },
        ],
      },
    ],
  },
]

export const router = createBrowserRouter(routes)
