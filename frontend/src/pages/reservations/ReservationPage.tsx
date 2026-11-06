import { useState, type ReactNode } from 'react'
import { Link, useParams, useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { SmartHints } from '@/components/ui/SmartHints'
import { useSmart } from '@/lib/smart'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button, buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Select } from '@/components/ui/Select'
import { ApiError } from '@/lib/api'
import { compareMoney, isPositive, subMoney } from '@/lib/decimal'
import { date, money } from '@/lib/format'
import { localDate, localTime, STATUS_TONES, today, useCancellationTerms, useCancelReservation, useLookups, usePayLater, useRecordPayment, useReservation, type PaymentInput, type ReservationDetail } from '@/lib/front'
import { useCan } from '@/lib/hotel'
import { Line, QuoteSummary } from './ReservationFormPage'
import { StayText } from './ReservationsPage'
import { ChangeStayPanel, CheckInPanel, CheckOutPanel } from './StayPanels'

export function ReservationPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const can = useCan()
  const q = useReservation(Number(id))
  const smart = useSmart('reservation', { id: Number(id) }, Number(id) > 0)
  const [params] = useSearchParams()
  const [action, setAction] = useState<'pay' | 'cancel' | 'no_show' | 'check_in' | 'check_out' | 'change' | null>(() =>
    params.get('action') === 'check-in' ? 'check_in' : params.get('action') === 'check-out' ? 'check_out' : null,
  )
  const lookups = useLookups()
  const payLater = usePayLater(Number(id))
  if (!q.data) return null
  const r = q.data.data
  const editable = r.status === 'pending' || r.status === 'confirmed'
  const arrived = Date.parse(r.check_in_at) <= Date.now()
  const canArrive = editable && localDate(r.check_in_at) <= today() && localDate(r.check_out_at) >= today()

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={r.code}
        subtitle={
          <span className="flex flex-wrap items-center gap-2">
            <Badge tone={STATUS_TONES[r.status]}>{t(`reservations.statuses.${r.status}`)}</Badge>
            <span>{r.branch?.name}</span>
            {r.status === 'pending' && r.expires_at && <span className="text-warn-text">{t('reservations.expiresAt', { time: `${date(localDate(r.expires_at))} ${localTime(r.expires_at)}` })}</span>}
            {r.pay_later && <Badge tone="info">{t('payLater.badge', { name: r.pay_later.by ?? '—' })}</Badge>}
          </span>
        }
        breadcrumbs={[{ label: t('nav.reservations'), to: '/reservations' }, { label: r.code }]}
        actions={
          <div className="flex flex-wrap gap-2">
            {['confirmed', 'pending', 'checked_in'].includes(r.status) && (
              <Link to={`/reservations/${r.id}/registration-card`} className={buttonClass('secondary')}>
                {t('regCard.print')}
              </Link>
            )}
            {canArrive && can('frontdesk.check_in') && action !== 'check_in' && (
              <Button size="lg" onClick={() => setAction('check_in')}>
                {t('desk.checkIn')}
              </Button>
            )}
            {r.status === 'checked_in' && can('frontdesk.check_out') && action !== 'check_out' && (
              <Button size="lg" onClick={() => setAction('check_out')}>
                {t('desk.checkOut')}
              </Button>
            )}
            {r.status === 'checked_in' && can('frontdesk.room_move') && (
              <Button variant="secondary" onClick={() => setAction('change')}>
                {t('desk.changeStay')}
              </Button>
            )}
            {r.status === 'pending' && can('reservations.edit') && lookups.data?.pay_later && (
              <Button variant="secondary" disabled={payLater.isPending} onClick={() => payLater.mutate()}>
                {t('payLater.option')}
              </Button>
            )}
            {editable && can('reservations.edit') && (
              <Link to={`/reservations/${r.id}/edit`} className={buttonClass('secondary')}>
                {t('common.edit')}
              </Link>
            )}
            {editable && can('reservations.no_show') && arrived && (
              <Button variant="secondary" onClick={() => setAction('no_show')}>
                {t('reservations.markNoShow')}
              </Button>
            )}
            {editable && can('reservations.cancel') && (
              <Button variant="danger" onClick={() => setAction('cancel')}>
                {t('reservations.cancel')}
              </Button>
            )}
          </div>
        }
      />

      <SmartHints hints={smart.data?.data} />

      {(action === 'cancel' || action === 'no_show') && <CancelPanel r={r} noShow={action === 'no_show'} onClose={() => setAction(null)} />}
      {action === 'check_in' && canArrive && <CheckInPanel r={r} onClose={() => setAction(null)} />}
      {action === 'check_out' && r.status === 'checked_in' && <CheckOutPanel r={r} onClose={() => setAction(null)} />}
      {action === 'change' && r.status === 'checked_in' && <ChangeStayPanel r={r} onClose={() => setAction(null)} />}

      <div className="grid items-start gap-6 lg:grid-cols-[1fr_22rem]">
        <div className="flex flex-col gap-6">
          <Card className="grid gap-4 text-[13px] sm:grid-cols-3">
            <Info label={t('reservations.guest')}>
              <Link to={`/guests/${r.guest.id}`} className="font-medium text-accent-text">
                {r.guest.full_name}
              </Link>
              <span className="text-xs text-muted">{r.guest.phone}</span>
              {r.guest.details_missing && (
                <Link to={`/guests/${r.guest.id}`} className="w-fit">
                  <Badge tone="warn">{t('guests.detailsMissing')}</Badge>
                </Link>
              )}
            </Info>
            <Info label={t('reservations.stay')}>
              <StayText checkIn={r.check_in_at} checkOut={r.check_out_at} stayType={r.stay_type} />
              <span className="text-xs text-muted">
                {t('reservations.checkInOut', { in: localTime(r.check_in_at), out: localTime(r.check_out_at) })}
              </span>
            </Info>
            <Info label={t('reservations.guests')}>
              <span>{t('reservations.people', { adults: t('reservations.adultsCount', { count: r.adults }), children: t('reservations.childrenCount', { count: r.children }) })}</span>
              <span className="text-xs text-muted">{t(`reservations.sources.${r.source}`)}</span>
            </Info>
            {r.notes && (
              <div className="sm:col-span-3">
                <Info label={t('reservations.form.notes')}>{r.notes}</Info>
              </div>
            )}
            {r.cancel_reason && (
              <div className="sm:col-span-3">
                <Alert tone="info">{t('reservations.cancelledBecause', { reason: r.cancel_reason === 'expired' ? t('reservations.expiredReason') : r.cancel_reason })}</Alert>
              </div>
            )}
          </Card>

          <Card className="flex flex-col gap-3">
            <h2 className="text-sm font-semibold">{t('reservations.rooms')}</h2>
            <QuoteSummary q={{ ...r, rooms: r.rooms.map((x) => ({ room_type: x.room_type ?? '', room_number: x.room_number, total: x.total, lines: x.lines })), policy_name: r.policy?.name }} />
          </Card>

          <Card className="flex flex-col gap-3">
            <div className="flex items-center justify-between gap-2">
              <h2 className="text-sm font-semibold">{t('reservations.payments')}</h2>
              {(can('payments.refund') || (can('payments.take') && (['pending', 'confirmed', 'checked_in'].includes(r.status) || isPositive(r.balance)))) && action !== 'pay' && (
                <Button variant="secondary" onClick={() => setAction('pay')}>
                  {t('reservations.recordPayment')}
                </Button>
              )}
            </div>
            {action === 'pay' && <PaymentForm r={r} onDone={() => setAction(null)} />}
            {r.payments.length === 0 && <p className="text-[13px] text-muted">{t('reservations.noPayments')}</p>}
            <ul className="flex flex-col divide-y divide-border text-[13px]">
              {r.payments.map((p) => (
                <li key={p.id} className="flex items-center justify-between gap-3 py-2">
                  <span className="flex flex-col">
                    <span className="font-medium">
                      {t(`reservations.paymentTypes.${p.type}`)} · {p.method}
                    </span>
                    <span className="text-xs text-muted">
                      {date(localDate(p.paid_at))} {localTime(p.paid_at)} · {p.by}
                      {p.reference && ` · ${p.reference}`}
                      {p.refund_reason && ` · ${p.refund_reason}`}
                    </span>
                  </span>
                  <span className={p.type === 'refund' ? 'text-danger-text tabular-nums' : 'tabular-nums'}>
                    {p.type === 'refund' ? '− ' : ''}
                    {money(p.amount, p.currency)}
                    {p.currency !== r.currency && <span className="block text-right text-xs text-muted">{money(p.amount_in_base, r.currency)}</span>}
                  </span>
                </li>
              ))}
            </ul>
          </Card>
        </div>

        <Card className="flex flex-col gap-2 text-[13px] lg:sticky lg:top-20">
          <h2 className="text-sm font-semibold">{t('reservations.money')}</h2>
          <Line label={t('reservations.total')} value={money(r.total, r.currency)} />
          <Line label={t('reservations.paid')} value={money(r.paid_total, r.currency)} />
          {r.cancellation_fee !== null && <Line label={t('reservations.cancellationFee')} value={money(r.cancellation_fee, r.currency)} />}
          <Line label={t('reservations.balance')} value={money(r.balance, r.currency)} strong />
          {r.status === 'pending' && compareMoney(r.deposit_required, r.paid_total) > 0 && (
            <Alert tone="info">{t('reservations.depositMissing', { amount: money(subMoney(r.deposit_required, r.paid_total), r.currency) })}</Alert>
          )}
          {r.policy && <p className="text-xs text-muted">{t('reservations.policyLine', { name: r.policy.name, hours: r.policy.free_until_hours ?? 0, context: r.policy.free_until_hours === null ? 'never' : undefined })}</p>}
          {(r.invoices?.length ?? 0) > 0 && (
            <div className="flex flex-col gap-1 border-t border-border pt-2">
              {r.invoices?.map((i) => (
                <Link key={i.id} to={`/billing/${i.id}`} className="flex min-h-touch items-center justify-between gap-2 text-accent-text hover:underline">
                  <span>{i.number}</span>
                  <span className="tabular-nums text-muted">{money(i.balance, r.currency)}</span>
                </Link>
              ))}
            </div>
          )}
          <p className="text-xs text-muted">{t('reservations.createdBy', { name: r.created_by ?? '—', date: date(localDate(r.created_at)) })}</p>
        </Card>
      </div>
    </div>
  )
}

function Info({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-xs text-muted">{label}</span>
      {children}
    </div>
  )
}

function PaymentForm({ r, onDone }: { r: ReservationDetail; onDone: () => void }) {
  const { t } = useTranslation()
  const can = useCan()
  const lookups = useLookups()
  const pay = useRecordPayment(r.id)
  // After check-out, money still owed (approved by a manager) can still be paid here.
  const active = r.status === 'pending' || r.status === 'confirmed' || r.status === 'checked_in' || isPositive(r.balance)
  const beforeStay = r.status === 'pending' || r.status === 'confirmed'
  const types = (['deposit', 'payment', 'refund'] as const).filter((x) =>
    x === 'refund' ? can('payments.refund') : can('payments.take') && active && (x === 'payment' || beforeStay),
  )
  const due = r.status === 'pending' ? subMoney(r.deposit_required, r.paid_total) : r.balance
  const [f, setF] = useState<PaymentInput>({ type: types[0] ?? 'deposit', payment_method_id: 0, amount: isPositive(due) ? due : '', currency: r.currency, reference: '', refund_reason: '' })
  const methodId = f.payment_method_id || lookups.data?.payment_methods[0]?.id || 0
  const error = pay.error instanceof ApiError ? pay.error : null

  return (
    <form
      noValidate
      className="grid gap-3 rounded-lg bg-sunken p-4 sm:grid-cols-3"
      onSubmit={(e) => {
        e.preventDefault()
        pay.mutate({ ...f, payment_method_id: methodId }, { onSuccess: onDone })
      }}
    >
      <Select label={t('reservations.paymentType')} value={f.type} onChange={(e) => setF({ ...f, type: e.target.value as PaymentInput['type'] })}>
        {types.map((x) => (
          <option key={x} value={x}>
            {t(`reservations.paymentTypes.${x}`)}
          </option>
        ))}
      </Select>
      <Select label={t('reservations.method')} value={String(methodId)} onChange={(e) => setF({ ...f, payment_method_id: Number(e.target.value) })} error={error?.field('payment_method_id')}>
        {lookups.data?.payment_methods.map((m) => (
          <option key={m.id} value={m.id}>
            {m.name}
          </option>
        ))}
      </Select>
      <div className="flex gap-2">
        <div className="flex-1">
          <Field label={t('reservations.amount')} inputMode="decimal" value={f.amount} onChange={(e) => setF({ ...f, amount: e.target.value.replace(/[^0-9.]/g, '') })} error={error?.field('amount')} />
        </div>
        {(lookups.data?.currencies.length ?? 0) > 1 && (
          <div className="w-24">
            <Select label={t('reservations.currency')} value={f.currency} onChange={(e) => setF({ ...f, currency: e.target.value })} error={error?.field('currency')}>
              {lookups.data?.currencies.map((c) => (
                <option key={c.code} value={c.code}>
                  {c.code}
                </option>
              ))}
            </Select>
          </div>
        )}
      </div>
      <Field label={t('reservations.reference')} value={f.reference ?? ''} hint={t('reservations.referenceHint')} onChange={(e) => setF({ ...f, reference: e.target.value })} />
      {f.type === 'refund' && <Field label={t('reservations.refundReason')} value={f.refund_reason ?? ''} onChange={(e) => setF({ ...f, refund_reason: e.target.value })} error={error?.field('refund_reason')} />}
      {pay.error && !error && <Alert>{t('common.serverUnreachable')}</Alert>}
      {error && !Object.keys(error.errors).length && <Alert>{error.message}</Alert>}
      <div className="flex items-end justify-end gap-2 sm:col-span-3">
        <Button variant="ghost" onClick={onDone}>
          {t('common.cancel')}
        </Button>
        <Button type="submit" disabled={pay.isPending || !f.amount || !types.length}>
          {t('reservations.savePayment')}
        </Button>
      </div>
    </form>
  )
}

function CancelPanel({ r, noShow, onClose }: { r: ReservationDetail; noShow: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const terms = useCancellationTerms(r.id, noShow, true)
  const cancel = useCancelReservation(r.id)
  const [reason, setReason] = useState(noShow ? t('reservations.noShowReason') : '')
  const error = cancel.error instanceof ApiError ? cancel.error : null
  const x = terms.data?.data

  return (
    <Card className="flex flex-col gap-3 ring-danger/40 animate-page-in">
      <h2 className="text-sm font-semibold">{noShow ? t('reservations.markNoShow') : t('reservations.cancelTitle')}</h2>
      {x && (
        <div className="flex flex-col gap-1 text-[13px]">
          <p className="text-muted">{x.free ? t('reservations.cancelFree') : t('reservations.cancelFee', { fee: money(x.fee, r.currency), policy: x.policy?.name ?? '' })}</p>
          {isPositive(r.paid_total) && <p className="text-muted">{t('reservations.refundDue', { amount: money(x.refund_due, r.currency) })}</p>}
        </div>
      )}
      <Field label={t('reservations.reason')} value={reason} onChange={(e) => setReason(e.target.value)} error={error?.field('reason')} />
      {error?.field('status') && <Alert>{error.field('status')}</Alert>}
      <div className="flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose}>
          {t('common.back')}
        </Button>
        <Button variant="danger" disabled={!reason || cancel.isPending} onClick={() => cancel.mutate({ reason, noShow }, { onSuccess: onClose })}>
          {noShow ? t('reservations.confirmNoShow') : t('reservations.confirmCancel')}
        </Button>
      </div>
    </Card>
  )
}
