import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { ProofField, ProofLink, type Proof } from '@/components/payments/PaymentProof'
import { PrinterIcon, Trash2Icon } from 'lucide-react'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button, buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { LoadFailed } from '@/components/ui/LoadFailed'
import { Select } from '@/components/ui/Select'
import { ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { INVOICE_TONES, useAddCharge, useInvoice, useInvoiceBuyer, useInvoicePayment, useRemoveItem, useSplitInvoice, useVoidInvoice, type InvoiceDetail } from '@/lib/desk'
import { isPositive } from '@/lib/decimal'
import { date, money } from '@/lib/format'
import { localDate, localTime, useLookups } from '@/lib/front'
import { useCan } from '@/lib/hotel'
import { Line } from '@/pages/reservations/ReservationFormPage'
import { ChargePanel } from '@/components/billing/ChargePanel'

const err = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : null)

/** One guest bill: lines, taxes, payments, extras, discount, split, void, print (6.2–6.7). */
export function InvoicePage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const can = useCan()
  const q = useInvoice(Number(id))
  const remove = useRemoveItem(Number(id))
  const split = useSplitInvoice(Number(id))
  const [panel, setPanel] = useState<'charge' | 'discount' | 'pay' | 'void' | null>(null)
  if (q.isError) return <LoadFailed error={q.error} onRetry={() => q.refetch()} back={{ to: '/billing', label: t('loadFailed.toBilling') }} />
  if (!q.data) return null
  const inv = q.data.data
  const editable = inv.status !== 'void'
  // The guest has left: only a manager adds or removes lines (owner, 2026-11-08). Payments stay possible.
  const linesEditable = editable && (!['checked_out', 'cancelled', 'no_show'].includes(inv.stay?.status ?? '') || can('invoices.void'))
  const c = inv.currency

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={inv.number}
        subtitle={
          <span className="flex flex-wrap items-center gap-2">
            <Badge tone={INVOICE_TONES[inv.status]}>{t(`billing.statuses.${inv.status}`)}</Badge>
            {inv.reservation && (
              <Link to={`/reservations/${inv.reservation.id}`} className="text-accent-text">
                {inv.reservation.code}
              </Link>
            )}
            <span>{inv.guest.full_name}</span>
          </span>
        }
        breadcrumbs={[{ label: t('nav.billing'), to: '/billing' }, { label: inv.number }]}
        actions={
          <div className="flex flex-wrap gap-2">
            {can('invoices.print') && (
              <Link to={`/billing/${inv.id}/print`} className={buttonClass('secondary')} target="_blank">
                <PrinterIcon aria-hidden className="size-4" />
                {t('billing.print')}
              </Link>
            )}
            {editable && can('invoices.split') && (inv.stay?.rooms.length ?? 0) > 1 && !inv.reservation_room_id && (
              <Button variant="secondary" disabled={split.isPending} onClick={() => split.mutate(undefined)}>
                {t('billing.split')}
              </Button>
            )}
            {editable && can('invoices.void') && (
              <Button variant="danger" onClick={() => setPanel('void')}>
                {t('billing.void')}
              </Button>
            )}
          </div>
        }
      />
      {inv.status === 'void' && <Alert>{t('billing.voided', { reason: inv.void_reason ?? '' })}</Alert>}
      {split.error && <Alert>{err(split.error)}</Alert>}
      {split.isSuccess && <Alert tone="ok">{t('billing.splitDone', { count: split.data.data.length })}</Alert>}
      {panel === 'void' && <VoidPanel inv={inv} onClose={() => setPanel(null)} />}

      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div className="flex flex-col gap-6">
          <Card className="overflow-x-auto p-0">
            <table className="w-full min-w-[560px] text-left text-sm">
              <thead>
                <tr>
                  <th className="px-4 h-10 font-medium">{t('billing.date')}</th>
                  <th className="px-4 h-10 font-medium">{t('billing.description')}</th>
                  <th className="px-4 h-10 text-right font-medium">{t('billing.qty')}</th>
                  <th className="px-4 h-10 text-right font-medium">{t('billing.amount')}</th>
                  <th className="w-12 px-2 h-10" />
                </tr>
              </thead>
              <tbody>
                {inv.items.map((it) => (
                  <tr key={it.id} className="border-t border-border">
                    <td className="px-4 py-2 text-muted">{date(it.service_date)}</td>
                    <td className="px-4 py-2">
                      {it.description}
                      {it.is_waived && <Badge tone="grey">{t('billing.waived')}</Badge>}
                    </td>
                    <td className="px-4 py-2 text-right tabular-nums">{Number(it.quantity)}</td>
                    <td className={cn('px-4 py-2 text-right tabular-nums', Number(it.amount) < 0 && 'text-ok-text')}>{money(it.amount, c)}</td>
                    <td className="px-2 py-1 text-right">
                      {linesEditable && it.type !== 'room' && can(it.type === 'discount' ? 'invoices.discount' : 'invoices.add_charge') && (
                        <button
                          type="button"
                          aria-label={t('common.delete')}
                          disabled={remove.isPending}
                          onClick={() => remove.mutate(it.id)}
                          className="flex size-touch items-center justify-center rounded-md text-muted hover:bg-danger-bg hover:text-danger-text"
                        >
                          <Trash2Icon aria-hidden className="size-4" />
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>
          {remove.error && <Alert>{err(remove.error)}</Alert>}
          {editable && (
            <div className="flex flex-wrap gap-2">
              {linesEditable && can('invoices.add_charge') && (
                <Button variant="secondary" onClick={() => setPanel('charge')}>
                  {t('billing.addCharge')}
                </Button>
              )}
              {linesEditable && can('invoices.discount') && (
                <Button variant="secondary" onClick={() => setPanel('discount')}>
                  {t('billing.addDiscount')}
                </Button>
              )}
              {(can('payments.take') || can('payments.refund')) && (
                <Button onClick={() => setPanel('pay')}>{t('reservations.recordPayment')}</Button>
              )}
            </div>
          )}
          {panel === 'charge' && <ChargePanel invoiceId={inv.id} onClose={() => setPanel(null)} />}
          {panel === 'discount' && <DiscountPanel inv={inv} onClose={() => setPanel(null)} />}
          {panel === 'pay' && <PayPanel inv={inv} onClose={() => setPanel(null)} />}

          <Card className="flex flex-col gap-2">
            <h2 className="text-sm font-semibold">{t('reservations.payments')}</h2>
            {inv.payments.length === 0 && <p className="text-sm text-muted">{t('reservations.noPayments')}</p>}
            <ul className="flex flex-col divide-y divide-border text-sm">
              {inv.payments.map((p) => (
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
                    <ProofLink paymentId={p.id} proof={p.proof} canAdd={can('payments.take')} />
                  </span>
                  <span className={cn('tabular-nums', p.type === 'refund' && 'text-danger-text')}>
                    {p.type === 'refund' ? '− ' : ''}
                    {money(p.amount, p.currency)}
                  </span>
                </li>
              ))}
            </ul>
          </Card>
        </div>

        <div className="flex flex-col gap-6 lg:sticky lg:top-20">
        <Card className="flex flex-col gap-2 text-sm">
          <h2 className="text-sm font-semibold">{t('reservations.money')}</h2>
          <Line label={t('billing.charges')} value={money(inv.subtotal, c)} />
          {Number(inv.discount_total) > 0 && <Line label={t('billing.discounts')} value={`− ${money(inv.discount_total, c)}`} />}
          {inv.taxes.map((x) => (
            <Line key={`${x.name}${x.rate}${x.is_inclusive}`} label={x.is_inclusive ? t('billing.taxIncluded', { name: x.name, rate: Number(x.rate) }) : `${x.name} ${Number(x.rate)}%`} value={money(x.amount, c)} />
          ))}
          <Line label={t('reservations.total')} value={money(inv.total, c)} strong />
          <Line label={t('reservations.paid')} value={money(inv.paid_total, c)} />
          <Line label={t('reservations.balance')} value={money(inv.balance, c)} strong />
          {inv.outstanding_approved_at && <p className="text-xs text-danger-text">{t('billing.outstandingSince', { date: date(localDate(inv.outstanding_approved_at)) })}</p>}
        </Card>
        <BuyerBox inv={inv} canEdit={inv.status !== 'void' && can('invoices.print')} />
        </div>
      </div>
    </div>
  )
}

function DiscountPanel({ inv, onClose }: { inv: InvoiceDetail; onClose: () => void }) {
  const { t } = useTranslation()
  const add = useAddCharge(inv.id)
  const [percent, setPercent] = useState('')
  const [why, setWhy] = useState('')
  return (
    <Card className="grid gap-3 animate-page-in sm:grid-cols-3">
      <Field label={t('reservations.form.discount')} inputMode="decimal" value={percent} onChange={(e) => setPercent(e.target.value.replace(/[^0-9.]/g, ''))} />
      <div className="sm:col-span-2">
        <Field label={t('billing.discountReason')} value={why} onChange={(e) => setWhy(e.target.value)} />
      </div>
      {add.error && <div className="sm:col-span-3"><Alert>{err(add.error)}</Alert></div>}
      <div className="flex justify-end gap-2 sm:col-span-3">
        <Button variant="ghost" onClick={onClose}>
          {t('common.cancel')}
        </Button>
        <Button disabled={!percent || add.isPending} onClick={() => add.mutate({ type: 'discount', discount_percent: percent, description: why || undefined }, { onSuccess: onClose })}>
          {t('billing.addDiscount')}
        </Button>
      </div>
    </Card>
  )
}

/** Also used by the dashboard side sheet (P18): same rules, same "saved once" key. */
export function PayPanel({ inv, onClose }: { inv: InvoiceDetail; onClose: () => void }) {
  const { t } = useTranslation()
  const can = useCan()
  const lookups = useLookups()
  const pay = useInvoicePayment(inv.id)
  const types = (['payment', 'refund'] as const).filter((x) => can(x === 'refund' ? 'payments.refund' : 'payments.take'))
  const [type, setType] = useState(types[0] ?? 'payment')
  const [methodId, setMethodId] = useState(0)
  const [amount, setAmount] = useState(isPositive(inv.balance) ? inv.balance : '')
  const [currency, setCurrency] = useState(inv.currency)
  const [reference, setReference] = useState('')
  const [proof, setProof] = useState<Proof | null>(null)
  const [reason, setReason] = useState('')
  const method = methodId || lookups.data?.payment_methods[0]?.id || 0

  return (
    <Card className="grid gap-3 animate-page-in sm:grid-cols-3">
      <Select label={t('reservations.paymentType')} value={type} onChange={(e) => setType(e.target.value as 'payment' | 'refund')}>
        {types.map((x) => (
          <option key={x} value={x}>
            {t(`reservations.paymentTypes.${x}`)}
          </option>
        ))}
      </Select>
      <Select label={t('reservations.method')} value={String(method)} onChange={(e) => setMethodId(Number(e.target.value))}>
        {lookups.data?.payment_methods.map((m) => (
          <option key={m.id} value={m.id}>
            {m.name}
          </option>
        ))}
      </Select>
      <div className="flex gap-2">
        <div className="flex-1">
          <Field label={t('reservations.amount')} inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value.replace(/[^0-9.]/g, ''))} />
        </div>
        {(lookups.data?.currencies.length ?? 0) > 1 && (
          <div className="w-24">
            <Select label={t('reservations.currency')} value={currency} onChange={(e) => setCurrency(e.target.value)}>
              {lookups.data?.currencies.map((x) => (
                <option key={x.code} value={x.code}>
                  {x.code}
                </option>
              ))}
            </Select>
          </div>
        )}
      </div>
      <Field label={t('reservations.reference')} value={reference} onChange={(e) => setReference(e.target.value)} />
      <ProofField value={proof} onChange={setProof} />
      {type === 'refund' && <Field label={t('reservations.refundReason')} value={reason} onChange={(e) => setReason(e.target.value)} />}
      {pay.error && <div className="sm:col-span-3"><Alert>{err(pay.error)}</Alert></div>}
      <div className="flex justify-end gap-2 sm:col-span-3">
        <Button variant="ghost" onClick={onClose}>
          {t('common.cancel')}
        </Button>
        <Button disabled={!amount || pay.isPending} onClick={() => pay.mutate({ type, payment_method_id: method, amount, currency, reference: reference || undefined, refund_reason: reason || undefined, proof_id: proof?.id }, { onSuccess: onClose })}>
          {t('reservations.savePayment')}
        </Button>
      </div>
    </Card>
  )
}

function VoidPanel({ inv, onClose }: { inv: InvoiceDetail; onClose: () => void }) {
  const { t } = useTranslation()
  const voidIt = useVoidInvoice(inv.id)
  const [reason, setReason] = useState('')
  return (
    <Card className="flex flex-col gap-3 border-danger/40 animate-page-in">
      <h2 className="text-sm font-semibold">{t('billing.voidTitle')}</h2>
      <p className="text-sm text-muted">{t('billing.voidHelp')}</p>
      <Field label={t('reservations.reason')} value={reason} onChange={(e) => setReason(e.target.value)} />
      {voidIt.error && <Alert>{err(voidIt.error)}</Alert>}
      <div className="flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose}>
          {t('common.back')}
        </Button>
        <Button variant="danger" disabled={!reason || voidIt.isPending} onClick={() => voidIt.mutate(reason, { onSuccess: onClose })}>
          {t('billing.void')}
        </Button>
      </div>
    </Card>
  )
}

/** A company that pays the bill: its name and TIN print on the invoice (optional). */
function BuyerBox({ inv, canEdit }: { inv: InvoiceDetail; canEdit: boolean }) {
  const { t } = useTranslation()
  const save = useInvoiceBuyer(inv.id)
  const [editing, setEditing] = useState(false)
  const [company, setCompany] = useState(inv.buyer?.company ?? '')
  const [tin, setTin] = useState(inv.buyer?.tin ?? '')
  const has = !!inv.buyer?.company
  if (!has && !canEdit) return null

  return (
    <Card className="flex flex-col gap-2 text-sm">
      <h2 className="text-sm font-semibold">{t('billing.buyer.title')}</h2>
      {editing ? (
        <form
          className="flex flex-col gap-3"
          onSubmit={(e) => {
            e.preventDefault()
            save.mutate({ company: company.trim() || null, tin: tin.trim() || null }, { onSuccess: () => setEditing(false) })
          }}
        >
          <Field label={t('billing.buyer.company')} value={company} onChange={(e) => setCompany(e.target.value)} autoFocus />
          <Field label={t('billing.buyer.tin')} inputMode="numeric" value={tin} onChange={(e) => setTin(e.target.value.replace(/\D/g, ''))} hint={t('billing.buyer.tinHint')} />
          {save.error && <Alert>{err(save.error)}</Alert>}
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setEditing(false)}>
              {t('common.cancel')}
            </Button>
            <Button type="submit" disabled={save.isPending || (!company.trim() && !!tin.trim())}>
              {t('common.save')}
            </Button>
          </div>
        </form>
      ) : (
        <>
          {has ? (
            <p className="flex flex-col">
              <span className="font-medium">{inv.buyer?.company}</span>
              {inv.buyer?.tin && <span className="text-muted">TIN: {inv.buyer.tin}</span>}
            </p>
          ) : (
            <p className="text-muted">{t('billing.buyer.none')}</p>
          )}
          {canEdit && (
            <Button variant="secondary" className="self-start" onClick={() => setEditing(true)}>
              {has ? t('billing.buyer.change') : t('billing.buyer.add')}
            </Button>
          )}
        </>
      )}
    </Card>
  )
}
