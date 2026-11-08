import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { CalendarDaysIcon, ChevronLeftIcon, ChevronRightIcon, LogInIcon, LogOutIcon, PlusIcon, UserPlusIcon } from 'lucide-react'
import { Alert } from '@/components/ui/Alert'
import { QuickCharge } from '@/components/billing/QuickCharge'
import { Badge } from '@/components/ui/Badge'
import { Button, buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { LoadFailed } from '@/components/ui/LoadFailed'
import { Select, SelectBox } from '@/components/ui/Select'
import { inputClass } from '@/components/ui/formStyles'
import { Tabs } from '@/components/ui/Tabs'
import { ApiError } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { chooseBranch, useCurrentBranch } from '@/lib/branch'
import { cn } from '@/lib/cn'
import { date, money } from '@/lib/format'
import { ROOM_DOTS, ROOM_TONES, useBoard, useExtendAny, useQuickCharges, useRoomStatus, useToday, type BoardRoom, type TodayRow } from '@/lib/desk'
import { addDays, localDate, localTime, STATUS_TONES, today as todayDate } from '@/lib/front'
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
        {/* Phones: the tabs get the whole row (the branch box goes below), so all four tabs show. */}
        <div className="w-full sm:w-auto sm:min-w-0 sm:flex-1">
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
  // Another day (owner, 2026-11-07): who stays in each room that night. Today = live.
  const [viewDay, setViewDay] = useState<string | null>(null)
  const board = useBoard(branchId, viewDay)
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
  const day = viewDay ?? (board.data ? localDate(board.data.refreshed_at) : todayDate())
  const shown = filterRooms(rooms, filter, day)
  const grouped = filter.sort === 'number'
  const floors = grouped ? [...new Set(shown.map((r) => r.floor ?? ''))] : ['']
  const selected = open ? (rooms.find((r) => r.id === open.id) ?? open) : null

  return (
    <div className="flex flex-col gap-4">
      <DayPicker day={viewDay} onChange={(d) => setViewDay(d && d !== todayDate() ? d : null)} />
      {viewDay && <Alert tone="info">{t('desk.otherDay', { date: date(viewDay) })}</Alert>}
      {board.isError && !board.data && <LoadFailed error={board.error} onRetry={() => board.refetch()} />}
      <RoomFilterBar rooms={rooms} counts={board.data?.counts ?? {}} day={day} filter={filter} setFilter={setFilter} />
      {selected && <RoomPanel room={selected} branchId={branchId} readOnly={!!viewDay} onClose={() => setOpen(null)} />}
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
                  className={cn('flex min-h-24 flex-col items-start gap-1 rounded-xl border p-3 text-left shadow-xs transition-shadow hover:shadow-md', ROOM_TONES[r.status], selected?.id === r.id && 'ring-2 ring-primary ring-offset-2 ring-offset-background')}
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
                  <span className="text-xs text-muted">{r.room_type}</span>
                  {r.stay ? (
                    <span className="mt-auto flex flex-col text-xs">
                      <span className="truncate font-medium">{r.stay.guest}</span>
                      <span className={r.stay.overdue ? 'font-semibold text-danger-text' : 'text-muted'}>
                        {r.stay.open_stay ? t('openStay.badge') : localDate(r.stay.check_out_at) === day
                          ? t('desk.leavesAt', { time: localTime(r.stay.check_out_at) })
                          : t('desk.until', { date: date(localDate(r.stay.check_out_at)) })}
                      </span>
                    </span>
                  ) : r.arriving ? (
                    <span className="mt-auto text-xs font-medium text-accent-text">{t('desk.arrivingToday', { guest: r.arriving.guest })}</span>
                  ) : (
                    <span className="mt-auto text-xs text-muted">{t(`roomStatus.${r.status}`)}</span>
                  )}
                  {!r.arriving && r.next_booking && (
                    <span className="text-xs text-accent-text">{t('desk.bookedFrom', { date: date(localDate(r.next_booking.check_in_at)) })}</span>
                  )}
                </button>
              ))}
          </div>
        </div>
      ))}
      {board.data && rooms.length === 0 && <p className="text-muted">{t('reservations.noRooms')}</p>}
      {rooms.length > 0 && shown.length === 0 && (
        <p className="text-sm text-muted">
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
      // The template's filter buttons (outline, dashed until chosen).
      'flex h-8 items-center gap-1.5 rounded-md border px-3 text-sm font-medium shadow-xs transition-colors pointer-coarse:h-11',
      active ? 'border-primary bg-primary/10 text-text' : 'border-dashed bg-background text-muted hover:bg-sunken hover:text-text dark:bg-input/30',
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
            className={cn(inputClass, 'w-44')}
          />
          {types.length > 1 && (
            <SelectBox aria-label={t('desk.filter.type')} value={filter.typeId ?? ''} onChange={(e) => setFilter({ ...filter, typeId: e.target.value ? Number(e.target.value) : null })} className="w-auto">
              <option value="">{t('desk.filter.allTypes')}</option>
              {types.map(([id, name]) => (
                <option key={id} value={id}>
                  {name}
                </option>
              ))}
            </SelectBox>
          )}
          {floors.length > 1 && (
            <SelectBox aria-label={t('desk.filter.floor')} value={filter.floor ?? ''} onChange={(e) => setFilter({ ...filter, floor: e.target.value || null })} className="w-auto">
              <option value="">{t('desk.filter.allFloors')}</option>
              {floors.map((f) => (
                <option key={f} value={f}>
                  {t('desk.floor', { floor: f })}
                </option>
              ))}
            </SelectBox>
          )}
          <SelectBox aria-label={t('desk.filter.sort')} value={filter.sort} onChange={(e) => setFilter({ ...filter, sort: e.target.value as RoomFilter['sort'] })} className="w-auto">
            {ROOM_SORTS.map((k) => (
              <option key={k} value={k}>
                {t(`desk.filter.sort_${k}`)}
              </option>
            ))}
          </SelectBox>
          {isFiltered(filter) && (
            <button type="button" onClick={() => setFilter({ ...NO_FILTER, sort: filter.sort })} className="min-h-touch rounded-md px-3 text-sm font-medium text-text hover:bg-sunken">
              {t('desk.filter.clear')}
            </button>
          )}
        </div>
      </div>
    </div>
  )
}

/** Tap a room: who is in it, and quick status changes. */
function RoomPanel({ room, branchId, readOnly = false, onClose }: { room: BoardRoom; branchId: number | null; readOnly?: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const can = useCan()
  const change = useRoomStatus()
  const error = change.error instanceof ApiError ? (change.error.field('status') ?? change.error.message) : null
  const next: string[] = []
  if (room.status !== 'occupied' && !readOnly) {
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
          <span className="text-sm text-muted">
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
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-sunken px-3 py-2 text-sm">
          <span className="flex flex-col">
            <span className="font-medium">{room.stay.guest}</span>
            <span className="text-xs text-muted">
              {room.stay.code} · {room.stay.open_stay ? t('openStay.badge') : t('desk.until', { date: `${date(localDate(room.stay.check_out_at))} ${localTime(room.stay.check_out_at)}` })}
              {Number(room.stay.balance) > 0 && ` · ${t('desk.owes', { amount: money(room.stay.balance) })}`}
            </span>
          </span>
          <span className="flex flex-wrap gap-2">
            {!readOnly && <PlusNight id={room.stay.reservation_id} checkOut={room.stay.check_out_at} stayType={room.stay.stay_type} openStay={room.stay.open_stay} />}
            {!readOnly && can('frontdesk.check_out') && (
              <Link to={`/reservations/${room.stay.reservation_id}?action=check-out`} className={buttonClass('secondary')}>
                <LogOutIcon aria-hidden className="size-4" />
                {t('desk.checkOut')}
              </Link>
            )}
            <Link to={`/reservations/${room.stay.reservation_id}`} className={buttonClass('ghost')}>
              {t('desk.openStay')}
            </Link>
          </span>
        </div>
      )}
      {room.stay && !readOnly && can('charges.room') && <RoomCharges roomId={room.id} roomNumber={room.number} branchId={branchId} />}
      {!room.stay && room.arriving && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-accent-soft px-3 py-2 text-sm">
          <span>{t('desk.arrivingToday', { guest: room.arriving.guest })}</span>
          <Link to={`/reservations/${room.arriving.reservation_id}?action=check-in`} className={buttonClass('primary')}>
            <LogInIcon aria-hidden className="size-4" />
            {t('desk.checkIn')}
          </Link>
        </div>
      )}
      {room.next_booking && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-sunken px-3 py-2 text-sm">
          <span>
            {t('desk.nextBooking', { from: date(localDate(room.next_booking.check_in_at)), to: date(localDate(room.next_booking.check_out_at)), guest: room.next_booking.guest ?? '' })}
          </span>
          <Link to={`/reservations/${room.next_booking.reservation_id}`} className="font-medium text-accent-text hover:underline">
            {room.next_booking.code}
          </Link>
        </div>
      )}
      {room.notes && <p className="text-sm text-muted">{room.notes}</p>}
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
    <div className="flex flex-col gap-2">
      {kind === 'departures' && rows.length > 0 && <p className="text-sm text-muted">{t('desk.stillStaying')}</p>}
      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[760px] text-left text-sm">
          <thead>
            <tr>
              <th className="px-4 h-10 font-medium">{t('reservations.guest')}</th>
              <th className="px-4 h-10 font-medium">{t('reservations.rooms')}</th>
              <th className="px-4 h-10 font-medium">{kind === 'arrivals' ? t('reservations.form.checkIn') : t('reservations.form.checkOut')}</th>
              <th className="px-4 h-10 font-medium">{t('reservations.status')}</th>
              <th className="px-4 h-10 text-right font-medium">{t('reservations.balance')}</th>
              <th className="px-4 h-10" />
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
                  {kind !== 'arrivals' && r.open_stay ? (
                    <Badge tone="info">{t('openStay.badge')}</Badge>
                  ) : (
                    <>
                      {kind === 'in_house' && localDate(r.check_out_at) !== todayDate() && `${date(localDate(r.check_out_at))} `}
                      {localTime(kind === 'arrivals' ? r.check_in_at : r.check_out_at)}
                    </>
                  )}
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
                  <span className="inline-flex flex-wrap justify-end gap-2">
                    {kind === 'departures' && <PlusNight id={r.id} checkOut={r.check_out_at} stayType={r.stay_type} openStay={r.open_stay} label={t('desk.staying')} />}
                    {kind !== 'arrivals' && can('frontdesk.check_out') && (
                      <Link to={`/reservations/${r.id}?action=check-out`} className={buttonClass(kind === 'departures' ? 'primary' : 'secondary')}>
                        {kind === 'departures' ? t('desk.leaving') : t('desk.checkOut')}
                      </Link>
                    )}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {!loading && rows.length === 0 && <p className="p-6 text-center text-muted">{t(`desk.empty.${kind}`)}</p>}
      </Card>
    </div>
  )
}

/**
 * One tap: one more night in the same room. From the last booked night or from today, whichever is later
 * (an overdue guest pays the night they already stayed too). Not for open stays (they get a night each day).
 */
function PlusNight({ id, checkOut, stayType, openStay, label }: { id: number; checkOut: string; stayType?: string; openStay?: boolean; label?: string }) {
  const { t } = useTranslation()
  const can = useCan()
  const extend = useExtendAny()
  if (openStay || (stayType && stayType !== 'nightly') || !can('frontdesk.room_move')) return null
  const from = localDate(checkOut) > todayDate() ? localDate(checkOut) : todayDate()
  const to = addDays(from, 1)
  const error = extend.error instanceof ApiError ? (extend.error.field('check_out') ?? extend.error.message) : null
  return (
    <span className="inline-flex flex-col items-end gap-1">
      <Button variant="secondary" disabled={extend.isPending} title={t('desk.plusNightHelp', { date: date(to) })} onClick={() => extend.mutate({ id, check_out: to })}>
        <PlusIcon aria-hidden className="size-4" />
        {label ?? t('desk.plusNight')}
      </Button>
      {error && <span role="alert" className="max-w-56 text-right text-xs text-danger-text">{error}</span>}
    </span>
  )
}

/** Quick charges on the room panel: Breakfast, Laundry, Taxi… in one tap (owner, 2026-11-07). */
function RoomCharges({ roomId, roomNumber, branchId }: { roomId: number; roomNumber: string; branchId: number | null }) {
  const { t } = useTranslation()
  const quick = useQuickCharges(branchId)
  return (
    <div className="flex flex-col gap-2 border-t border-border pt-3">
      <h3 className="text-sm font-semibold">{t('quickCharge.onRoom')}</h3>
      <QuickCharge key={roomId} roomId={roomId} roomNumber={roomNumber} buttons={quick.data?.buttons ?? []} />
    </div>
  )
}

/** Today, or another day: the previous / next day, or pick a date. */
function DayPicker({ day, onChange }: { day: string | null; onChange: (d: string | null) => void }) {
  const { t } = useTranslation()
  const shown = day ?? todayDate()
  return (
    <div className="flex flex-wrap items-end gap-2">
      <Button variant="secondary" aria-label={t('desk.dayBefore')} onClick={() => onChange(addDays(shown, -1))}>
        <ChevronLeftIcon aria-hidden className="size-4" />
      </Button>
      <div className="w-44">
        <Field label={t('desk.showDay')} type="date" value={shown} onChange={(e) => onChange(e.target.value || null)} />
      </div>
      <Button variant="secondary" aria-label={t('desk.dayAfter')} onClick={() => onChange(addDays(shown, 1))}>
        <ChevronRightIcon aria-hidden className="size-4" />
      </Button>
      {day && (
        <Button variant="ghost" onClick={() => onChange(null)}>
          {t('desk.backToToday')}
        </Button>
      )}
    </div>
  )
}
