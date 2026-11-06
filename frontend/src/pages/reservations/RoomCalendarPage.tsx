import { useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/react/24/outline'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { PageHeader } from '@/components/ui/PageHeader'
import { useMe } from '@/lib/auth'
import { cn } from '@/lib/cn'
import { date } from '@/lib/format'
import { addDays, localDate, localTime, sleepingOn as sleeping, STATUS_TONES, today, useCalendar, type ReservationStatus } from '@/lib/front'

const CELL: Record<ReservationStatus, string> = {
  pending: 'bg-warn-bg text-warn-text',
  confirmed: 'bg-accent/15 text-accent-text',
  checked_in: 'bg-info-bg text-info-text',
  checked_out: 'bg-sunken text-muted',
  cancelled: 'bg-sunken text-muted',
  no_show: 'bg-sunken text-muted',
}

/** One room, one month: who sleeps in it each night, who arrives and who leaves. */
export function RoomCalendarPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const roomId = Number(id)
  const [params] = useSearchParams()
  const me = useMe()
  const branchId = Number(params.get('branch')) || me.data?.branches?.[0]?.id || null
  const [month, setMonth] = useState(() => `${today().slice(0, 7)}-01`)
  const days = daysInMonth(month)
  const cal = useCalendar(branchId, month, days, roomId)
  const room = cal.data?.rooms.find((r) => r.id === roomId)
  const bookings = (cal.data?.bookings ?? []).filter((b) => b.room_id === roomId)

  // Monday-first grid: empty cells before the 1st.
  const firstDay = (new Date(`${month}T00:00:00`).getDay() + 6) % 7
  const cells = [...Array.from({ length: firstDay }, () => null), ...Array.from({ length: days }, (_, i) => addDays(month, i))]
  const nights = cells.filter(Boolean).map((d) => (d ? sleeping(bookings, d) : null))
  const booked = nights.filter(Boolean).length

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={room ? t('roomCal.title', { number: room.number }) : t('roomCal.titlePlain')}
        subtitle={room?.room_type ?? undefined}
        breadcrumbs={[{ label: t('nav.roomBoard'), to: '/rooms' }, { label: room ? t('roomCal.title', { number: room.number }) : '…' }]}
      />
      <div className="flex flex-wrap items-center gap-3">
        <div className="flex items-center gap-1">
          <Button variant="secondary" aria-label={t('reservations.earlier')} onClick={() => setMonth(shiftMonth(month, -1))}>
            <ChevronLeftIcon aria-hidden className="size-4" />
          </Button>
          <Button variant="secondary" onClick={() => setMonth(`${today().slice(0, 7)}-01`)}>
            {t('reservations.today')}
          </Button>
          <Button variant="secondary" aria-label={t('reservations.later')} onClick={() => setMonth(shiftMonth(month, 1))}>
            <ChevronRightIcon aria-hidden className="size-4" />
          </Button>
        </div>
        <h2 className="text-base font-semibold">{monthName(month)}</h2>
        {cal.data && <span className="text-[13px] text-muted">{t('roomCal.booked', { count: booked, days })}</span>}
      </div>

      {cal.data && !room && <Alert tone="info">{t('roomCal.otherBranch')}</Alert>}
      <Card className={cn('overflow-x-auto p-0', cal.data && !room && 'hidden')}>
        <div className="grid min-w-[640px] grid-cols-7 text-[11px] font-semibold text-muted">
          {[1, 2, 3, 4, 5, 6, 7].map((wd) => (
            <div key={wd} className="border-b border-border bg-sunken px-2 py-2 text-center">
              {t(`common.weekdays.${wd}`)}
            </div>
          ))}
          {cells.map((d, i) => {
            if (!d) return <div key={`e${i}`} className="min-h-24 border-b border-r border-border bg-sunken/40" />
            const night = sleeping(bookings, d)
            const leaving = bookings.find((b) => localDate(b.check_out_at) === d && b !== night)
            const arriving = night && localDate(night.check_in_at) === d
            return (
              <div key={d} className={cn('flex min-h-24 flex-col gap-1 border-b border-r border-border p-1.5', d === today() && 'ring-2 ring-accent ring-inset')}>
                <span className={cn('text-xs font-semibold', d === today() ? 'text-accent-text' : 'text-text')}>{Number(d.slice(8, 10))}</span>
                {leaving && (
                  <Link to={`/reservations/${leaving.reservation_id}`} className="truncate text-[11px] text-muted hover:underline">
                    ← {t('roomCal.leaves', { guest: leaving.guest ?? leaving.code })}
                  </Link>
                )}
                {night ? (
                  <Link to={`/reservations/${night.reservation_id}`} className={cn('flex flex-col rounded-md px-1.5 py-1 text-[11px] font-medium transition duration-75 hover:brightness-95', CELL[night.status])}>
                    <span className="truncate">
                      {arriving && '→ '}
                      {night.guest ?? night.code}
                    </span>
                    {arriving && <span className="font-normal opacity-80">{t('roomCal.arrives', { time: localTime(night.check_in_at) })}</span>}
                  </Link>
                ) : (
                  <span className="mt-auto text-[11px] text-muted">{t('roomCal.free')}</span>
                )}
              </div>
            )
          })}
        </div>
      </Card>

      {bookings.length > 0 && (
        <Card className="flex flex-col gap-2">
          <h2 className="text-sm font-semibold">{t('roomCal.list')}</h2>
          <ul className="flex flex-col divide-y divide-border text-[13px]">
            {[...bookings]
              .sort((a, b) => a.check_in_at.localeCompare(b.check_in_at))
              .map((b) => (
                <li key={b.id}>
                  <Link to={`/reservations/${b.reservation_id}`} className="flex min-h-touch items-center justify-between gap-3 py-1.5 hover:text-accent-text">
                    <span className="flex flex-col">
                      <span className="font-medium">{b.guest ?? b.code}</span>
                      <span className="text-xs text-muted">
                        {b.code} · {date(localDate(b.check_in_at))} → {date(localDate(b.check_out_at))}
                      </span>
                    </span>
                    <Badge tone={STATUS_TONES[b.status]}>{t(`reservations.statuses.${b.status}`)}</Badge>
                  </Link>
                </li>
              ))}
          </ul>
        </Card>
      )}
    </div>
  )
}

function daysInMonth(first: string): number {
  const [y, m] = first.split('-').map(Number)
  return new Date(y, m, 0).getDate()
}

function shiftMonth(first: string, n: number): string {
  const [y, m] = first.split('-').map(Number)
  const d = new Date(y, m - 1 + n, 1)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`
}

function monthName(first: string): string {
  return new Date(`${first}T00:00:00`).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' })
}
