import { useEffect, useState, type FormEvent } from 'react'
import { useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { ClipboardDocumentIcon, KeyIcon } from '@heroicons/react/24/outline'
import { CheckIcon } from '@heroicons/react/20/solid'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Select } from '@/components/ui/Select'
import { StatusBadge } from '@/components/ui/StatusBadge'
import {
  getQuote,
  useActivateHotel,
  useHotel,
  usePlans,
  useRecordPayment,
  useResetUserPassword,
  useSetTrial,
  useSuspendHotel,
  type Hotel,
} from '@/lib/admin'
import { ApiError } from '@/lib/api'
import { date, money } from '@/lib/format'

type Panel = 'payment' | 'suspend' | 'trial' | null

export function HotelPage() {
  const { t } = useTranslation()
  const id = Number(useParams().id)
  const hotel = useHotel(id)
  const [panel, setPanel] = useState<Panel>(null)

  if (hotel.isPending) return <p className="text-muted">{t('common.loading')}</p>
  if (hotel.isError) return <Alert>{t('common.serverUnreachable')}</Alert>

  const h = hotel.data.data
  const toggle = (p: Panel) => setPanel((cur) => (cur === p ? null : p))

  return (
    <div className="mx-auto flex max-w-6xl flex-col gap-6">
      <PageHeader
        title={h.name}
        breadcrumbs={[{ label: t('nav.hotels'), to: '/admin/hotels' }, { label: h.name }]}
        subtitle={
          <span className="flex flex-wrap items-center gap-2">
            <StatusBadge status={h.status} />
            <span>{[h.plan?.name, h.city].filter(Boolean).join(', ')}</span>
          </span>
        }
        actions={
          <>
            <Button onClick={() => toggle('payment')} variant={panel === 'payment' ? 'secondary' : 'primary'}>
              {t('admin.hotel.recordPayment')}
            </Button>
            <Button variant="secondary" onClick={() => toggle('trial')}>
              {t('admin.hotel.changeTrial')}
            </Button>
            {h.status === 'suspended' && h.suspend_reason !== null ? (
              <ActivateButton hotelId={h.id} />
            ) : (
              <Button variant="secondary" onClick={() => toggle('suspend')}>
                {t('admin.hotel.suspend')}
              </Button>
            )}
          </>
        }
      />

      {h.status === 'suspended' && (
        <Alert>{h.suspend_reason ? t('admin.hotel.suspendedBecause', { reason: h.suspend_reason }) : t('admin.hotel.suspendedUnpaid')}</Alert>
      )}

      {panel === 'payment' && <PaymentPanel hotel={h} onDone={() => setPanel(null)} />}
      {panel === 'suspend' && <SuspendPanel hotelId={h.id} onDone={() => setPanel(null)} />}
      {panel === 'trial' && <TrialPanel hotel={h} onDone={() => setPanel(null)} />}

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="flex flex-col gap-3">
          <h2 className="font-semibold">{t('admin.hotel.subscription')}</h2>
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            <dt className="text-muted">{t('admin.hotels.plan')}</dt>
            <dd>{h.plan?.name ?? '—'}</dd>
            <dt className="text-muted">{t('admin.newHotel.billing')}</dt>
            <dd>{t(`admin.billing.${h.billing_cycle}`)}</dd>
            <dt className="text-muted">{t('admin.hotels.paidUntil')}</dt>
            <dd>{date(h.subscription_ends_at)}</dd>
            <dt className="text-muted">{t('admin.hotel.trialEnds')}</dt>
            <dd>
              {date(h.trial_ends_at)}
              {h.trial_plan && <span className="text-muted"> ({h.trial_plan.name})</span>}
            </dd>
          </dl>
        </Card>

        <Card className="flex flex-col gap-3">
          <h2 className="font-semibold">{t('admin.hotel.contact')}</h2>
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            <dt className="text-muted">{t('admin.newHotel.email')}</dt>
            <dd className="break-all">{h.email ?? '—'}</dd>
            <dt className="text-muted">{t('admin.newHotel.phone')}</dt>
            <dd>{h.phone ?? '—'}</dd>
            <dt className="text-muted">{t('admin.newHotel.address')}</dt>
            <dd>{h.address ?? '—'}</dd>
          </dl>
          <h3 className="mt-2 text-sm font-semibold">{t('admin.hotel.staff')}</h3>
          <ul className="flex flex-col gap-1 text-sm">
            {hotel.data.admins.map((a) => (
              <li key={a.id} className="flex flex-col gap-2">
                <div className="flex flex-col">
                  <span className="font-medium">{a.name}</span>
                  <span className="text-muted break-all">
                    {a.email}, {a.must_change_password ? t('admin.hotel.notSignedInYet') : t('admin.hotel.lastLogin', { date: date(a.last_login_at) })}
                  </span>
                </div>
                <ResetPassword hotelId={h.id} user={a} />
              </li>
            ))}
          </ul>
        </Card>

        <Card className="flex flex-col gap-3">
          <h2 className="font-semibold">{t('admin.hotels.branches')}</h2>
          <ul className="flex flex-col gap-1 text-sm">
            {hotel.data.branches.map((b) => (
              <li key={b.id} className="flex justify-between gap-2">
                <span className="font-medium">{b.name}</span>
                <span className="text-muted">{b.city ?? ''}</span>
              </li>
            ))}
          </ul>
        </Card>
      </div>

      <Card className="overflow-x-auto p-0">
        <h2 className="px-5 pt-5 font-semibold">{t('admin.hotel.payments')}</h2>
        {hotel.data.payments.length === 0 ? (
          <p className="p-5 text-sm text-muted">{t('admin.hotel.noPayments')}</p>
        ) : (
          <table className="mt-3 w-full min-w-[640px] text-left text-sm">
            <thead className="border-t border-border bg-sunken text-text">
              <tr>
                <th className="px-5 py-3 font-medium">{t('admin.hotel.paidOn')}</th>
                <th className="px-5 py-3 font-medium">{t('admin.hotel.amount')}</th>
                <th className="px-5 py-3 font-medium">{t('admin.hotel.period')}</th>
                <th className="px-5 py-3 font-medium">{t('admin.hotel.method')}</th>
                <th className="px-5 py-3 font-medium">{t('admin.hotel.recordedBy')}</th>
              </tr>
            </thead>
            <tbody>
              {hotel.data.payments.map((p) => (
                <tr key={p.id} className="border-t border-border">
                  <td className="px-5 py-3">{date(p.paid_at)}</td>
                  <td className="px-5 py-3 font-semibold">{money(p.amount, p.currency)}</td>
                  <td className="px-5 py-3">
                    {date(p.period_start)} – {date(p.period_end)}
                  </td>
                  <td className="px-5 py-3">
                    {t(`admin.methods.${p.method}`)}
                    {p.reference && <span className="block text-xs text-muted">{p.reference}</span>}
                  </td>
                  <td className="px-5 py-3">{p.recorder?.name ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>
    </div>
  )
}

function ResetPassword({ hotelId, user }: { hotelId: number; user: { id: number; name: string; email: string } }) {
  const { t } = useTranslation()
  const reset = useResetUserPassword(hotelId)
  const [open, setOpen] = useState(false)
  const [sendEmail, setSendEmail] = useState(true)
  const [copied, setCopied] = useState(false)

  if (!open) {
    return (
      <Button variant="secondary" className="self-start" onClick={() => setOpen(true)}>
        <KeyIcon aria-hidden className="size-5 text-icon" />
        {t('admin.hotel.resetPassword')}
      </Button>
    )
  }

  if (reset.data) {
    const copy = () => {
      void navigator.clipboard?.writeText(reset.data.temporary_password).then(() => setCopied(true))
    }
    return (
      <div className="flex flex-col gap-2 rounded-xl bg-sunken p-3">
        <span className="text-xs text-muted">{t('admin.hotel.newPasswordReady')}</span>
        <div className="flex items-center justify-between gap-2">
          <span className="font-mono text-base font-semibold break-all">{reset.data.temporary_password}</span>
          <Button variant="secondary" onClick={copy}>
            {copied ? <CheckIcon aria-hidden className="size-5" /> : <ClipboardDocumentIcon aria-hidden className="size-5 text-icon" />}
            {copied ? t('admin.hotel.copied') : t('admin.hotel.copy')}
          </Button>
        </div>
        <span className="text-xs text-muted">
          {reset.data.email_sent ? t('admin.hotel.resetEmailSent', { email: user.email }) : t('admin.hotel.resetEmailNotSent')}
        </span>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-3 rounded-xl bg-sunken p-3">
      <p className="text-sm font-semibold">{t('admin.hotel.resetTitle', { name: user.name })}</p>
      <p className="text-xs text-muted">{t('admin.hotel.resetHelp', { name: user.name })}</p>
      <label className="flex min-h-touch items-center gap-3 text-sm">
        <input type="checkbox" className="size-5 accent-accent" checked={sendEmail} onChange={(e) => setSendEmail(e.target.checked)} />
        {t('admin.hotel.emailIt', { email: user.email })}
      </label>
      {reset.isError && <Alert>{t('common.serverUnreachable')}</Alert>}
      <div className="flex justify-end gap-2">
        <Button variant="ghost" onClick={() => setOpen(false)}>
          {t('common.cancel')}
        </Button>
        <Button disabled={reset.isPending} onClick={() => reset.mutate({ userId: user.id, send_email: sendEmail })}>
          {t('admin.hotel.createPassword')}
        </Button>
      </div>
    </div>
  )
}

function ActivateButton({ hotelId }: { hotelId: number }) {
  const { t } = useTranslation()
  const activate = useActivateHotel(hotelId)
  return (
    <Button variant="secondary" disabled={activate.isPending} onClick={() => activate.mutate()}>
      {t('admin.hotel.activate')}
    </Button>
  )
}

function PaymentPanel({ hotel, onDone }: { hotel: Hotel; onDone: () => void }) {
  const { t } = useTranslation()
  const plans = usePlans()
  const record = useRecordPayment(hotel.id)
  const [planId, setPlanId] = useState(String(hotel.plan?.id ?? ''))
  const [cycle, setCycle] = useState<'monthly' | 'yearly'>(hotel.billing_cycle)
  const [periods, setPeriods] = useState('1')
  const [amount, setAmount] = useState('')
  const [currency, setCurrency] = useState('ETB')
  const [method, setMethod] = useState('bank_transfer')
  const [reference, setReference] = useState('')
  const error = record.error instanceof ApiError ? record.error : null

  // Suggest the amount whenever plan, cycle or length changes.
  useEffect(() => {
    if (!planId || !periods) return
    let cancelled = false
    getQuote(hotel.id, Number(planId), cycle, Number(periods))
      .then((q) => {
        if (!cancelled) {
          setAmount(q.amount)
          setCurrency(q.currency)
        }
      })
      .catch(() => {})
    return () => {
      cancelled = true
    }
  }, [hotel.id, planId, cycle, periods])

  function submit(e: FormEvent) {
    e.preventDefault()
    record.mutate(
      { plan_id: Number(planId), amount, currency, method, reference: reference || undefined, billing_cycle: cycle, periods: Number(periods) },
      { onSuccess: onDone },
    )
  }

  return (
    <Card>
      <form onSubmit={submit} noValidate className="grid gap-4 sm:grid-cols-3">
        <h2 className="font-semibold sm:col-span-3">{t('admin.hotel.recordPayment')}</h2>
        <Select label={t('admin.hotels.plan')} value={planId} onChange={(e) => setPlanId(e.target.value)} error={error?.field('plan_id')}>
          {plans.data?.data.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </Select>
        <Select label={t('admin.newHotel.billing')} value={cycle} onChange={(e) => setCycle(e.target.value as 'monthly' | 'yearly')}>
          <option value="monthly">{t('admin.billing.monthly')}</option>
          <option value="yearly">{t('admin.billing.yearly')}</option>
        </Select>
        <Field
          label={cycle === 'yearly' ? t('admin.hotel.years') : t('admin.hotel.months')}
          type="number"
          inputMode="numeric"
          min={1}
          max={24}
          value={periods}
          onChange={(e) => setPeriods(e.target.value)}
          error={error?.field('periods')}
        />
        <Field
          label={t('admin.hotel.amountIn', { currency })}
          inputMode="decimal"
          value={amount}
          hint={t('admin.hotel.amountHint')}
          onChange={(e) => setAmount(e.target.value)}
          error={error?.field('amount')}
        />
        <Select label={t('admin.hotel.method')} value={method} onChange={(e) => setMethod(e.target.value)}>
          {['bank_transfer', 'mobile_money', 'cash', 'other'].map((m) => (
            <option key={m} value={m}>
              {t(`admin.methods.${m}`)}
            </option>
          ))}
        </Select>
        <Field label={t('admin.hotel.reference')} value={reference} onChange={(e) => setReference(e.target.value)} error={error?.field('reference')} />
        <div className="flex justify-end gap-2 sm:col-span-3">
          <Button variant="ghost" onClick={onDone}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={record.isPending || !amount || !planId}>
            {t('admin.hotel.savePayment')}
          </Button>
        </div>
      </form>
    </Card>
  )
}

function SuspendPanel({ hotelId, onDone }: { hotelId: number; onDone: () => void }) {
  const { t } = useTranslation()
  const suspend = useSuspendHotel(hotelId)
  const [reason, setReason] = useState('')
  return (
    <Card>
      <form
        noValidate
        className="flex flex-col gap-4"
        onSubmit={(e) => {
          e.preventDefault()
          suspend.mutate(reason, { onSuccess: onDone })
        }}
      >
        <h2 className="font-semibold">{t('admin.hotel.suspend')}</h2>
        <p className="text-sm text-muted">{t('admin.hotel.suspendHelp')}</p>
        <Field label={t('admin.hotel.reason')} value={reason} onChange={(e) => setReason(e.target.value)} required />
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onDone}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" variant="danger" disabled={suspend.isPending || !reason}>
            {t('admin.hotel.confirmSuspend')}
          </Button>
        </div>
      </form>
    </Card>
  )
}

function TrialPanel({ hotel, onDone }: { hotel: Hotel; onDone: () => void }) {
  const { t } = useTranslation()
  const setTrial = useSetTrial(hotel.id)
  const [ends, setEnds] = useState(hotel.trial_ends_at ?? '')
  const [reason, setReason] = useState('')
  return (
    <Card>
      <form
        noValidate
        className="grid gap-4 sm:grid-cols-2"
        onSubmit={(e) => {
          e.preventDefault()
          setTrial.mutate({ trial_ends_at: ends, reason }, { onSuccess: onDone })
        }}
      >
        <h2 className="font-semibold sm:col-span-2">{t('admin.hotel.changeTrial')}</h2>
        <Field label={t('admin.hotel.trialEnds')} type="date" value={ends} onChange={(e) => setEnds(e.target.value)} />
        <Field label={t('admin.hotel.reason')} value={reason} onChange={(e) => setReason(e.target.value)} />
        <div className="flex justify-end gap-2 sm:col-span-2">
          <Button variant="ghost" onClick={onDone}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={setTrial.isPending || !ends || !reason}>
            {t('common.save')}
          </Button>
        </div>
      </form>
    </Card>
  )
}
