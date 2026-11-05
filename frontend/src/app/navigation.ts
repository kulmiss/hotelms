import {
  Bed,
  Buildings,
  CalendarBlank,
  ChartBar,
  Gauge,
  GearSix,
  Palette,
  Receipt,
  SlidersHorizontal,
  Sparkle,
  SquaresFour,
  Tag,
  UserGear,
  UsersThree,
  Wallet,
  type Icon,
} from '@phosphor-icons/react'

export type NavItem = {
  key: string
  path: string
  icon: Icon
  /** Build-plan phase that delivers this screen (for placeholders). */
  phase: number
  /** Optional module: hidden when the hotel turns it off (phase 2). */
  module?: 'cashier' | 'housekeeping' | 'staff'
}

export const mainNav: NavItem[] = [
  { key: 'dashboard', path: '/', icon: SquaresFour, phase: 6 },
  { key: 'roomBoard', path: '/rooms', icon: Bed, phase: 4 },
  { key: 'reservations', path: '/reservations', icon: CalendarBlank, phase: 3 },
  { key: 'guests', path: '/guests', icon: UsersThree, phase: 3 },
  { key: 'billing', path: '/billing', icon: Receipt, phase: 4 },
  { key: 'cashier', path: '/cashier', icon: Wallet, phase: 5, module: 'cashier' },
  { key: 'housekeeping', path: '/housekeeping', icon: Sparkle, phase: 5, module: 'housekeeping' },
  { key: 'staff', path: '/staff', icon: UserGear, phase: 5, module: 'staff' },
  { key: 'reports', path: '/reports', icon: ChartBar, phase: 6 },
  { key: 'settings', path: '/settings', icon: GearSix, phase: 2 },
]

export const devNav: NavItem[] = [{ key: 'designSystem', path: '/design-system', icon: Palette, phase: 0 }]

/** Super Admin (HotelMS team) area. */
export const adminNav: NavItem[] = [
  { key: 'commandCenter', path: '/admin', icon: Gauge, phase: 1 },
  { key: 'hotels', path: '/admin/hotels', icon: Buildings, phase: 1 },
  { key: 'plans', path: '/admin/plans', icon: Tag, phase: 1 },
  { key: 'platformSettings', path: '/admin/settings', icon: SlidersHorizontal, phase: 1 },
]
