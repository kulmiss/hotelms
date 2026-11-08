import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { ApiError } from '@/lib/api'
import { date, money } from '@/lib/format'
import { useHotelInvoices, useIssueInvoice, useSendInvoice, useVoidInvoice, type HotelInvoice } from '@/lib/platform'

const errorOf = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : e ? String(e) : null)
export const INVOICE_TONE = { unpaid: 'warn', paid: 'ok', void: 'grey' } as const

/** Super Admin hotel page: HotelMS invoices to this hotel (made a week ahead and emailed). */
export function HotelInvoices({ hotelId, canBill }: { hotelId: number; canBill: boolean }) {
  const { t } = useTranslation()
  const q = useHotelInvoices(hotelId)
  const issue = useIssueInvoice(hotelId)
  const send = useSendInvoice(hotelId)
  const voidIt = useVoidInvoice(hotelId)
  const rows = q.data?.data ?? []
  const error = issue.error ?? send.error ?? voidIt.error
  return (
    <Card className="flex flex-col gap-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h2 className="font-semibold">{t('hotelInvoices.listTitle')}</h2>
          {q.data && (
            <p className="text-xs text-muted">
              {t('hotelInvoices.next', { from: date(q.data.next_period.start), to: date(q.data.next_period.end) })}
            </p>
          )}
        </div>
        {canBill && (
          <Button variant="secondary" disabled={issue.isPending} onClick={() => issue.mutate()}>
            {t('hotelInvoices.issue')}
          </Button>
        )}
      </div>
      {error && <Alert>{errorOf(error)}</Alert>}
      {rows.length === 0 ? (
        <p className="text-sm text-muted">{t('hotelInvoices.none')}</p>
      ) : (
        <ul className="flex flex-col divide-y divide-border text-sm">
          {rows.map((i: HotelInvoice) => (
            <li key={i.id} className="flex flex-wrap items-center gap-2 py-2">
              <span className="min-w-0 flex-1">
                <span className="block font-medium">
                  {i.number} · {money(i.amount, i.currency)}
                </span>
                <span className="block text-xs text-muted">
                  {date(i.period_start)} – {date(i.period_end)} · {t('hotelInvoices.dueOn', { date: date(i.due_date) })}
                  {i.emailed_at ? ` · ${t('hotelInvoices.emailed')}` : ''}
                </span>
              </span>
              <Badge tone={INVOICE_TONE[i.status]}>{t(`hotelInvoices.status.${i.status}`)}</Badge>
              {canBill && i.status !== 'void' && (
                <Button variant="ghost" disabled={send.isPending} onClick={() => send.mutate(i.id)}>
                  {t('hotelInvoices.send')}
                </Button>
              )}
              {canBill && i.status === 'unpaid' && (
                <Button variant="ghost" disabled={voidIt.isPending} onClick={() => window.confirm(t('hotelInvoices.voidConfirm', { number: i.number })) && voidIt.mutate(i.id)}>
                  {t('hotelInvoices.void')}
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

/** Hotel side (Settings → Subscription): invoices from HotelMS with a link to print. */
export function MyInvoices({ rows }: { rows: HotelInvoice[] }) {
  const { t } = useTranslation()
  if (!rows.length) return null
  return (
    <Card className="flex flex-col gap-2">
      <h3 className="text-sm font-semibold">{t('hotelInvoices.listTitle')}</h3>
      <ul className="flex flex-col divide-y divide-border text-sm">
        {rows.map((i) => (
          <li key={i.id} className="flex flex-wrap items-center gap-2 py-2">
            <Link to={`/subscription-invoices/${i.id}`} className="min-w-0 flex-1 font-medium text-accent-text hover:underline">
              {i.number}
            </Link>
            <span className="text-muted">
              {date(i.period_start)} – {date(i.period_end)}
            </span>
            <span className="tabular-nums">{money(i.amount, i.currency)}</span>
            <Badge tone={INVOICE_TONE[i.status]}>{t(`hotelInvoices.status.${i.status}`)}</Badge>
          </li>
        ))}
      </ul>
    </Card>
  )
}
