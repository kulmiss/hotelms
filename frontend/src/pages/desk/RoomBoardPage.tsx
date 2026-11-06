import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { ArrowRightEndOnRectangleIcon, ArrowRightStartOnRectangleIcon, UserPlusIcon } from '@heroicons/react/24/outline'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button, buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { PageHeader } from '@/components/ui/PageHeader'
import { Select } from '@/components/ui/Select'
import { Tabs } from '@/components/ui/Tabs'
import { ApiError } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { cn } from '@/lib/cn'
import { date, money } from '@/lib/format'
import { ROOM_DOTS, ROOM_TONES, useBoard, useRoomStatus, useToday, type BoardRoom, type TodayRow } from '@/lib/desk'
import { localDate, localTime, STATUS_TONES } from '@/lib/front'
import { useCan } from '@/lib/hotel'

const TABS = ['board', 'arrivals', 'departures', 'in_house'] as const
type Tab = (typeof TABS)[number]

/** Front desk: live room board and today's arrivals, departures and guests in house. */
export function RoomBoardPage() {
  const { t } = useTranslation()
  const can = useCan()
  const me = useMe()
  const branches = me.data?.branches ?? []
  const [params, setParams] = useSearchParams()
  const tab = (TABS as readonly string[]).includes(params.get('tab') ?? '') ? (params.get('tab') as Tab) : 'board'
  const [branchId, setBranchId] = useState<number | null>(() => Number(params.get('branch')) || null)
  const current = branchId ?? branches[0]?.id ?? null
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
            <Select label={t('settings.rooms.branch')} value={String(current ?? '')} onChange={(e) => setBranchId(Number(e.target.value))}>
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
  const floors = [...new Set(rooms.map((r) => r.floor ?? ''))]
  const selected = open ? (rooms.find((r) => r.id === open.id) ?? open) : null

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap gap-3 text-[13px] text-muted">
        {['available', 'occupied', 'dirty', 'cleaning', 'maintenance', 'out_of_order'].map((s) => (
          <span key={s} className="flex items-center gap-1.5">
            <span className={cn('size-2.5 rounded-full', ROOM_DOTS[s])} />
            {t(`roomStatus.${s}`)} <span className="tabular-nums">{board.data?.counts[s] ?? 0}</span>
          </span>
        ))}
      </div>
      {selected && <RoomPanel room={selected} onClose={() => setOpen(null)} />}
      {floors.map((f) => (
        <div key={f} className="flex flex-col gap-2">
          {floors.length > 1 && <h2 className="text-xs font-semibold text-muted">{f ? t('desk.floor', { floor: f }) : t('desk.noFloor')}</h2>}
          <div className="grid grid-cols-[repeat(auto-fill,minmax(9.5rem,1fr))] gap-2">
            {rooms
              .filter((r) => (r.floor ?? '') === f)
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
                    <span className={cn('size-2.5 rounded-full', ROOM_DOTS[r.status])} />
                  </span>
                  <span className="text-[11px] text-muted">{r.room_type}</span>
                  {r.stay ? (
                    <span className="mt-auto flex flex-col text-[11px]">
                      <span className="truncate font-medium">{r.stay.guest}</span>
                      <span className={r.stay.overdue ? 'font-semibold text-danger-text' : 'text-muted'}>
                        {t('desk.until', { date: date(localDate(r.stay.check_out_at)) })}
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
    </div>
  )
}

/** Tap a room: who is in it, and quick status changes. */
function RoomPanel({ room, onClose }: { room: BoardRoom; onClose: () => void }) {
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
        <Button variant="ghost" onClick={onClose}>
          {t('common.close')}
        </Button>
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
                {localTime(kind === 'arrivals' ? r.check_in_at : r.check_out_at)}
                {r.late && <Badge tone="warn">{t('desk.late')}</Badge>}
                {r.overdue && <Badge tone="danger">{t('desk.overdue')}</Badge>}
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
