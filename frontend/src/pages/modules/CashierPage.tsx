import { useState } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { ApiError } from '@/lib/api'
import { subMoney } from '@/lib/decimal'
import { cn } from '@/lib/cn'
import { date, money } from '@/lib/format'
import { localDate, localTime, useLookups } from '@/lib/front'
import { useCan } from '@/lib/hotel'
import { useApproveShift, useCloseShift, useCurrentShift, useOpenShift, useShifts, type CashierSession } from '@/lib/modules'

const err = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : null)
const TONES = { open: 'info', closed: 'warn', approved: 'ok' } as const

/** Cashier shift close (W10): open with starting cash, take cash, count, close, manager approves. */
export function CashierPage() {
  const { t } = useTranslation()
  const can = useCan()
  const current = useCurrentShift()
  const shift = current.data?.data

  return (
    <div className="flex flex-col gap-6">
      <PageHeader title={t('nav.cashier')} subtitle={t('cashier.subtitle')} />
      {can('cashier.own_shift') && current.data && (shift ? <MyShift shift={shift} /> : <OpenShift />)}
      <ShiftList />
    </div>
  )
}

function OpenShift() {
  const { t } = useTranslation()
  const lookups = useLookups()
  const open = useOpenShift()
  const currencies = lookups.data?.currencies.map((c) => c.code) ?? ['ETB']
  const [opening, setOpening] = useState<Record<string, string>>({})

  return (
    <Card className="flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <h2 className="text-sm font-semibold">{t('cashier.openTitle')}</h2>
        <p className="text-[13px] text-muted">{t('cashier.openHelp')}</p>
      </div>
      <div className="grid gap-3 sm:grid-cols-3">
        {currencies.map((c) => (
          <Field key={c} label={t('cashier.startingCash', { currency: c })} inputMode="decimal" value={opening[c] ?? ''} placeholder="0" onChange={(e) => setOpening({ ...opening, [c]: e.target.value.replace(/[^0-9.]/g, '') })} />
        ))}
      </div>
      {open.error && <Alert>{err(open.error)}</Alert>}
      <div>
        <Button size="lg" disabled={open.isPending} onClick={() => open.mutate(Object.fromEntries(currencies.map((c) => [c, opening[c] || '0'])))}>
          {t('cashier.open')}
        </Button>
      </div>
    </Card>
  )
}

function MyShift({ shift }: { shift: CashierSession }) {
  const { t } = useTranslation()
  const close = useCloseShift()
  const [counted, setCounted] = useState<Record<string, string>>({})
  const [notes, setNotes] = useState('')
  const [closing, setClosing] = useState(false)
  const expected = Object.entries(shift.expected ?? {})
  const diff = (c: string, e: string) => (counted[c] !== undefined && counted[c] !== '' ? Number(subMoney(counted[c], e)) : null)
  const anyDiff = expected.some(([c, r]) => (diff(c, r.expected) ?? 0) !== 0)

  return (
    <div className="grid items-start gap-6 lg:grid-cols-[1fr_22rem]">
      <Card className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-sm font-semibold">{t('cashier.myShift')}</h2>
          <span className="text-xs text-muted">{t('cashier.openedAt', { time: `${date(localDate(shift.opened_at))} ${localTime(shift.opened_at)}` })}</span>
        </div>
        <table className="w-full text-left text-[13px]">
          <thead className="text-muted">
            <tr>
              <th className="py-1.5 font-medium">{t('reservations.currency')}</th>
              <th className="py-1.5 text-right font-medium">{t('cashier.opening')}</th>
              <th className="py-1.5 text-right font-medium">{t('cashier.cashIn')}</th>
              <th className="py-1.5 text-right font-medium">{t('cashier.cashOut')}</th>
              <th className="py-1.5 text-right font-medium">{t('cashier.inDrawer')}</th>
            </tr>
          </thead>
          <tbody>
            {expected.map(([c, r]) => (
              <tr key={c} className="border-t border-border">
                <td className="py-1.5 font-medium">{c}</td>
                <td className="py-1.5 text-right tabular-nums">{money(r.opening, c)}</td>
                <td className="py-1.5 text-right tabular-nums">{money(r.cash_in, c)}</td>
                <td className="py-1.5 text-right tabular-nums">{money(r.cash_out, c)}</td>
                <td className="py-1.5 text-right font-semibold tabular-nums">{money(r.expected, c)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <h3 className="pt-2 text-xs font-semibold text-muted">{t('cashier.cashTaken')}</h3>
        {shift.payments?.length === 0 && <p className="text-[13px] text-muted">{t('cashier.noCash')}</p>}
        <ul className="flex flex-col divide-y divide-border text-[13px]">
          {shift.payments?.map((p) => (
            <li key={p.id} className="flex justify-between gap-3 py-1.5">
              <span>
                {localTime(p.paid_at)} · {t(`reservations.paymentTypes.${p.type}`)}
                {p.reservation && (
                  <Link to={`/reservations/${p.reservation.id}`} className="text-accent-text">
                    {' '}
                    · {p.reservation.code}
                  </Link>
                )}
              </span>
              <span className={cn('tabular-nums', p.type === 'refund' && 'text-danger-text')}>
                {p.type === 'refund' ? '− ' : ''}
                {money(p.amount, p.currency)}
              </span>
            </li>
          ))}
        </ul>
      </Card>

      <Card className="flex flex-col gap-3">
        <h2 className="text-sm font-semibold">{t('cashier.closeTitle')}</h2>
        {!closing ? (
          <>
            <p className="text-[13px] text-muted">{t('cashier.closeHelp')}</p>
            <Button variant="secondary" onClick={() => setClosing(true)}>
              {t('cashier.countDrawer')}
            </Button>
          </>
        ) : (
          <>
            {expected.map(([c, r]) => {
              const d = diff(c, r.expected)
              return (
                <div key={c} className="flex flex-col gap-1">
                  <Field label={t('cashier.counted', { currency: c })} inputMode="decimal" value={counted[c] ?? ''} onChange={(e) => setCounted({ ...counted, [c]: e.target.value.replace(/[^0-9.]/g, '') })} />
                  {d !== null && d !== 0 && <span className={cn('text-xs font-medium', d < 0 ? 'text-danger-text' : 'text-warn-text')}>{d < 0 ? t('cashier.short', { amount: money(Math.abs(d).toFixed(2), c) }) : t('cashier.over', { amount: money(d.toFixed(2), c) })}</span>}
                  {d === 0 && <span className="text-xs font-medium text-ok-text">{t('cashier.matches')}</span>}
                </div>
              )
            })}
            {anyDiff && <Field label={t('cashier.why')} value={notes} onChange={(e) => setNotes(e.target.value)} />}
            {close.error && <Alert>{err(close.error)}</Alert>}
            <Button size="lg" disabled={close.isPending || expected.some(([c]) => !counted[c]) || (anyDiff && !notes)} onClick={() => close.mutate({ counted, notes })}>
              {t('cashier.close')}
            </Button>
          </>
        )}
      </Card>
    </div>
  )
}

function ShiftList() {
  const { t } = useTranslation()
  const can = useCan()
  const [short, setShort] = useState(false)
  const list = useShifts({ short })
  const approve = useApproveShift()

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-sm font-semibold">{can('cashier.view_all') ? t('cashier.allShifts') : t('cashier.pastShifts')}</h2>
        <label className="flex items-center gap-2 text-[13px]">
          <input type="checkbox" className="size-4 accent-accent" checked={short} onChange={(e) => setShort(e.target.checked)} />
          {t('cashier.onlyShort')}
        </label>
      </div>
      {approve.error && <Alert>{err(approve.error)}</Alert>}
      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[720px] text-left text-[13px]">
          <thead className="bg-sunken">
            <tr>
              <th className="px-4 py-2.5 font-semibold">{t('cashier.person')}</th>
              <th className="px-4 py-2.5 font-semibold">{t('cashier.when')}</th>
              <th className="px-4 py-2.5 font-semibold">{t('reservations.status')}</th>
              <th className="px-4 py-2.5 text-right font-semibold">{t('cashier.difference')}</th>
              <th className="px-4 py-2.5" />
            </tr>
          </thead>
          <tbody>
            {list.data?.data.map((s) => (
              <tr key={s.id} className="border-t border-border align-top">
                <td className="px-4 py-2 font-medium">
                  {s.user?.name}
                  <span className="block text-xs font-normal text-muted">{s.branch?.name}</span>
                </td>
                <td className="px-4 py-2 text-muted">
                  {date(localDate(s.opened_at))} {localTime(s.opened_at)}
                  {s.closed_at && ` – ${localTime(s.closed_at)}`}
                  {s.notes && <span className="block text-xs">“{s.notes}”</span>}
                </td>
                <td className="px-4 py-2">
                  <Badge tone={TONES[s.status]}>{t(`cashier.statuses.${s.status}`)}</Badge>
                  {s.approved_by && <span className="block text-xs text-muted">{s.approved_by}</span>}
                </td>
                <td className="px-4 py-2 text-right tabular-nums">
                  {s.balances
                    .filter((b) => b.difference !== null)
                    .map((b) => (
                      <span key={b.currency} className={cn('block', Number(b.difference) < 0 ? 'text-danger-text' : Number(b.difference) > 0 ? 'text-warn-text' : 'text-muted')}>
                        {money(b.difference ?? '0', b.currency)}
                      </span>
                    ))}
                </td>
                <td className="px-4 py-2 text-right">
                  {s.status === 'closed' && can('cashier.approve') && (
                    <Button variant="secondary" disabled={approve.isPending} onClick={() => approve.mutate(s.id)}>
                      {t('cashier.approve')}
                    </Button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {list.data?.data.length === 0 && <p className="p-6 text-center text-muted">{t('cashier.noShifts')}</p>}
      </Card>
    </div>
  )
}
