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
}

const byPath: Record<string, () => Promise<unknown>> = {
  '/': pages.dashboard,
  '/admin': pages.adminDashboard,
  '/admin/hotels': pages.hotels,
  '/admin/plans': pages.plans,
  '/admin/settings': pages.platformSettings,
  '/design-system': pages.designSystem,
  '/staff': pages.staff,
  '/roles': pages.roles,
  '/activity-log': pages.activityLog,
  '/settings': pages.settings,
}

const done = new Set<string>()

export function prefetchRoute(path: string): void {
  const load = byPath[path]
  if (!load || done.has(path)) return
  done.add(path)
  void load().catch(() => done.delete(path))
}
