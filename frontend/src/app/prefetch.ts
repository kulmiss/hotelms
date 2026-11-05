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
}

const byPath: Record<string, () => Promise<unknown>> = {
  '/': pages.dashboard,
  '/admin': pages.adminDashboard,
  '/admin/hotels': pages.hotels,
  '/admin/plans': pages.plans,
  '/admin/settings': pages.platformSettings,
  '/design-system': pages.designSystem,
}

const done = new Set<string>()

export function prefetchRoute(path: string): void {
  const load = byPath[path]
  if (!load || done.has(path)) return
  done.add(path)
  void load().catch(() => done.delete(path))
}
