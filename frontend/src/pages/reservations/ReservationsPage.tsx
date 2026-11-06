import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { PlusIcon } from '@heroicons/react/20/solid'
import { CalendarDaysIcon, ChevronLeftIcon, ChevronRightIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline'
import { Badge } from '@/components/ui/Badge'
import { Button, buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { PageHeader } from '@/components/ui/PageHeader'
import { Select } from '@/components/ui/Select'
import { Tabs } from '@/components/ui/Tabs'
import { useMe } from '@/lib/auth'
import { cn } from '@/lib/cn'
import { date, money } from '@/lib/format'
import { addDays, daysBetween, localDate, localTime, STATUS_TONES, today, useCalendar, useReservations, type CalendarData, type ReservationStatus } from '@/lib/front'
import { useCan } from '@/lib/hotel'
import { useDebounced } from '@/lib/useDebounced'
import { Pager } from '@/pages/guests/GuestsPage'

const FILTERS: { key: string; status?: string; expiring?: boolean }[] = [
  { key: 'upcoming', status: 'pending,confirmed' },
  { key: 'pending', status: 'pending' },
  { key: 'expiring', expiring: true },
  { key: 'in_house', status: 'checked_in' },
  { key: 'cancelled', status: 'cancelled,no_show' },
  { key: 'all' },
]

export function ReservationsPage() {
  const { t } = useTranslation()
  const can = useCan()
  const [params, setParams] = useSearchParams()
  const view = params.get('view') === 'calendar' ? 'calendar' : 'list'

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={t('nav.reservations')}
        subtitle={t('reservations.subtitle')}
        actions={
          can('reservations.create') && (
            <Link to="/reservations/new" className={buttonClass()}>
              <PlusIcon aria-hidden className="size-4" />
              {t('reservations.new')}
            </Link>
          )
        }
      />
      <div className="max-w-xs">
        <Tabs
          label={t('nav.reservations')}
          active={view}
          onChange={(k) => setParams(k === 'calendar' ? { view: 'calendar' } : {})}
          items={[
            { key: 'list', label: t('reservations.list') },
            { key: 'calendar', label: t('reservations.calendar') },
          ]}
        />
      </div>
      {view === 'list' ? <ReservationList /> : <ReservationCalendar />}
    </div>
  )
}

function ReservationList() {
  const { t } = useTranslation()
  const me = useMe()
  const branches = me.data?.branches ?? []
  const [filter, setFilter] = useState('upcoming')
  const [branchId, setBranchId] = useState<number | null>(null)
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const search = useDebounced(q, 250)
  const f = FILTERS.find((x) => x.key === filter) ?? FILTERS[0]
  const list = useReservations({ q: search, status: f.status, expiring: f.expiring, branch_id: branchId, from: filter === 'upcoming' ? today() : undefined, page })

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-end gap-3">
        <div className="relative w-full max-w-xs">
          <MagnifyingGlassIcon aria-hidden className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-icon" />
          <input
            type="search"
            aria-label={t('reservations.search')}
            placeholder={t('reservations.search')}
            value={q}
            onChange={(e) => {
              setQ(e.target.value)
              setPage(1)
            }}
            className="min-h-touch w-full rounded-lg bg-surface pr-3 pl-9 text-[13px] shadow-sm ring-1 ring-ring outline-none focus:ring-2 focus:ring-accent"
          />
        </div>
        {branches.length > 1 && (
          <div className="w-48">
            <Select label={t('settings.rooms.branch')} value={String(branchId ?? '')} onChange={(e) => setBranchId(e.target.value ? Number(e.target.value) : null)}>
              <option value="">{t('reservations.allBranches')}</option>
              {branches.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.name}
                </option>
              ))}
            </Select>
          </div>
        )}
      </div>
      <div className="flex flex-wrap gap-1.5" role="group" aria-label={t('reservations.filter')}>
        {FILTERS.map((x) => (
          <button
            key={x.key}
            type="button"
            aria-pressed={filter === x.key}
            onClick={() => {
              setFilter(x.key)
              setPage(1)
            }}
            className={cn(
              'min-h-touch rounded-full px-3 text-[13px] font-medium ring-1 transition duration-75',
              filter === x.key ? 'bg-accent text-on-accent ring-accent' : 'bg-surface text-muted ring-ring hover:bg-sunken hover:text-text',
            )}
          >
            {t(`reservations.filters.${x.key}`)}
          </button>
        ))}
      </div>
      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[820px] text-left text-[13px]">
          <thead className="bg-sunken">
            <tr>
              <th className="px-4 py-2.5 font-semibold">{t('reservations.code')}</th>
              <th className="px-4 py-2.5 font-semibold">{t('reservations.guest')}</th>
              <th className="px-4 py-2.5 font-semibold">{t('reservations.stay')}</th>
              <th className="px-4 py-2.5 font-semibold">{t('reservations.rooms')}</th>
              <th className="px-4 py-2.5 font-semibold">{t('reservations.status')}</th>
              <th className="px-4 py-2.5 text-right font-semibold">{t('reservations.total')}</th>
            </tr>
          </thead>
          <tbody>
            {list.data?.data.map((r) => (
              <tr key={r.id} className="border-t border-border transition duration-75 hover:bg-sunken">
                <td className="px-4 py-1.5">
                  <Link to={`/reservations/${r.id}`} className="flex min-h-touch items-center font-medium text-accent-text">
                    {r.code}
                  </Link>
                </td>
                <td className="px-4 py-1.5">
                  <span className="flex flex-col">
                    <span className="font-medium">{r.guest?.name}</span>
                    <span className="text-xs text-muted">{r.guest?.phone}</span>
                  </span>
                </td>
                <td className="px-4 py-1.5 text-muted">
                  <StayText checkIn={r.check_in_at} checkOut={r.check_out_at} stayType={r.stay_type} />
                </td>
                <td className="px-4 py-1.5 text-muted">{r.rooms.map((x) => x.room_number ?? x.room_type).join(', ')}</td>
                <td className="px-4 py-1.5">
                  <span className="flex flex-col items-start gap-0.5">
                    <Badge tone={STATUS_TONES[r.status]}>{t(`reservations.statuses.${r.status}`)}</Badge>
                    {r.status === 'pending' && r.expires_at && <span className="text-[11px] text-warn-text">{t('reservations.expiresAt', { time: `${date(localDate(r.expires_at))} ${localTime(r.expires_at)}` })}</span>}
                  </span>
                </td>
                <td className="px-4 py-1.5 text-right tabular-nums">{money(r.total, r.currency)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {list.data?.data.length === 0 && (
          <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
            <span className="flex size-12 items-center justify-center rounded-full bg-sunken">
              <CalendarDaysIcon aria-hidden className="size-6 text-icon" />
            </span>
            <p className="text-muted">{t('reservations.empty')}</p>
          </div>
        )}
      </Card>
      {list.data && list.data.last_page > 1 && <Pager page={page} last={list.data.last_page} onChange={setPage} />}
    </div>
  )
}

export function StayText({ checkIn, checkOut, stayType }: { checkIn: string; checkOut: string; stayType: string }) {
  const { t } = useTranslation()
  if (stayType === 'nightly') {
    const nights = daysBetween(localDate(checkIn), localDate(checkOut))
    return (
      <span>
        {date(localDate(checkIn))} → {date(localDate(checkOut))} <span className="text-xs">· {t('reservations.nights', { count: nights })}</span>
      </span>
    )
  }
  return (
    <span>
      {date(localDate(checkIn))} {localTime(checkIn)}–{localTime(checkOut)} <span className="text-xs">· {t(`reservations.stayTypes.${stayType}`)}</span>
    </span>
  )
}

/* ------------------------------------------------------------- calendar */

const DAYS = 14

const BAR: Record<ReservationStatus, string> = {
  pending: 'bg-warn-bg text-warn-text ring-warn-text/30',
  confirmed: 'bg-accent/15 text-accent-text ring-accent/40',
  checked_in: 'bg-info-bg text-info-text ring-info-text/30',
  checked_out: 'bg-sunken text-muted ring-ring',
  cancelled: 'bg-sunken text-muted ring-ring',
  no_show: 'bg-sunken text-muted ring-ring',
}

/** Rooms × days. Bookings without a room number yet sit on a row per room type. */
type CalendarBooking = CalendarData['bookings'][number]

function ReservationCalendar() {
  const { t } = useTranslation()
  const me = useMe()
  const branches = me.data?.branches ?? []
  const [branchId, setBranchId] = useState<number | null>(null)
  const [from, setFrom] = useState(() => today())
  const current = branchId ?? branches[0]?.id ?? null
  const cal = useCalendar(current, from, DAYS)
  const days = Array.from({ length: DAYS }, (_, i) => addDays(from, i))

  type Row = { key: string; label: string; sub?: string; roomId: number | null; typeId: number | null }
  const rows: Row[] = []
  const data = cal.data
  if (data) {
    const types = [...new Map(data.rooms.map((r) => [r.room_type_id, r.room_type])).entries()]
    for (const [typeId, typeName] of types) {
      if (data.bookings.some((b) => b.room_id === null && b.room_type_id === typeId)) {
        rows.push({ key: `t${typeId}`, label: t('reservations.unassigned'), sub: typeName ?? '', roomId: null, typeId })
      }
      for (const r of data.rooms.filter((x) => x.room_type_id === typeId)) rows.push({ key: `r${r.id}`, label: r.number, sub: typeName ?? '', roomId: r.id, typeId })
    }
  }
  // Where a booking sits on the grid (in days from the first column).
  const span = (b: CalendarBooking) => {
    const start = Math.max(0, daysBetween(from, localDate(b.check_in_at)) + (b.stay_type === 'nightly' ? 0.5 : 0))
    const end = Math.min(DAYS, daysBetween(from, localDate(b.check_out_at)) + (b.stay_type === 'nightly' ? 0.5 : 1))
    return { start, end }
  }
  // Unassigned bookings of a type share that type's row: each goes on the first line where it does not overlap another.
  const barsFor = (row: Row) => {
    const list = (data?.bookings ?? [])
      .filter((b) => (row.roomId ? b.room_id === row.roomId : b.room_id === null && b.room_type_id === row.typeId))
      .map((b) => ({ b, ...span(b) }))
      .filter((x) => x.end > 0 && x.start < DAYS)
      .sort((a, z) => a.start - z.start)
    const lineEnds: number[] = []
    const placed = list.map((x) => {
      let line = row.roomId ? 0 : lineEnds.findIndex((e) => e <= x.start)
      if (line === -1) line = lineEnds.length
      lineEnds[line] = x.end
      return { ...x, line }
    })
    return { placed, lines: Math.max(1, lineEnds.length) }
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-end gap-3">
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
        <div className="flex items-center gap-1">
          <Button variant="secondary" aria-label={t('reservations.earlier')} onClick={() => setFrom(addDays(from, -7))}>
            <ChevronLeftIcon aria-hidden className="size-4" />
          </Button>
          <Button variant="secondary" onClick={() => setFrom(today())}>
            {t('reservations.today')}
          </Button>
          <Button variant="secondary" aria-label={t('reservations.later')} onClick={() => setFrom(addDays(from, 7))}>
            <ChevronRightIcon aria-hidden className="size-4" />
          </Button>
        </div>
        <span className="text-[13px] text-muted">
          {date(from)} – {date(addDays(from, DAYS - 1))}
        </span>
      </div>
      <Card className="overflow-x-auto p-0">
        <div className="min-w-[960px]">
          <div className="grid border-b border-border bg-sunken text-[11px] font-semibold text-muted" style={{ gridTemplateColumns: `7rem repeat(${DAYS}, minmax(0, 1fr))` }}>
            <div className="px-3 py-2">{t('reservations.room')}</div>
            {days.map((d) => {
              const wd = new Date(`${d}T00:00:00`).getDay()
              return (
                <div key={d} className={cn('border-l border-border px-1 py-2 text-center', d === today() && 'bg-accent/10 text-accent-text', (wd === 0 || wd === 6) && 'text-text')}>
                  {t(`common.weekdays.${wd === 0 ? 7 : wd}`)} {Number(d.slice(8, 10))}
                </div>
              )
            })}
          </div>
          {rows.map((row) => {
            const { placed, lines } = barsFor(row)
            return (
              <div key={row.key} className="relative grid border-b border-border last:border-b-0" style={{ gridTemplateColumns: `7rem repeat(${DAYS}, minmax(0, 1fr))` }}>
                <div className="flex flex-col justify-center px-3 py-1.5">
                  <span className={cn('text-[13px] font-medium', !row.roomId && 'text-muted italic')}>{row.label}</span>
                  <span className="text-[11px] text-muted">{row.sub}</span>
                </div>
                {days.map((d) => (
                  <div key={d} className={cn('border-l border-border', d === today() && 'bg-accent/5')} style={{ minHeight: `${Math.max(44, lines * 32 + 12)}px` }} />
                ))}
                <div className="pointer-events-none absolute inset-y-0 right-0 left-28 py-1">
                  {placed.map(({ b, start, end, line }) => (
                    <Link
                      key={b.id}
                      to={`/reservations/${b.reservation_id}`}
                      title={`${b.code} · ${b.guest ?? ''}`}
                      className={cn('pointer-events-auto absolute flex h-7 items-center overflow-hidden rounded-md px-2 text-[11px] font-medium whitespace-nowrap ring-1 transition duration-75 hover:brightness-95', BAR[b.status])}
                      style={{ left: `${(start / DAYS) * 100}%`, width: `${((end - start) / DAYS) * 100}%`, top: 8 + line * 32 }}
                    >
                      {b.guest ?? b.code}
                    </Link>
                  ))}
                </div>
              </div>
            )
          })}
          {data && rows.length === 0 && <p className="p-6 text-center text-muted">{t('reservations.noRooms')}</p>}
        </div>
      </Card>
    </div>
  )
}
