import type { BoardRoom } from './desk'
import { localDate } from './front'

/** Room board filter and sort (remembered on this device). */
export const ROOM_STATUSES = ['available', 'occupied', 'dirty', 'cleaning', 'maintenance', 'out_of_order'] as const
export const TODAY_FILTERS = ['arriving', 'leaving', 'overdue', 'free_tonight'] as const
export const ROOM_SORTS = ['number', 'status', 'check_out'] as const

export type RoomFilter = {
  statuses: string[]
  today: (typeof TODAY_FILTERS)[number] | null
  typeId: number | null
  floor: string | null
  q: string
  sort: (typeof ROOM_SORTS)[number]
}

export const NO_FILTER: RoomFilter = { statuses: [], today: null, typeId: null, floor: null, q: '', sort: 'number' }

const KEY = 'hms-room-filter'

export function loadRoomFilter(): RoomFilter {
  try {
    const saved = JSON.parse(localStorage.getItem(KEY) ?? 'null') as Partial<RoomFilter> | null
    return { ...NO_FILTER, ...(saved ?? {}), q: '' } // the search text is not kept
  } catch {
    return NO_FILTER
  }
}

export function saveRoomFilter(f: RoomFilter) {
  try {
    localStorage.setItem(KEY, JSON.stringify({ ...f, q: '' }))
  } catch {
    // ignore (private mode)
  }
}

export const isFiltered = (f: RoomFilter) => f.statuses.length > 0 || f.today !== null || f.typeId !== null || f.floor !== null || f.q.trim() !== ''

/** Does the room match one of the "today" chips? `day` is the hotel's date today (YYYY-MM-DD). */
export function matchesToday(r: BoardRoom, which: RoomFilter['today'], day: string): boolean {
  switch (which) {
    case 'arriving':
      return r.arriving !== null
    case 'leaving':
      return r.stay !== null && localDate(r.stay.check_out_at) <= day
    case 'overdue':
      return Boolean(r.stay?.overdue)
    case 'free_tonight': // nobody in it and nobody coming today; dirty rooms can still be cleaned in time
      return r.stay === null && r.arriving === null && !['maintenance', 'out_of_order'].includes(r.status)
    default:
      return true
  }
}

export function filterRooms(rooms: BoardRoom[], f: RoomFilter, day: string): BoardRoom[] {
  const q = f.q.trim().toLowerCase()
  const shown = rooms.filter(
    (r) =>
      (f.statuses.length === 0 || f.statuses.includes(r.status)) &&
      matchesToday(r, f.today, day) &&
      (f.typeId === null || r.room_type_id === f.typeId) &&
      (f.floor === null || (r.floor ?? '') === f.floor) &&
      (q === '' || r.number.toLowerCase().includes(q) || [r.stay?.guest, r.arriving?.guest].some((g) => g?.toLowerCase().includes(q))),
  )
  if (f.sort === 'status') {
    const order = ROOM_STATUSES as readonly string[]
    return [...shown].sort((a, b) => order.indexOf(a.status) - order.indexOf(b.status))
  }
  if (f.sort === 'check_out') {
    // Who leaves first comes first; empty rooms after.
    return [...shown].sort((a, b) => (a.stay?.check_out_at ?? '9999').localeCompare(b.stay?.check_out_at ?? '9999'))
  }
  return shown // by floor and number, as the server sends them
}
