import {
  BarChart3,
  Building2,
  Gauge,
  SlidersHorizontal,
  Tags,
  BedDouble,
  CalendarDays,
  LayoutDashboard,
  Palette,
  Receipt,
  Settings,
  Sparkles,
  Users,
  UserCog,
  Wallet,
  type LucideIcon,
} from 'lucide-react'

export type NavItem = {
  key: string
  path: string
  icon: LucideIcon
  /** Build-plan phase that delivers this screen (for placeholders). */
  phase: number
  /** Optional module: hidden when the hotel turns it off (phase 2). */
  module?: 'cashier' | 'housekeeping' | 'staff'
}

export const mainNav: NavItem[] = [
  { key: 'dashboard', path: '/', icon: LayoutDashboard, phase: 6 },
  { key: 'roomBoard', path: '/rooms', icon: BedDouble, phase: 4 },
  { key: 'reservations', path: '/reservations', icon: CalendarDays, phase: 3 },
  { key: 'guests', path: '/guests', icon: Users, phase: 3 },
  { key: 'billing', path: '/billing', icon: Receipt, phase: 4 },
  { key: 'cashier', path: '/cashier', icon: Wallet, phase: 5, module: 'cashier' },
  { key: 'housekeeping', path: '/housekeeping', icon: Sparkles, phase: 5, module: 'housekeeping' },
  { key: 'staff', path: '/staff', icon: UserCog, phase: 5, module: 'staff' },
  { key: 'reports', path: '/reports', icon: BarChart3, phase: 6 },
  { key: 'settings', path: '/settings', icon: Settings, phase: 2 },
]

export const devNav: NavItem[] = [{ key: 'designSystem', path: '/design-system', icon: Palette, phase: 0 }]

/** Super Admin (HotelMS team) area. */
export const adminNav: NavItem[] = [
  { key: 'commandCenter', path: '/admin', icon: Gauge, phase: 1 },
  { key: 'hotels', path: '/admin/hotels', icon: Building2, phase: 1 },
  { key: 'plans', path: '/admin/plans', icon: Tags, phase: 1 },
  { key: 'platformSettings', path: '/admin/settings', icon: SlidersHorizontal, phase: 1 },
]
