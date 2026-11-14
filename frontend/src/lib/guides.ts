/**
 * Staff guides (Help → How to use). Each topic shows only to people who can do it:
 * `perm` (any of) and, for optional modules, `module`. The words are in en.json under "guide.topics.<key>"
 * (title + steps), in very easy English, so they can be translated to Amharic later.
 */
export type GuideTopic = { key: string; perm?: string | string[]; module?: string; pic?: boolean }

/** A topic with `pic: true` shows `public/guide/<key>.webp` (a screenshot with orange numbers = the step numbers). */
export const guidePicture = (key: string) => `/guide/${key}.webp`
export type GuideSection = { key: string; topics: GuideTopic[] }

export const GUIDE: GuideSection[] = [
  {
    key: 'start',
    topics: [
      { key: 'signIn' },
      { key: 'lockScreen' },
      { key: 'search' },
      { key: 'lists' },
      { key: 'bell' },
      { key: 'profile' },
      { key: 'branch' },
    ],
  },
  {
    key: 'desk',
    topics: [
      { key: 'dashboard', perm: 'dashboard.view' },
      { key: 'gettingStarted', perm: 'settings.hotel' },
      { key: 'roomBoard', pic: true, perm: 'frontdesk.view' },
      { key: 'todayLists', perm: 'frontdesk.view' },
      { key: 'walkIn', pic: true, perm: 'frontdesk.walk_in' },
      { key: 'noEndDate', perm: 'frontdesk.walk_in' },
      { key: 'quickCheckIn', perm: 'frontdesk.quick_check_in' },
      { key: 'newBooking', perm: 'reservations.create' },
      { key: 'onlineRequests', perm: 'reservations.view' },
      { key: 'freeRooms', pic: true, perm: 'reservations.view' },
      { key: 'checkIn', pic: true, perm: 'frontdesk.check_in' },
      { key: 'moveExtend', pic: true, perm: 'frontdesk.room_move' },
      { key: 'checkOut', pic: true, perm: 'frontdesk.check_out' },
      { key: 'changeBooking', pic: true, perm: 'reservations.edit' },
      { key: 'cancelBooking', pic: true, perm: 'reservations.cancel' },
      { key: 'noShow', pic: true, perm: 'reservations.no_show' },
    ],
  },
  {
    key: 'guests',
    topics: [
      { key: 'guests', perm: 'guests.create_edit' },
      { key: 'idPhoto', perm: 'guests.create_edit' },
      { key: 'blacklist', perm: 'guests.blacklist' },
    ],
  },
  {
    key: 'money',
    topics: [
      { key: 'takePayment', pic: true, perm: 'payments.take' },
      { key: 'paymentProof', pic: true, perm: 'payments.take' },
      { key: 'quickCharge', perm: 'charges.room' },
      { key: 'extraCharge', pic: true, perm: 'invoices.add_charge' },
      { key: 'discount', perm: 'invoices.discount' },
      { key: 'printBill', perm: 'invoices.print' },
      { key: 'refund', perm: 'payments.refund' },
      { key: 'cancelBill', perm: 'invoices.void' },
      { key: 'unpaid', perm: 'checkout.approve_balance' },
      { key: 'cashierShift', pic: true, perm: 'cashier.own_shift', module: 'cashier_close' },
      { key: 'approveShift', perm: 'cashier.approve', module: 'cashier_close' },
    ],
  },
  {
    key: 'rooms',
    topics: [
      { key: 'roomStatus', perm: 'rooms.change_status' },
      { key: 'maintenance', perm: 'rooms.maintenance' },
      { key: 'cleaning', pic: true, perm: ['housekeeping.view', 'housekeeping.own_tasks'], module: 'housekeeping' },
      { key: 'assignCleaning', perm: 'housekeeping.assign', module: 'housekeeping' },
    ],
  },
  {
    key: 'work',
    topics: [
      { key: 'clockIn', pic: true, perm: 'attendance.clock', module: 'attendance' },
      { key: 'myShifts', perm: 'shifts.view_own', module: 'shifts' },
      { key: 'roster', perm: 'shifts.manage', module: 'shifts' },
      { key: 'paperAttendance', perm: 'attendance.manual', module: 'attendance' },
    ],
  },
  {
    key: 'manage',
    topics: [
      { key: 'reports', perm: ['reports.revenue', 'reports.occupancy', 'reports.payments', 'reports.tax', 'reports.outstanding'] },
      { key: 'myReport', perm: ['reports.my_shift', 'reports.my_work'] },
      { key: 'approvals', perm: 'approvals.decide' },
      { key: 'staff', perm: 'staff.manage' },
      { key: 'roles', perm: 'roles.manage' },
      { key: 'devices', perm: 'staff.manage' },
      { key: 'settings', perm: ['settings.hotel', 'settings.rooms', 'settings.pricing', 'settings.policies', 'settings.money'] },
      { key: 'activity', perm: 'activity_log.view' },
      { key: 'plan', perm: 'subscription.view' },
    ],
  },
]

/** The sections and topics this person may use. */
export function guideFor(can: (p?: string | readonly string[]) => boolean, modules: string[]): GuideSection[] {
  return GUIDE.map((s) => ({ ...s, topics: s.topics.filter((tp) => can(tp.perm) && (!tp.module || modules.includes(tp.module))) })).filter((s) => s.topics.length > 0)
}
