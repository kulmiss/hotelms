import { describe, expect, it } from 'vitest'
import type { BoardRoom } from '@/lib/desk'
import { filterRooms, NO_FILTER } from '@/lib/roomFilter'

const stay = (out: string, extra: Partial<NonNullable<BoardRoom['stay']>> = {}) => ({
  reservation_id: 1, code: 'X', guest: 'Abebe Kebede', check_in_at: '2026-11-10T14:00:00+03:00', check_out_at: out, balance: '0.00', overdue: false, ...extra,
})
const room = (id: number, number: string, status: string, more: Partial<BoardRoom> = {}): BoardRoom => ({
  id, number, floor: number[0], status, room_type_id: number.startsWith('1') ? 1 : 2, room_type: number.startsWith('1') ? 'Standard' : 'Deluxe', notes: null, stay: null, arriving: null, ...more,
})
const day = '2026-11-12'
const rooms: BoardRoom[] = [
  room(1, '101', 'occupied', { stay: stay('2026-11-12T12:00:00+03:00') }), // leaves today
  room(2, '102', 'occupied', { stay: stay('2026-11-11T12:00:00+03:00', { overdue: true, guest: 'Sara Tesfaye' }) }),
  room(3, '103', 'dirty'),
  room(4, '201', 'available', { arriving: stay('2026-11-14T12:00:00+03:00', { guest: 'Meron Alemu' }) }),
  room(5, '202', 'maintenance'),
  room(6, '203', 'occupied', { stay: stay('2026-11-15T12:00:00+03:00') }),
]
const numbers = (f: Partial<typeof NO_FILTER>) => filterRooms(rooms, { ...NO_FILTER, ...f }, day).map((r) => r.number)

describe('room board filters', () => {
  it('filters by status, today, type, floor and search', () => {
    expect(numbers({})).toEqual(['101', '102', '103', '201', '202', '203'])
    expect(numbers({ statuses: ['dirty', 'maintenance'] })).toEqual(['103', '202'])
    expect(numbers({ today: 'leaving' })).toEqual(['101', '102']) // overdue ones are leaving too
    expect(numbers({ today: 'overdue' })).toEqual(['102'])
    expect(numbers({ today: 'arriving' })).toEqual(['201'])
    expect(numbers({ today: 'free_tonight' })).toEqual(['103']) // dirty can be cleaned; not maintenance, not arriving
    expect(numbers({ typeId: 2 })).toEqual(['201', '202', '203'])
    expect(numbers({ floor: '1' })).toEqual(['101', '102', '103'])
    expect(numbers({ q: 'sara' })).toEqual(['102'])
    expect(numbers({ q: 'meron' })).toEqual(['201']) // the guest arriving
    expect(numbers({ q: '20' })).toEqual(['201', '202', '203'])
  })

  it('sorts by status or by who leaves first', () => {
    expect(numbers({ sort: 'status' })).toEqual(['201', '101', '102', '203', '103', '202'])
    expect(numbers({ sort: 'check_out' })).toEqual(['102', '101', '203', '103', '201', '202'])
  })
})
