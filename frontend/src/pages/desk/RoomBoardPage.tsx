import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { ArrowRightEndOnRectangleIcon, ArrowRightStartOnRectangleIcon, CalendarDaysIcon, UserPlusIcon } from '@heroicons/react/24/outline'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button, buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { PageHeader } from '@/components/ui/PageHeader'
import { Select } from '@/components/ui/Select'
import { Tabs } from '@/components/ui/Tabs'
import { ApiError } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { chooseBranch, useCurrentBranch } from '@/lib/branch'
import { cn } from '@/lib/cn'
import { date, money } from '@/lib/format'
import { ROOM_DOTS, ROOM_TONES, useBoard, useRoomStatus, useToday, type BoardRoom, type TodayRow } from '@/lib/desk'
import { localDate, localTime, STATUS_TONES, today as todayDate } from '@/lib/front'
import { useCan } from '@/lib/hotel'
import { isPositive } from '@/lib/decimal'
import { filterRooms, isFiltered, loadRoomFilter, matchesToday, NO_FILTER, ROOM_SORTS, ROOM_STATUSES, saveRoomFilter, TODAY_FILTERS, type RoomFilter } from '@/lib/roomFilter'

const TABS = ['board', 'arrivals', 'departures', 'in_house'] as const
type Tab = (typeof TABS)[number]

/** Front desk: live room board and today's arrivals, departures and guests in house. */
export function RoomBoardPage() {
  const { t } = useTranslation()
  const can = useCan()
  const me = useMe()
  const [params, setParams] = useSearchParams()
  // A link may name the branch (?branch=); otherwise the one chosen in the top bar. Both stay the same.
  useState(() => Number(params.get('branch')) && chooseBranch(Number(params.get('branch'))))
  const { branches, current: chosen, select } = useCurrentBranch(me.data)
  const tab = (TABS as readonly string[]).includes(params.get('tab') ?? '') ? (params.get('tab') as Tab) : 'board'
  const current = chosen?.id ?? null
  const today = useToday(current)

  const count = (k: Tab) => (k === 'board' ? undefined : today.data?.[k].length)

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={t('nav.roomBoard')}
        subtitle={t('desk.subtitle')}
        actions={
          can('frontdesk.walk_in') && (
            <Link to="/rooms/walk-in" className={buttonClass('primary', 'lg')}>
              <UserPlusIcon aria-hidden className="size-5" />
              {t('desk.walkIn')}
            </Link>
          )
        }
      />
      <div className="flex flex-wrap items-end gap-3">
        <div className="min-w-0 flex-1">
          <Tabs
            label={t('nav.roomBoard')}
            active={tab}
            onChange={(k) => setParams(k === 'board' ? {} : { tab: k })}
            items={TABS.map((k) => ({ key: k, label: count(k) !== undefined ? `${t(`desk.tabs.${k}`)} (${count(k)})` : t(`desk.tabs.${k}`) }))}
          />
        </div>
        {branches.length > 1 && (
          <div className="w-48">
            <Select label={t('settings.rooms.branch')} value={String(current ?? '')} onChange={(e) => select(Number(e.target.value))}>
              {branches.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.name}
                </option>
              ))}
            </Select>
          </div>
        )}
      </div>
      {tab === 'board' ? <Board branchId={current} /> : <TodayList rows={today.data?.[tab] ?? []} kind={tab} loading={today.isPending} />}
    </div>
  )
}

function Board({ branchId }: { branchId: number | null }) {
  const { t } = useTranslation()
  const board = useBoard(branchId)
  const [params] = useSearchParams()
  const [open, setOpen] = useState<BoardRoom | null>(null)
  const rooms = board.data?.data ?? []
  // From the search box: open that room straight away.
  const fromSearch = Number(params.get('room')) || null
  const [shownSearch, setShownSearch] = useState<number | null>(null)
  if (fromSearch && fromSearch !== shownSearch && rooms.length) {
    setShownSearch(fromSearch)
    const hit = rooms.find((r) => r.id === fromSearch)
    if (hit) setOpen(hit)
  }
  const [filter, setFilterState] = useState<RoomFilter>(loadRoomFilter)
  const setFilter = (next: RoomFilter) => {
    setFilterState(next)
    saveRoomFilter(next)
  }
  const day = board.data ? localDate(board.data.refreshed_at) : todayDate()
  const shown = filterRooms(rooms, filter, day)
  const grouped = filter.sort === 'number'
  const floors = grouped ? [...new Set(shown.map((r) => r.floor ?? ''))] : ['']
  const selected = open ? (rooms.find((r) => r.id === open.id) ?? open) : null

  return (
    <div className="flex flex-col gap-4">
      <RoomFilterBar rooms={rooms} counts={board.data?.counts ?? {}} day={day} filter={filter} setFilter={setFilter} />
      {selected && <RoomPanel room={selected} branchId={branchId} onClose={() => setOpen(null)} />}
      {floors.map((f) => (
        <div key={f} className="flex flex-col gap-2">
          {grouped && floors.length > 1 && <h2 className="text-xs font-semibold text-muted">{f ? t('desk.floor', { floor: f }) : t('desk.noFloor')}</h2>}
          <div className="grid grid-cols-[repeat(auto-fill,minmax(9.5rem,1fr))] gap-2">
            {shown
              .filter((r) => !grouped || (r.floor ?? '') === f)
              .map((r) => (
                <button
                  key={r.id}
                  type="button"
                  onClick={() => setOpen(r)}
                  aria-label={`${t('reservations.room')} ${r.number}, ${t(`roomStatus.${r.status}`)}`}
                  className={cn('flex min-h-24 flex-col items-start gap-1 rounded-xl p-3 text-left ring-1 transition duration-75 hover:shadow-md', ROOM_TONES[r.status], selected?.id === r.id && 'ring-2 ring-accent')}
                >
                  <span className="flex w-full items-center justify-between gap-2">
                    <span className="text-base font-semibold">{r.number}</span>
                    <span className="flex items-center gap-1.5">
                      {r.stay && isPositive(r.stay.balance) && (
                        <span title={t('desk.owes', { amount: money(r.stay.balance) })} className="rounded-full bg-danger-bg px-1.5 text-[10px] font-semibold text-danger-text">
                          {t('desk.owesShort')}
                        </span>
                      )}
                      <span className={cn('size-2.5 rounded-full', ROOM_DOTS[r.status])} />
                    </span>
                  </span>
                  <span className="text-[11px] text-muted">{r.room_type}</span>
                  {r.stay ? (
                    <span className="mt-auto flex flex-col text-[11px]">
                      <span className="truncate font-medium">{r.stay.guest}</span>
                      <span className={r.stay.overdue ? 'font-semibold text-danger-text' : 'text-muted'}>
                        {localDate(r.stay.check_out_at) === day
                          ? t('desk.leavesAt', { time: localTime(r.stay.check_out_at) })
                          : t('desk.until', { date: date(localDate(r.stay.check_out_at)) })}
                      </span>
                    </span>
                  ) : r.arriving ? (
                    <span className="mt-auto text-[11px] font-medium text-accent-text">{t('desk.arrivingToday', { guest: r.arriving.guest })}</span>
                  ) : (
                    <span className="mt-auto text-[11px] text-muted">{t(`roomStatus.${r.status}`)}</span>
                  )}
                  {!r.arriving && r.next_booking && (
                    <span className="text-[11px] text-accent-text">{t('desk.bookedFrom', { date: date(localDate(r.next_booking.check_in_at)) })}</span>
                  )}
                </button>
              ))}
          </div>
        </div>
      ))}
      {board.data && rooms.length === 0 && <p className="text-muted">{t('reservations.noRooms')}</p>}
      {rooms.length > 0 && shown.length === 0 && (
        <p className="text-[13px] text-muted">
          {t('desk.filter.none')}{' '}
          <button type="button" className="font-medium text-accent-text underline" onClick={() => setFilter({ ...NO_FILTER, sort: filter.sort })}>
            {t('desk.filter.clear')}
          </button>
        </p>
      )}
    </div>
  )
}

/** Filters above the room board: tap a status count, a "today" chip, a type or floor; search; sort. */
function RoomFilterBar({ rooms, counts, day, filter, setFilter }: { rooms: BoardRoom[]; counts: Record<string, number>; day: string; filter: RoomFilter; setFilter: (f: RoomFilter) => void }) {
  const { t } = useTranslation()
  const types = [...new Map(rooms.map((r) => [r.room_type_id, r.room_type ?? ''])).entries()]
  const floors = [...new Set(rooms.map((r) => r.floor ?? ''))].filter(Boolean)
  const chip = (active: boolean) =>
    cn(
      'flex min-h-touch items-center gap-1.5 rounded-lg px-3 text-[13px] font-medium ring-1 transition duration-75',
      active ? 'bg-accent/15 text-accent-text ring-accent' : 'bg-surface text-muted ring-ring hover:bg-sunken hover:text-text',
    )
  const toggleStatus = (s: string) =>
    setFilter({ ...filter, statuses: filter.statuses.includes(s) ? filter.statuses.filter((x) => x !== s) : [...filter.statuses, s] })

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap gap-2" role="group" aria-label={t('desk.filter.status')}>
        {ROOM_STATUSES.map((s) => (
          <button key={s} type="button" aria-pressed={filter.statuses.includes(s)} onClick={() => toggleStatus(s)} className={chip(filter.statuses.includes(s))}>
            <span className={cn('size-2.5 rounded-full', ROOM_DOTS[s])} />
            {t(`roomStatus.${s}`)} <span className="tabular-nums">{counts[s] ?? 0}</span>
          </button>
        ))}
      </div>
      <div className="flex flex-wrap items-end gap-2">
        <div className="flex flex-wrap gap-2" role="group" aria-label={t('desk.filter.today')}>
          {TODAY_FILTERS.map((k) => (
            <button key={k} type="button" aria-pressed={filter.today === k} onClick={() => setFilter({ ...filter, today: filter.today === k ? null : k })} className={chip(filter.today === k)}>
              {t(`desk.filter.${k}`)} <span className="tabular-nums">{rooms.filter((r) => matchesToday(r, k, day)).length}</span>
            </button>
          ))}
        </div>
        <div className="ml-auto flex flex-wrap items-end gap-2">
          <input
            type="search"
            value={filter.q}
            onChange={(e) => setFilter({ ...filter, q: e.target.value })}
            placeholder={t('desk.filter.search')}
            aria-label={t('desk.filter.search')}
            className="min-h-touch w-44 rounded-lg bg-surface px-3 text-[13px] ring-1 ring-ring-strong outline-none focus:ring-2 focus:ring-accent"
          />
          {types.length > 1 && (
            <select aria-label={t('desk.filter.type')} value={filter.typeId ?? ''} onChange={(e) => setFilter({ ...filter, typeId: e.target.value ? Number(e.target.value) : null })} className="min-h-touch rounded-lg bg-surface px-3 text-[13px] ring-1 ring-ring-strong">
              <option value="">{t('desk.filter.allTypes')}</option>
              {types.map(([id, name]) => (
                <option key={id} value={id}>
                  {name}
                </option>
              ))}
            </select>
          )}
          {floors.length > 1 && (
            <select aria-label={t('desk.filter.floor')} value={filter.floor ?? ''} onChange={(e) => setFilter({ ...filter, floor: e.target.value || null })} className="min-h-touch rounded-lg bg-surface px-3 text-[13px] ring-1 ring-ring-strong">
              <option value="">{t('desk.filter.allFloors')}</option>
              {floors.map((f) => (
                <option key={f} value={f}>
                  {t('desk.floor', { floor: f })}
                </option>
              ))}
            </select>
          )}
          <select aria-label={t('desk.filter.sort')} value={filter.sort} onChange={(e) => setFilter({ ...filter, sort: e.target.value as RoomFilter['sort'] })} className="min-h-touch rounded-lg bg-surface px-3 text-[13px] ring-1 ring-ring-strong">
            {ROOM_SORTS.map((k) => (
              <option key={k} value={k}>
                {t(`desk.filter.sort_${k}`)}
              </option>
            ))}
          </select>
          {isFiltered(filter) && (
            <button type="button" onClick={() => setFilter({ ...NO_FILTER, sort: filter.sort })} className="min-h-touch rounded-lg px-3 text-[13px] font-medium text-accent-text hover:bg-sunken">
              {t('desk.filter.clear')}
            </button>
          )}
        </div>
      </div>
    </div>
  )
}

/** Tap a room: who is in it, and quick status changes. */
function RoomPanel({ room, branchId, onClose }: { room: BoardRoom; branchId: number | null; onClose: () => void }) {
  const { t } = useTranslation()
  const can = useCan()
  const change = useRoomStatus()
  const error = change.error instanceof ApiError ? (change.error.field('status') ?? change.error.message) : null
  const next: string[] = []
  if (room.status !== 'occupied') {
    if (can('rooms.change_status')) {
      if (room.status === 'dirty') next.push('cleaning', 'available')
      if (room.status === 'cleaning') next.push('available')
      if (room.status === 'available') next.push('dirty')
    }
    if (can('rooms.maintenance')) {
      if (room.status === 'maintenance' || room.status === 'out_of_order') next.push('available')
      else next.push('maintenance', 'out_of_order')
    }
  }

  return (
    <Card className="flex flex-col gap-3 animate-page-in">
      <div className="flex items-start justify-between gap-3">
        <div className="flex flex-col">
          <h2 className="text-base font-semibold">
            {t('reservations.room')} {room.number}
          </h2>
          <span className="text-[13px] text-muted">
            {room.room_type} · {t(`roomStatus.${room.status}`)}
          </span>
        </div>
        <div className="flex gap-1">
          {can('reservations.view') && (
            <Link to={`/rooms/${room.id}/calendar${branchId ? `?branch=${branchId}` : ''}`} className={buttonClass('secondary')}>
              <CalendarDaysIcon aria-hidden className="size-4" />
              {t('roomCal.open')}
            </Link>
          )}
          <Button variant="ghost" onClick={onClose}>
            {t('common.close')}
          </Button>
        </div>
      </div>
      {room.stay && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-sunken px-3 py-2 text-[13px]">
          <span className="flex flex-col">
            <span className="font-medium">{room.stay.guest}</span>
            <span className="text-xs text-muted">
              {room.stay.code} · {t('desk.until', { date: `${date(localDate(room.stay.check_out_at))} ${localTime(room.stay.check_out_at)}` })}
              {Number(room.stay.balance) > 0 && ` · ${t('desk.owes', { amount: money(room.stay.balance) })}`}
            </span>
          </span>
          <Link to={`/reservations/${room.stay.reservation_id}`} className={buttonClass('secondary')}>
            <ArrowRightStartOnRectangleIcon aria-hidden className="size-4" />
            {t('desk.openStay')}
          </Link>
        </div>
      )}
      {!room.stay && room.arriving && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-accent-soft px-3 py-2 text-[13px]">
          <span>{t('desk.arrivingToday', { guest: room.arriving.guest })}</span>
          <Link to={`/reservations/${room.arriving.reservation_id}?action=check-in`} className={buttonClass('primary')}>
            <ArrowRightEndOnRectangleIcon aria-hidden className="size-4" />
            {t('desk.checkIn')}
          </Link>
        </div>
      )}
      {room.next_booking && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-sunken px-3 py-2 text-[13px]">
          <span>
            {t('desk.nextBooking', { from: date(localDate(room.next_booking.check_in_at)), to: date(localDate(room.next_booking.check_out_at)), guest: room.next_booking.guest ?? '' })}
          </span>
          <Link to={`/reservations/${room.next_booking.reservation_id}`} className="font-medium text-accent-text hover:underline">
            {room.next_booking.code}
          </Link>
        </div>
      )}
      {room.notes && <p className="text-[13px] text-muted">{room.notes}</p>}
      {error && <Alert>{error}</Alert>}
      {next.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {next.map((s) => (
            <Button key={s} variant="secondary" disabled={change.isPending} onClick={() => change.mutate({ id: room.id, status: s })}>
              <span className={cn('size-2.5 rounded-full', ROOM_DOTS[s])} />
              {t('desk.markAs', { status: t(`roomStatus.${s}`) })}
            </Button>
          ))}
        </div>
      )}
    </Card>
  )
}

function TodayList({ rows, kind, loading }: { rows: TodayRow[]; kind: Exclude<Tab, 'board'>; loading: boolean }) {
  const { t } = useTranslation()
  const can = useCan()
  return (
    <Card className="overflow-x-auto p-0">
      <table className="w-full min-w-[760px] text-left text-[13px]">
        <thead className="bg-sunken">
          <tr>
            <th className="px-4 py-2.5 font-semibold">{t('reservations.guest')}</th>
            <th className="px-4 py-2.5 font-semibold">{t('reservations.rooms')}</th>
            <th className="px-4 py-2.5 font-semibold">{kind === 'arrivals' ? t('reservations.form.checkIn') : t('reservations.form.checkOut')}</th>
            <th className="px-4 py-2.5 font-semibold">{t('reservations.status')}</th>
            <th className="px-4 py-2.5 text-right font-semibold">{t('reservations.balance')}</th>
            <th className="px-4 py-2.5" />
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.id} className={cn('border-t border-border', (r.late || r.overdue) && 'bg-warn-bg/40')}>
              <td className="px-4 py-1.5">
                <Link to={`/reservations/${r.id}`} className="flex min-h-touch flex-col justify-center">
                  <span className="font-medium">{r.guest?.name}</span>
                  <span className="text-xs text-muted">
                    {r.code} · {r.guest?.phone}
                  </span>
                </Link>
              </td>
              <td className="px-4 py-1.5 text-muted">{r.rooms.map((x) => x.room_number ?? x.room_type).join(', ')}</td>
              <td className="px-4 py-1.5">
                {/* In house: the day too (guests leave on different days); today's lists: the time is enough. */}
                {kind === 'in_house' && localDate(r.check_out_at) !== todayDate() && `${date(localDate(r.check_out_at))} `}
                {localTime(kind === 'arrivals' ? r.check_in_at : r.check_out_at)}
                {r.late && (
                  <span title={t('desk.lateHelp')}>
                    <Badge tone="warn">{t('desk.late')}</Badge>
                  </span>
                )}
                {r.overdue && (
                  <span title={t('desk.overdueHelp')}>
                    <Badge tone="danger">{t('desk.overdue')}</Badge>
                  </span>
                )}
              </td>
              <td className="px-4 py-1.5">
                <Badge tone={STATUS_TONES[r.status]}>{t(`reservations.statuses.${r.status}`)}</Badge>
              </td>
              <td className="px-4 py-1.5 text-right tabular-nums">{money(r.balance, r.currency)}</td>
              <td className="px-4 py-1.5 text-right">
                {kind === 'arrivals' && can('frontdesk.check_in') && (
                  <Link to={`/reservations/${r.id}?action=check-in`} className={buttonClass('primary')}>
                    {t('desk.checkIn')}
                  </Link>
                )}
                {kind !== 'arrivals' && can('frontdesk.check_out') && (
                  <Link to={`/reservations/${r.id}?action=check-out`} className={buttonClass(kind === 'departures' ? 'primary' : 'secondary')}>
                    {t('desk.checkOut')}
                  </Link>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {!loading && rows.length === 0 && <p className="p-6 text-center text-muted">{t(`desk.empty.${kind}`)}</p>}
    </Card>
  )
}
