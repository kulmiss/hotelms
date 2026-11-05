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
const GuestsPage = lazy(() => pages.guests().then((m) => ({ default: m.GuestsPage })))
const GuestPage = lazy(() => pages.guests().then((m) => ({ default: m.GuestPage })))
const ReservationsPage = lazy(() => pages.reservations().then((m) => ({ default: m.ReservationsPage })))
const ReservationPage = lazy(() => pages.reservation().then((m) => ({ default: m.ReservationPage })))
const ReservationFormPage = lazy(() => pages.reservationForm().then((m) => ({ default: m.ReservationFormPage })))
const RoomBoardPage = lazy(() => pages.roomBoard().then((m) => ({ default: m.RoomBoardPage })))
const WalkInPage = lazy(() => pages.walkIn().then((m) => ({ default: m.WalkInPage })))
const BillingPage = lazy(() => pages.billing().then((m) => ({ default: m.BillingPage })))
const InvoicePage = lazy(() => pages.invoice().then((m) => ({ default: m.InvoicePage })))
const InvoicePrintPage = lazy(() => pages.invoicePrint().then((m) => ({ default: m.InvoicePrintPage })))
const CashierPage = lazy(() => pages.cashier().then((m) => ({ default: m.CashierPage })))
const HousekeepingPage = lazy(() => pages.housekeeping().then((m) => ({ default: m.HousekeepingPage })))
const ShiftsPage = lazy(() => pages.shifts().then((m) => ({ default: m.ShiftsPage })))
const AttendancePage = lazy(() => pages.attendance().then((m) => ({ default: m.AttendancePage })))
const KioskPage = lazy(() => pages.kiosk().then((m) => ({ default: m.KioskPage })))
const SetupPage = lazy(() => pages.setup().then((m) => ({ default: m.SetupPage })))
const SettingsLayout = lazy(() => pages.settingsLayout().then((m) => ({ default: m.SettingsLayout })))
const section = (name: string, load: () => Promise<unknown> = pages.settings) =>
  lazy(() => load().then((m) => ({ default: (m as Record<string, ComponentType>)[name] })))
const SETTINGS_PAGES: Record<string, ReturnType<typeof section>> = {
  profile: section('ProfileSettings'),
  branches: section('BranchesSettings'),
  'room-types': section('RoomTypesSettings', pages.roomSettings),
  rooms: section('RoomsSettings', pages.roomSettings),
  import: section('ImportSettings', pages.roomSettings),
  'rate-rules': section('RateRulesSettings', pages.roomSettings),
  'guest-types': section('GuestTypesSettings', pages.roomSettings),
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
function RequirePermission({ permission }: { permission: string | string[] }) {
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
      { path: '/setup', element: page(<SetupPage />) },
      {
        element: <RequirePermission permission="attendance.clock" />,
        children: [{ path: '/kiosk', element: page(<KioskPage />) }],
      },
      {
        element: <RequirePermission permission="invoices.print" />,
        children: [{ path: '/billing/:id/print', element: page(<InvoicePrintPage />) }],
      },
      {
        path: '/',
        element: <AppShell />,
        children: [
          { index: true, element: <HomeRedirect /> },
          ...mainNav
            .filter((item) => !item.built)
            .map((item) => ({ path: item.path.slice(1), element: <PlaceholderPage item={item} /> })),
          {
            element: <RequirePermission permission={['cashier.own_shift', 'cashier.view_all']} />,
            children: [{ path: 'cashier', element: page(<CashierPage />) }],
          },
          {
            element: <RequirePermission permission="housekeeping.view" />,
            children: [{ path: 'housekeeping', element: page(<HousekeepingPage />) }],
          },
          {
            element: <RequirePermission permission="shifts.view_own" />,
            children: [{ path: 'shifts', element: page(<ShiftsPage />) }],
          },
          {
            element: <RequirePermission permission={['attendance.clock', 'attendance.report']} />,
            children: [{ path: 'attendance', element: page(<AttendancePage />) }],
          },
          {
            element: <RequirePermission permission="frontdesk.view" />,
            children: [{ path: 'rooms', element: page(<RoomBoardPage />) }],
          },
          {
            element: <RequirePermission permission="frontdesk.walk_in" />,
            children: [{ path: 'rooms/walk-in', element: page(<WalkInPage />) }],
          },
          {
            element: <RequirePermission permission="invoices.view" />,
            children: [
              { path: 'billing', element: page(<BillingPage />) },
              { path: 'billing/:id', element: page(<InvoicePage />) },
            ],
          },
          {
            element: <RequirePermission permission="guests.view" />,
            children: [
              { path: 'guests', element: page(<GuestsPage />) },
              { path: 'guests/:id', element: page(<GuestPage />) },
            ],
          },
          {
            element: <RequirePermission permission="guests.create_edit" />,
            children: [{ path: 'guests/new', element: page(<GuestPage />) }],
          },
          {
            element: <RequirePermission permission="reservations.view" />,
            children: [
              { path: 'reservations', element: page(<ReservationsPage />) },
              { path: 'reservations/:id', element: page(<ReservationPage />) },
            ],
          },
          {
            element: <RequirePermission permission="reservations.create" />,
            children: [{ path: 'reservations/new', element: page(<ReservationFormPage />) }],
          },
          {
            element: <RequirePermission permission="reservations.edit" />,
            children: [{ path: 'reservations/:id/edit', element: page(<ReservationFormPage />) }],
          },
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
