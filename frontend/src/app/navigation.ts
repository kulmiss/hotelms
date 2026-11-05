import type { ComponentType, SVGProps } from 'react'
import {
  AdjustmentsHorizontalIcon,
  BanknotesIcon,
  BuildingOffice2Icon,
  CalendarDaysIcon,
  ChartBarIcon,
  Cog6ToothIcon,
  DocumentTextIcon,
  HomeIcon,
  SparklesIcon,
  Squares2X2Icon,
  SwatchIcon,
  TagIcon,
  UserGroupIcon,
  UsersIcon,
} from '@heroicons/react/24/outline'
import {
  AdjustmentsHorizontalIcon as AdjustmentsHorizontalSolid,
  BanknotesIcon as BanknotesSolid,
  BuildingOffice2Icon as BuildingOffice2Solid,
  CalendarDaysIcon as CalendarDaysSolid,
  ChartBarIcon as ChartBarSolid,
  Cog6ToothIcon as Cog6ToothSolid,
  DocumentTextIcon as DocumentTextSolid,
  HomeIcon as HomeSolid,
  SparklesIcon as SparklesSolid,
  Squares2X2Icon as Squares2X2Solid,
  SwatchIcon as SwatchSolid,
  TagIcon as TagSolid,
  UserGroupIcon as UserGroupSolid,
  UsersIcon as UsersSolid,
} from '@heroicons/react/24/solid'

export type IconType = ComponentType<SVGProps<SVGSVGElement>>

export type NavItem = {
  key: string
  path: string
  /** Outline icon; the solid one is shown when the page is open (Filament style). */
  icon: IconType
  activeIcon: IconType
  /** Sidebar group heading (translation key under nav.groups). */
  group?: string
  /** Build-plan phase that delivers this screen (for placeholders). */
  phase: number
  /** Optional module: hidden when the hotel turns it off (phase 2). */
  module?: 'cashier' | 'housekeeping' | 'staff'
}

export const mainNav: NavItem[] = [
  { key: 'dashboard', path: '/', icon: HomeIcon, activeIcon: HomeSolid, phase: 6 },
  { key: 'roomBoard', path: '/rooms', icon: Squares2X2Icon, activeIcon: Squares2X2Solid, group: 'frontDesk', phase: 4 },
  { key: 'reservations', path: '/reservations', icon: CalendarDaysIcon, activeIcon: CalendarDaysSolid, group: 'frontDesk', phase: 3 },
  { key: 'guests', path: '/guests', icon: UsersIcon, activeIcon: UsersSolid, group: 'frontDesk', phase: 3 },
  { key: 'billing', path: '/billing', icon: DocumentTextIcon, activeIcon: DocumentTextSolid, group: 'frontDesk', phase: 4 },
  { key: 'cashier', path: '/cashier', icon: BanknotesIcon, activeIcon: BanknotesSolid, group: 'operations', phase: 5, module: 'cashier' },
  { key: 'housekeeping', path: '/housekeeping', icon: SparklesIcon, activeIcon: SparklesSolid, group: 'operations', phase: 5, module: 'housekeeping' },
  { key: 'staff', path: '/staff', icon: UserGroupIcon, activeIcon: UserGroupSolid, group: 'operations', phase: 5, module: 'staff' },
  { key: 'reports', path: '/reports', icon: ChartBarIcon, activeIcon: ChartBarSolid, group: 'insights', phase: 6 },
  { key: 'settings', path: '/settings', icon: Cog6ToothIcon, activeIcon: Cog6ToothSolid, group: 'insights', phase: 2 },
]

export const devNav: NavItem[] = [
  { key: 'designSystem', path: '/design-system', icon: SwatchIcon, activeIcon: SwatchSolid, group: 'developer', phase: 0 },
]

/** Super Admin (HotelMS team) area. */
export const adminNav: NavItem[] = [
  { key: 'commandCenter', path: '/admin', icon: HomeIcon, activeIcon: HomeSolid, phase: 1 },
  { key: 'hotels', path: '/admin/hotels', icon: BuildingOffice2Icon, activeIcon: BuildingOffice2Solid, group: 'customers', phase: 1 },
  { key: 'plans', path: '/admin/plans', icon: TagIcon, activeIcon: TagSolid, group: 'billing', phase: 1 },
  { key: 'platformSettings', path: '/admin/settings', icon: AdjustmentsHorizontalIcon, activeIcon: AdjustmentsHorizontalSolid, group: 'system', phase: 1 },
]
