import type { ComponentType, SVGProps } from 'react'
import {
  AdjustmentsHorizontalIcon,
  AdjustmentsVerticalIcon,
  CheckBadgeIcon,
  NewspaperIcon,
  RocketLaunchIcon,
  LifebuoyIcon,
  ServerStackIcon,
  ListBulletIcon,
  UserPlusIcon,
  BanknotesIcon,
  BuildingOffice2Icon,
  CalendarDaysIcon,
  ChartBarIcon,
  ClipboardDocumentListIcon,
  ClockIcon,
  Cog6ToothIcon,
  ComputerDesktopIcon,
  DocumentTextIcon,
  FingerPrintIcon,
  HomeIcon,
  SparklesIcon,
  ShieldCheckIcon,
  Squares2X2Icon,
  SwatchIcon,
  TagIcon,
  UserGroupIcon,
  UsersIcon,
} from '@heroicons/react/24/outline'
import {
  AdjustmentsHorizontalIcon as AdjustmentsHorizontalSolid,
  AdjustmentsVerticalIcon as AdjustmentsVerticalSolid,
  CheckBadgeIcon as CheckBadgeSolid,
  NewspaperIcon as NewspaperSolid,
  RocketLaunchIcon as RocketLaunchSolid,
  LifebuoyIcon as LifebuoySolid,
  ServerStackIcon as ServerStackSolid,
  ListBulletIcon as ListBulletSolid,
  UserPlusIcon as UserPlusSolid,
  BanknotesIcon as BanknotesSolid,
  BuildingOffice2Icon as BuildingOffice2Solid,
  CalendarDaysIcon as CalendarDaysSolid,
  ChartBarIcon as ChartBarSolid,
  ClipboardDocumentListIcon as ClipboardDocumentListSolid,
  ClockIcon as ClockSolid,
  Cog6ToothIcon as Cog6ToothSolid,
  ComputerDesktopIcon as ComputerDesktopSolid,
  DocumentTextIcon as DocumentTextSolid,
  FingerPrintIcon as FingerPrintSolid,
  HomeIcon as HomeSolid,
  SparklesIcon as SparklesSolid,
  ShieldCheckIcon as ShieldCheckSolid,
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
  /** Hidden unless the user's role has this permission. */
  permission?: string | readonly string[]
  /** Build-plan phase that delivers this screen (for placeholders). */
  phase: number
  /** Optional module: hidden when the hotel turns it off (phase 2). */
  module?: 'cashier' | 'housekeeping' | 'shifts' | 'attendance'
  /** Real screen exists (not a placeholder). */
  built?: boolean
}

export const mainNav: NavItem[] = [
  { key: 'dashboard', path: '/', icon: HomeIcon, activeIcon: HomeSolid, phase: 6, built: true },
  { key: 'roomBoard', path: '/rooms', icon: Squares2X2Icon, activeIcon: Squares2X2Solid, group: 'frontDesk', phase: 4, permission: 'frontdesk.view', built: true },
  { key: 'reservations', path: '/reservations', icon: CalendarDaysIcon, activeIcon: CalendarDaysSolid, group: 'frontDesk', phase: 3, permission: 'reservations.view', built: true },
  { key: 'guests', path: '/guests', icon: UsersIcon, activeIcon: UsersSolid, group: 'frontDesk', phase: 3, permission: 'guests.view', built: true },
  { key: 'billing', path: '/billing', icon: DocumentTextIcon, activeIcon: DocumentTextSolid, group: 'frontDesk', phase: 4, permission: 'invoices.view', built: true },
  { key: 'cashier', path: '/cashier', icon: BanknotesIcon, activeIcon: BanknotesSolid, group: 'operations', phase: 5, module: 'cashier', permission: ['cashier.own_shift', 'cashier.view_all'], built: true },
  { key: 'housekeeping', path: '/housekeeping', icon: SparklesIcon, activeIcon: SparklesSolid, group: 'operations', phase: 5, module: 'housekeeping', permission: 'housekeeping.view', built: true },
  { key: 'shifts', path: '/shifts', icon: ClockIcon, activeIcon: ClockSolid, group: 'operations', phase: 5, module: 'shifts', permission: 'shifts.view_own', built: true },
  { key: 'attendance', path: '/attendance', icon: FingerPrintIcon, activeIcon: FingerPrintSolid, group: 'operations', phase: 5, module: 'attendance', permission: ['attendance.clock', 'attendance.report'], built: true },
  { key: 'staff', path: '/staff', icon: UserGroupIcon, activeIcon: UserGroupSolid, group: 'team', phase: 1, permission: 'staff.view', built: true },
  { key: 'devices', path: '/devices', icon: ComputerDesktopIcon, activeIcon: ComputerDesktopSolid, group: 'team', phase: 7, permission: 'staff.manage', built: true },
  { key: 'roles', path: '/roles', icon: ShieldCheckIcon, activeIcon: ShieldCheckSolid, group: 'team', phase: 1, permission: 'roles.manage', built: true },
  { key: 'activityLog', path: '/activity-log', icon: ClipboardDocumentListIcon, activeIcon: ClipboardDocumentListSolid, group: 'team', phase: 1, permission: 'activity_log.view', built: true },
  { key: 'approvals', path: '/approvals', icon: CheckBadgeIcon, activeIcon: CheckBadgeSolid, group: 'insights', phase: 8, permission: 'approvals.decide', built: true },
  { key: 'reports', path: '/reports', icon: ChartBarIcon, activeIcon: ChartBarSolid, group: 'insights', phase: 6, built: true, permission: ['reports.revenue', 'reports.occupancy', 'reports.payments', 'reports.outstanding', 'reports.tax', 'reports.guests', 'reports.cancellations', 'reports.branch_compare', 'reports.my_shift', 'reports.my_work', 'reports.forecast', 'reports.audit', 'reports.accounting_export', 'reports.targets_manage', 'reports.custom', 'reports.schedule', 'alerts.manage'] },
  {
    key: 'settings',
    path: '/settings',
    icon: Cog6ToothIcon,
    activeIcon: Cog6ToothSolid,
    group: 'insights',
    phase: 2,
    built: true,
    permission: ['settings.hotel', 'settings.branches', 'settings.money', 'settings.exchange_rates', 'settings.charges', 'settings.policies', 'settings.modules', 'subscription.view', 'automations.manage'],
  },
]

/** The hotel module that turns a menu item on (menu name → module key). */
export const NAV_MODULES = { cashier: 'cashier_close', housekeeping: 'housekeeping', shifts: 'shifts', attendance: 'attendance' } as const

export const devNav: NavItem[] = [
  { key: 'designSystem', path: '/design-system', icon: SwatchIcon, activeIcon: SwatchSolid, group: 'developer', phase: 0 },
]

/** Super Admin (HotelMS team) area. */
export const adminNav: NavItem[] = [
  { key: 'commandCenter', path: '/admin', icon: HomeIcon, activeIcon: HomeSolid, phase: 1 },
  { key: 'hotels', path: '/admin/hotels', icon: BuildingOffice2Icon, activeIcon: BuildingOffice2Solid, group: 'customers', phase: 1 },
  { key: 'support', path: '/admin/support', icon: LifebuoyIcon, activeIcon: LifebuoySolid, group: 'customers', phase: 8, permission: 'support.answer' },
  { key: 'platformReports', path: '/admin/reports', icon: ChartBarIcon, activeIcon: ChartBarSolid, group: 'customers', phase: 1 },
  { key: 'plans', path: '/admin/plans', icon: TagIcon, activeIcon: TagSolid, group: 'billing', phase: 1 },
  { key: 'switches', path: '/admin/switches', icon: AdjustmentsVerticalIcon, activeIcon: AdjustmentsVerticalSolid, group: 'system', phase: 8, permission: 'switches.manage' },
  { key: 'releases', path: '/admin/releases', icon: RocketLaunchIcon, activeIcon: RocketLaunchSolid, group: 'system', phase: 8, permission: 'releases.manage' },
  { key: 'content', path: '/admin/content', icon: NewspaperIcon, activeIcon: NewspaperSolid, group: 'system', phase: 8, permission: 'content.manage' },
  { key: 'system', path: '/admin/system', icon: ServerStackIcon, activeIcon: ServerStackSolid, group: 'system', phase: 8, permission: 'system.view' },
  { key: 'platformActivity', path: '/admin/activity', icon: ListBulletIcon, activeIcon: ListBulletSolid, group: 'system', phase: 8, permission: 'system.view' },
  { key: 'team', path: '/admin/team', icon: UserPlusIcon, activeIcon: UserPlusSolid, group: 'system', phase: 8, permission: 'team.manage' },
  { key: 'platformSettings', path: '/admin/settings', icon: AdjustmentsHorizontalIcon, activeIcon: AdjustmentsHorizontalSolid, group: 'system', phase: 1 },
]
