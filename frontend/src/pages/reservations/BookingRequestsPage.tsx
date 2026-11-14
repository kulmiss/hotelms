import { useState } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button, buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { PageHeader } from '@/components/ui/PageHeader'
import { Tabs } from '@/components/ui/Tabs'
import { ApiError } from '@/lib/api'
import { useBookingRequests, useHandleBookingRequest, type BookingRequest } from '@/lib/bookingRequests'
import { date, dateTime } from '@/lib/format'
import { useCan } from '@/lib/hotel'

const STATUSES = ['new', 'accepted', 'declined'] as const

/** Requests from the public booking page: call the guest, then book it with the normal form, or decline. */
export function BookingRequestsPage() {
  const { t } = useTranslation()
  const [status, setStatus] = useState<(typeof STATUSES)[number]>('new')
  const q = useBookingRequests(status)
  const can = useCan()
  return (
    <div className="mx-auto flex w-full max-w-5xl flex-col gap-4">
      <PageHeader
        title={t('nav.bookingRequests')}
        subtitle={t('bookingRequests.subtitle')}
        actions={can('settings.hotel') ? <Link to="/settings/booking-page" className={buttonClass('secondary')}>{t('bookingRequests.settings')}</Link> : undefined}
      />
      <Tabs label={t('nav.bookingRequests')} active={status} onChange={(k) => setStatus(k as typeof status)} items={STATUSES.map((k) => ({ key: k, label: `${t(`bookingRequests.status.${k}`)}${k === 'new' && q.data?.new ? ` (${q.data.new})` : ''}` }))} />
      {q.data?.data.length === 0 && <p className="text-sm text-muted">{status === 'new' ? t('bookingRequests.emptyNew') : t('bookingRequests.empty')}</p>}
      <div className="grid gap-4 md:grid-cols-2">
        {q.data?.data.map((r) => <RequestCard key={r.id} r={r} canBook={can('reservations.create')} />)}
      </div>
    </div>
  )
}

function RequestCard({ r, canBook }: { r: BookingRequest; canBook: boolean }) {
  const { t } = useTranslation()
  const handle = useHandleBookingRequest()
  const error = handle.error instanceof ApiError ? (Object.values(handle.error.errors)[0]?.[0] ?? handle.error.message) : null
  return (
    <Card className="flex flex-col gap-3">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <h2 className="truncate font-semibold">{r.name}</h2>
          <a href={`tel:${r.phone.replace(/[^0-9+]/g, '')}`} className="text-sm font-medium text-accent underline">{r.phone}</a>
          {r.email && <p className="truncate text-sm text-muted">{r.email}</p>}
        </div>
        <Badge tone={r.status === 'new' ? 'info' : r.status === 'accepted' ? 'ok' : 'grey'}>{t(`bookingRequests.status.${r.status}`)}</Badge>
      </div>
      <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
        <dt className="text-muted">{t('bookingRequests.dates')}</dt>
        <dd>{date(r.check_in)} – {date(r.check_out)}</dd>
        <dt className="text-muted">{t('bookingRequests.room')}</dt>
        <dd>{r.room_type?.name ?? t('bookingRequests.anyRoom')}{r.branch ? ` · ${r.branch.name}` : ''}</dd>
        <dt className="text-muted">{t('bookingRequests.guests')}</dt>
        <dd>{t('bookingRequests.guestCount', { adults: r.adults, children: r.children })}</dd>
        {r.note && (
          <>
            <dt className="text-muted">{t('bookingRequests.message')}</dt>
            <dd className="whitespace-pre-line">{r.note}</dd>
          </>
        )}
        <dt className="text-muted">{t('bookingRequests.sent')}</dt>
        <dd>{dateTime(r.created_at)}</dd>
      </dl>
      {error && <Alert>{error}</Alert>}
      {r.status === 'new' && canBook && (
        <div className="flex flex-wrap gap-2">
          <Link to={`/reservations/new?request=${r.id}`} className={buttonClass('primary')}>{t('bookingRequests.book')}</Link>
          <Button variant="secondary" disabled={handle.isPending} onClick={() => handle.mutate({ id: r.id, action: 'decline' })}>{t('bookingRequests.decline')}</Button>
        </div>
      )}
      {r.reservation_id && <Link to={`/reservations/${r.reservation_id}`} className="text-sm font-medium text-accent underline">{t('bookingRequests.openBooking')}</Link>}
    </Card>
  )
}
