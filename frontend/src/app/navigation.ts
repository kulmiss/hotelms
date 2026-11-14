import type { ComponentType } from 'react'
import {
  BadgeCheck,
  Banknote,
  BookOpen,
  Building2,
  CalendarDays,
  ChartColumn,
  ClipboardList,
  Clock,
  FileText,
  Fingerprint,
  House,
  LayoutGrid,
  LifeBuoy,
  List,
  Monitor,
  Newspaper,
  Palette,
  Rocket,
  Server,
  Settings,
  ShieldAlert,
  ShieldCheck,
  ShoppingBag,
  SlidersHorizontal,
  SlidersVertical,
  Sparkles,
  Tag,
  UserPlus,
  Users,
  UsersRound,
} from 'lucide-react'

/** Any icon component (Lucide in the app; each icon is imported one by one so only the used ones are downloaded). */
export type IconType = ComponentType<{ className?: string; 'aria-hidden'?: boolean | 'true' | 'false' }>

export type NavItem = {
  key: string
  path: string
  /** Lucide icon (template style: the same icon open or not; the open page gets a grey background). */
  icon: IconType
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

/** Any of these opens Reports (menu item and the screen itself). */
export const REPORTS_PERMISSIONS = ['reports.revenue', 'reports.occupancy', 'reports.payments', 'reports.outstanding', 'reports.tax', 'reports.guests', 'reports.cancellations', 'reports.branch_compare', 'reports.my_shift', 'reports.my_work', 'reports.forecast', 'reports.audit', 'reports.accounting_export', 'reports.targets_manage', 'reports.custom', 'reports.schedule', 'alerts.manage']

export const mainNav: NavItem[] = [
  { key: 'dashboard', path: '/dashboard', icon: House, phase: 6, built: true },
  { key: 'roomBoard', path: '/rooms', icon: LayoutGrid, group: 'frontDesk', phase: 4, permission: 'frontdesk.view', built: true },
  { key: 'chargeRoom', path: '/charge', icon: ShoppingBag, group: 'frontDesk', phase: 4, permission: 'charges.room', built: true },
  { key: 'reservations', path: '/reservations', icon: CalendarDays, group: 'frontDesk', phase: 3, permission: 'reservations.view', built: true },
  { key: 'bookingRequests', path: '/booking-requests', icon: ClipboardList, group: 'frontDesk', phase: 3, permission: 'reservations.view', built: true },
  { key: 'guests', path: '/guests', icon: Users, group: 'frontDesk', phase: 3, permission: 'guests.view', built: true },
  { key: 'billing', path: '/billing', icon: FileText, group: 'frontDesk', phase: 4, permission: 'invoices.view', built: true },
  { key: 'cashier', path: '/cashier', icon: Banknote, group: 'operations', phase: 5, module: 'cashier', permission: ['cashier.own_shift', 'cashier.view_all'], built: true },
  { key: 'housekeeping', path: '/housekeeping', icon: Sparkles, group: 'operations', phase: 5, module: 'housekeeping', permission: 'housekeeping.view', built: true },
  { key: 'shifts', path: '/shifts', icon: Clock, group: 'operations', phase: 5, module: 'shifts', permission: 'shifts.view_own', built: true },
  { key: 'attendance', path: '/attendance', icon: Fingerprint, group: 'operations', phase: 5, module: 'attendance', permission: ['attendance.clock', 'attendance.report'], built: true },
  { key: 'staff', path: '/staff', icon: UsersRound, group: 'team', phase: 1, permission: 'staff.view', built: true },
  { key: 'devices', path: '/devices', icon: Monitor, group: 'team', phase: 7, permission: 'staff.manage', built: true },
  { key: 'roles', path: '/roles', icon: ShieldCheck, group: 'team', phase: 1, permission: 'roles.manage', built: true },
  { key: 'activityLog', path: '/activity-log', icon: ClipboardList, group: 'team', phase: 1, permission: 'activity_log.view', built: true },
  { key: 'approvals', path: '/approvals', icon: BadgeCheck, group: 'insights', phase: 8, permission: 'approvals.decide', built: true },
  { key: 'reports', path: '/reports', icon: ChartColumn, group: 'insights', phase: 6, built: true, permission: REPORTS_PERMISSIONS },
  {
    key: 'settings',
    path: '/settings',
    icon: Settings,
    group: 'insights',
    phase: 2,
    built: true,
    permission: ['settings.hotel', 'settings.branches', 'settings.money', 'settings.exchange_rates', 'settings.charges', 'settings.policies', 'settings.modules', 'subscription.view', 'automations.manage'],
  },
]

/** The hotel module that turns a menu item on (menu name → module key). */
export const NAV_MODULES = { cashier: 'cashier_close', housekeeping: 'housekeeping', shifts: 'shifts', attendance: 'attendance' } as const

export const devNav: NavItem[] = [
  { key: 'designSystem', path: '/design-system', icon: Palette, group: 'developer', phase: 0 },
]

/** Super Admin (HotelMS team) area. */
export const adminNav: NavItem[] = [
  { key: 'commandCenter', path: '/admin', icon: House, phase: 1 },
  { key: 'hotels', path: '/admin/hotels', icon: Building2, group: 'customers', phase: 1 },
  { key: 'signups', path: '/admin/signups', icon: BadgeCheck, group: 'customers', phase: 8, permission: 'hotels.create' },
  { key: 'support', path: '/admin/support', icon: LifeBuoy, group: 'customers', phase: 8, permission: 'support.answer' },
  { key: 'platformReports', path: '/admin/reports', icon: ChartColumn, group: 'customers', phase: 1 },
  { key: 'plans', path: '/admin/plans', icon: Tag, group: 'billing', phase: 1 },
  { key: 'switches', path: '/admin/switches', icon: SlidersVertical, group: 'system', phase: 8, permission: 'switches.manage' },
  { key: 'releases', path: '/admin/releases', icon: Rocket, group: 'system', phase: 8, permission: 'releases.manage' },
  { key: 'content', path: '/admin/content', icon: Newspaper, group: 'system', phase: 8, permission: 'content.manage' },
  { key: 'watchdog', path: '/admin/watchdog', icon: ShieldAlert, group: 'system', phase: 8, permission: 'watchdog.view' },
  { key: 'docs', path: '/admin/docs', icon: BookOpen, group: 'system', phase: 8, permission: 'docs.view' },
  { key: 'system', path: '/admin/system', icon: Server, group: 'system', phase: 8, permission: 'system.view' },
  { key: 'platformActivity', path: '/admin/activity', icon: List, group: 'system', phase: 8, permission: 'system.view' },
  { key: 'team', path: '/admin/team', icon: UserPlus, group: 'system', phase: 8, permission: 'team.manage' },
  { key: 'platformSettings', path: '/admin/settings', icon: SlidersHorizontal, group: 'system', phase: 1 },
]
