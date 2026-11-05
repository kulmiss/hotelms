/**
 * Screens are loaded only when needed (small first download), and prefetched
 * when a link is hovered or focused so opening them feels instant.
 */
export const pages = {
  dashboard: () => import('@/pages/DashboardPage'),
  adminDashboard: () => import('@/pages/admin/AdminDashboardPage'),
  hotels: () => import('@/pages/admin/HotelsPage'),
  newHotel: () => import('@/pages/admin/NewHotelPage'),
  hotel: () => import('@/pages/admin/HotelPage'),
  plans: () => import('@/pages/admin/PlansPage'),
  platformSettings: () => import('@/pages/admin/PlatformSettingsPage'),
  platformReports: () => import('@/pages/admin/PlatformReportsPage'),
  designSystem: () => import('@/pages/DesignSystemPage'),
  profile: () => import('@/pages/ProfilePage'),
  staff: () => import('@/pages/team/StaffPage'),
  staffForm: () => import('@/pages/team/StaffFormPage'),
  roles: () => import('@/pages/team/RolesPage'),
  roleForm: () => import('@/pages/team/RoleFormPage'),
  activityLog: () => import('@/pages/team/ActivityLogPage'),
  settingsLayout: () => import('@/pages/settings/SettingsLayout'),
  settings: () => import('@/pages/settings/SettingsSections'),
  roomSettings: () => import('@/pages/settings/RoomSections'),
  setup: () => import('@/pages/setup/SetupPage'),
  guests: () => import('@/pages/guests/GuestsPage'),
  reservations: () => import('@/pages/reservations/ReservationsPage'),
  reservation: () => import('@/pages/reservations/ReservationPage'),
  reservationForm: () => import('@/pages/reservations/ReservationFormPage'),
  roomBoard: () => import('@/pages/desk/RoomBoardPage'),
  walkIn: () => import('@/pages/desk/WalkInPage'),
  billing: () => import('@/pages/billing/BillingPage'),
  invoice: () => import('@/pages/billing/InvoicePage'),
  invoicePrint: () => import('@/pages/billing/InvoicePrintPage'),
  cashier: () => import('@/pages/modules/CashierPage'),
  housekeeping: () => import('@/pages/modules/HousekeepingPage'),
  shifts: () => import('@/pages/modules/ShiftsPage'),
  attendance: () => import('@/pages/modules/AttendancePage'),
  kiosk: () => import('@/pages/modules/KioskPage'),
  reports: () => import('@/pages/reports/ReportsPage'),
}

const byPath: Record<string, () => Promise<unknown>> = {
  '/': pages.dashboard,
  '/admin': pages.adminDashboard,
  '/admin/hotels': pages.hotels,
  '/admin/plans': pages.plans,
  '/admin/settings': pages.platformSettings,
  '/admin/reports': pages.platformReports,
  '/design-system': pages.designSystem,
  '/staff': pages.staff,
  '/roles': pages.roles,
  '/activity-log': pages.activityLog,
  '/settings': pages.settings,
  '/guests': pages.guests,
  '/reservations': pages.reservations,
  '/rooms': pages.roomBoard,
  '/billing': pages.billing,
  '/cashier': pages.cashier,
  '/housekeeping': pages.housekeeping,
  '/shifts': pages.shifts,
  '/attendance': pages.attendance,
  '/reports': pages.reports,
}

const done = new Set<string>()

export function prefetchRoute(path: string): void {
  const load = byPath[path]
  if (!load || done.has(path)) return
  done.add(path)
  void load().catch(() => done.delete(path))
}
