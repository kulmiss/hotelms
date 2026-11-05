import { lazy, Suspense, type ComponentType, type ReactNode } from 'react'
import { createBrowserRouter, Navigate, Outlet } from 'react-router'
import { AppShell } from '@/components/layout/AppShell'
import { ProgressBar } from '@/components/layout/ProgressBar'
import { RequireAuth } from '@/components/layout/RequireAuth'
import { useMe } from '@/lib/auth'
import { useCan } from '@/lib/hotel'
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
const ProfilePage = lazy(() => pages.profile().then((m) => ({ default: m.ProfilePage })))
const StaffPage = lazy(() => pages.staff().then((m) => ({ default: m.StaffPage })))
const StaffFormPage = lazy(() => pages.staffForm().then((m) => ({ default: m.StaffFormPage })))
const RolesPage = lazy(() => pages.roles().then((m) => ({ default: m.RolesPage })))
const RoleFormPage = lazy(() => pages.roleForm().then((m) => ({ default: m.RoleFormPage })))
const ActivityLogPage = lazy(() => pages.activityLog().then((m) => ({ default: m.ActivityLogPage })))
const SettingsLayout = lazy(() => pages.settingsLayout().then((m) => ({ default: m.SettingsLayout })))
const section = (name: string) => lazy(() => pages.settings().then((m) => ({ default: (m as unknown as Record<string, ComponentType>)[name] })))
const SETTINGS_PAGES: Record<string, ReturnType<typeof section>> = {
  profile: section('ProfileSettings'),
  branches: section('BranchesSettings'),
  currencies: section('CurrenciesSettings'),
  taxes: section('TaxesSettings'),
  'payment-methods': section('PaymentMethodsSettings'),
  charges: section('ChargesSettings'),
  'booking-rules': section('BookingRulesSettings'),
  cancellation: section('CancellationSettings'),
  modules: section('ModulesSettings'),
  attendance: section('AttendanceSettings'),
  subscription: section('SubscriptionSettings'),
}
const DesignSystemPage = lazy(() => pages.designSystem().then((m) => ({ default: m.DesignSystemPage })))

/** Shown for the split second a screen is still downloading. */
const page = (node: ReactNode) => <Suspense fallback={<ProgressBar />}>{node}</Suspense>

/** The HotelMS team goes to the Super Admin area; hotel staff to their hotel. */
function HomeRedirect() {
  const me = useMe()
  return me.data?.user.is_super_admin ? <Navigate to="/admin" replace /> : page(<DashboardPage />)
}

/** Hotel screens: only for users whose role has the permission. */
function RequirePermission({ permission }: { permission: string }) {
  const can = useCan()
  return can(permission) ? <Outlet /> : <Navigate to="/" replace />
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
            .filter((item) => !item.built)
            .map((item) => ({ path: item.path.slice(1), element: <PlaceholderPage item={item} /> })),
          {
            element: <RequirePermission permission="staff.view" />,
            children: [
              { path: 'staff', element: page(<StaffPage />) },
              { path: 'staff/new', element: page(<StaffFormPage />) },
              { path: 'staff/:id', element: page(<StaffFormPage />) },
            ],
          },
          {
            element: <RequirePermission permission="roles.manage" />,
            children: [
              { path: 'roles', element: page(<RolesPage />) },
              { path: 'roles/new', element: page(<RoleFormPage />) },
              { path: 'roles/:id', element: page(<RoleFormPage />) },
            ],
          },
          {
            path: 'settings',
            element: page(<SettingsLayout />),
            children: Object.entries(SETTINGS_PAGES).map(([key, Page]) => ({
              path: key,
              element: page(<Page />),
            })),
          },
          {
            element: <RequirePermission permission="activity_log.view" />,
            children: [{ path: 'activity-log', element: page(<ActivityLogPage />) }],
          },
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
          { path: 'profile', element: page(<ProfilePage />) },
          { path: 'design-system', element: page(<DesignSystemPage />) },
          { path: '*', element: <NotFoundPage /> },
        ],
      },
    ],
  },
]

export const router = createBrowserRouter(routes)
