import { lazy, Suspense, useState, type ReactNode } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/shadcn/sheet'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { LoadFailed } from '@/components/ui/LoadFailed'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { useInvoice } from '@/lib/desk'
import { date, money } from '@/lib/format'
import { useReservation } from '@/lib/front'
import { useCan } from '@/lib/hotel'
import { isPositive } from '@/lib/decimal'
import { useDrilldown } from '@/lib/reports'

/*
 * Details IN PLACE (P18): a side sheet over the dashboard with the SAME panels the booking and bill pages use
 * (same rules, same "saved once" keys). The panels load only when a sheet opens.
 */
const CheckInPanel = lazy(() => import('@/pages/reservations/StayPanels').then((m) => ({ default: m.CheckInPanel })))
const CheckOutPanel = lazy(() => import('@/pages/reservations/StayPanels').then((m) => ({ default: m.CheckOutPanel })))
const PaymentForm = lazy(() => import('@/pages/reservations/ReservationPage').then((m) => ({ default: m.PaymentForm })))
const PayPanel = lazy(() => import('@/pages/billing/InvoicePage').then((m) => ({ default: m.PayPanel })))

export function SideSheet({ open, onClose, title, description, children }: { open: boolean; onClose: () => void; title: ReactNode; description?: ReactNode; children: ReactNode }) {
  return (
    <Sheet open={open} onOpenChange={(o) => !o && onClose()}>
      <SheetContent className="w-full overflow-y-auto sm:max-w-md">
        <SheetHeader className="pe-10">
          <SheetTitle className="text-lg">{title}</SheetTitle>
          {description && <SheetDescription>{description}</SheetDescription>}
        </SheetHeader>
        <div className="flex flex-col gap-4 px-4 pb-6">{children}</div>
      </SheetContent>
    </Sheet>
  )
}

const Loading = () => <div aria-hidden className="h-40 animate-pulse rounded-lg bg-sunken motion-reduce:animate-none" />

export type StayAction = 'view' | 'check_in' | 'check_out' | 'pay'

/** A booking in a side sheet: summary, then check in / check out / take payment with the existing panels. */
export function ReservationSheet({ id, action: start, onClose }: { id: number | null; action: StayAction; onClose: () => void }) {
  const { t } = useTranslation()
  const can = useCan()
  const q = useReservation(id ?? 0)
  const [action, setAction] = useState<StayAction>(start)
  const r = q.data?.data
  const close = () => {
    setAction('view')
    onClose()
  }
  const rooms = r?.rooms.map((x) => [x.room_number, x.room_type].filter(Boolean).join(' · ')).join(', ')
  return (
    <SideSheet open={id !== null} onClose={close} title={r ? (r.guest.full_name ?? r.code) : t('common.loading')} description={r ? `${r.code} · ${rooms}` : undefined}>
      {q.isError && <LoadFailed error={q.error} onRetry={() => q.refetch()} />}
      {q.isPending && <Loading />}
      {r && (
        <>
          <dl className="grid grid-cols-2 gap-3 rounded-lg border border-border p-3 text-sm">
            <div className="flex flex-col">
              <dt className="text-xs text-muted">{t('dash.sheet.status')}</dt>
              <dd>
                <StatusBadge status={r.status} />
              </dd>
            </div>
            <div className="flex flex-col">
              <dt className="text-xs text-muted">{t('dash.sheet.stay')}</dt>
              <dd>
                {date(r.check_in_at)} → {date(r.check_out_at)}
              </dd>
            </div>
            <div className="flex flex-col">
              <dt className="text-xs text-muted">{t('dash.sheet.total')}</dt>
              <dd className="tabular-nums">{money(r.total, r.currency)}</dd>
            </div>
            <div className="flex flex-col">
              <dt className="text-xs text-muted">{t('dash.sheet.balance')}</dt>
              <dd className={isPositive(r.balance) ? 'font-semibold text-warn-text tabular-nums' : 'tabular-nums'}>{money(r.balance, r.currency)}</dd>
            </div>
          </dl>
          <Suspense fallback={<Loading />}>
            {action === 'check_in' && <CheckInPanel r={r} onClose={() => setAction('view')} />}
            {action === 'check_out' && <CheckOutPanel r={r} onClose={() => setAction('view')} />}
            {action === 'pay' && <PaymentForm r={r} onDone={() => setAction('view')} />}
          </Suspense>
          {action === 'view' && (
            <div className="flex flex-col gap-2">
              {(r.status === 'pending' || r.status === 'confirmed') && can('frontdesk.check_in') && (
                <Button size="lg" onClick={() => setAction('check_in')}>
                  {t('dash.actions.checkIn')}
                </Button>
              )}
              {r.status === 'checked_in' && can('frontdesk.check_out') && (
                <Button size="lg" onClick={() => setAction('check_out')}>
                  {t('dash.actions.checkOut')}
                </Button>
              )}
              {isPositive(r.balance) && can('payments.take') && (
                <Button size="lg" variant="secondary" onClick={() => setAction('pay')}>
                  {t('dash.actions.takePayment')}
                </Button>
              )}
            </div>
          )}
          <Link to={`/reservations/${r.id}`} className="tap-link w-fit font-medium text-accent-text">
            {t('dash.sheet.openBooking')} →
          </Link>
        </>
      )}
    </SideSheet>
  )
}

/** An unpaid bill in a side sheet, with the existing Record payment panel. */
export function InvoiceSheet({ id, onClose }: { id: number | null; onClose: () => void }) {
  const { t } = useTranslation()
  const q = useInvoice(id ?? 0)
  const inv = q.data?.data
  return (
    <SideSheet open={id !== null} onClose={onClose} title={inv ? inv.guest.full_name : t('common.loading')} description={inv?.number}>
      {q.isError && <LoadFailed error={q.error} onRetry={() => q.refetch()} />}
      {q.isPending && <Loading />}
      {inv && (
        <>
          <dl className="grid grid-cols-2 gap-3 rounded-lg border border-border p-3 text-sm">
            <div className="flex flex-col">
              <dt className="text-xs text-muted">{t('dash.sheet.total')}</dt>
              <dd className="tabular-nums">{money(inv.total, inv.currency)}</dd>
            </div>
            <div className="flex flex-col">
              <dt className="text-xs text-muted">{t('dash.sheet.balance')}</dt>
              <dd className="font-semibold text-warn-text tabular-nums">{money(inv.balance, inv.currency)}</dd>
            </div>
          </dl>
          {isPositive(inv.balance) && (
            <Suspense fallback={<Loading />}>
              <PayPanel inv={inv} onClose={onClose} />
            </Suspense>
          )}
          <Link to={`/billing/${inv.id}`} className="tap-link w-fit font-medium text-accent-text">
            {t('dash.sheet.openBill')} →
          </Link>
        </>
      )}
    </SideSheet>
  )
}

/** One day from a chart: the rooms sold that night and the money taken, with links to the full report. */
export function DaySheet({ day, branchId, money: showMoney, onClose }: { day: string | null; branchId: number | null; money: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const can = useCan()
  const nights = useDrilldown('room_nights', day, branchId)
  const payments = useDrilldown('payments', showMoney && can(['reports.payments', 'reports.revenue']) ? day : null, branchId)
  const rows = nights.data?.data ?? []
  const pays = payments.data?.data ?? []
  return (
    <SideSheet open={day !== null} onClose={onClose} title={day ? date(day) : ''} description={t('dash.sheet.dayHelp')}>
      {nights.isPending && <Loading />}
      {nights.isError && <LoadFailed error={nights.error} onRetry={() => nights.refetch()} />}
      {nights.data && (
        <section className="flex flex-col gap-2">
          <h3 className="text-sm font-semibold">{t('dash.sheet.roomsSold', { count: rows.length })}</h3>
          <ul className="flex flex-col divide-y divide-border rounded-lg border border-border text-sm">
            {rows.slice(0, 40).map((r) => (
              <li key={`${r.reservation_id}-${r.room}`}>
                <Link to={`/reservations/${r.reservation_id}`} className="flex min-h-11 items-center gap-2 px-3 hover:bg-sunken/50">
                  <span className="w-12 font-semibold">{r.room ?? '—'}</span>
                  <span className="min-w-0 flex-1 truncate">{r.guest}</span>
                  {showMoney && <span className="tabular-nums text-muted">{money(String(r.amount))}</span>}
                </Link>
              </li>
            ))}
            {!rows.length && <li className="px-3 py-4 text-muted">{t('dash.empty')}</li>}
          </ul>
        </section>
      )}
      {payments.data && (
        <section className="flex flex-col gap-2">
          <h3 className="text-sm font-semibold">{t('dash.sheet.paymentsThatDay', { count: pays.length })}</h3>
          <ul className="flex flex-col divide-y divide-border rounded-lg border border-border text-sm">
            {pays.slice(0, 40).map((p) => (
              <li key={String(p.id)} className="flex min-h-11 items-center gap-2 px-3">
                <span className="w-12 text-muted tabular-nums">{p.time}</span>
                <span className="min-w-0 flex-1 truncate">{p.guest ?? '—'}</span>
                <Badge tone="grey">{p.method ?? '—'}</Badge>
                <span className="font-medium tabular-nums">{money(String(p.amount))}</span>
              </li>
            ))}
            {!pays.length && <li className="px-3 py-4 text-muted">{t('dash.empty')}</li>}
          </ul>
        </section>
      )}
      <Link to="/reports?report=performance" className="tap-link w-fit font-medium text-accent-text">
        {t('dash.viewAll')} →
      </Link>
    </SideSheet>
  )
}
