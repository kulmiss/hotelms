import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { DocumentTextIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline'
import { Badge } from '@/components/ui/Badge'
import { Card } from '@/components/ui/Card'
import { PageHeader } from '@/components/ui/PageHeader'
import { Tabs } from '@/components/ui/Tabs'
import { cn } from '@/lib/cn'
import { INVOICE_TONES, useInvoices, usePayments } from '@/lib/desk'
import { date, money } from '@/lib/format'
import { localDate, localTime } from '@/lib/front'
import { useCan } from '@/lib/hotel'
import { useDebounced } from '@/lib/useDebounced'
import { Pager } from '@/pages/guests/GuestsPage'

/** Invoices and payments (screen-list.md 6.1, 6.6). */
export function BillingPage() {
  const { t } = useTranslation()
  const can = useCan()
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') === 'payments' && can('payments.view') ? 'payments' : 'invoices'

  return (
    <div className="flex flex-col gap-6">
      <PageHeader title={t('nav.billing')} subtitle={t('billing.subtitle')} />
      {can('payments.view') && (
        <div className="max-w-xs">
          <Tabs
            label={t('nav.billing')}
            active={tab}
            onChange={(k) => setParams(k === 'payments' ? { tab: 'payments' } : {})}
            items={[
              { key: 'invoices', label: t('billing.invoices') },
              { key: 'payments', label: t('billing.payments') },
            ]}
          />
        </div>
      )}
      {tab === 'invoices' ? <InvoiceList /> : <PaymentList />}
    </div>
  )
}

const FILTERS = [
  { key: 'all' },
  { key: 'unpaid', unpaid: true },
  { key: 'outstanding', status: 'outstanding' },
  { key: 'paid', status: 'paid' },
  { key: 'void', status: 'void' },
] as const

function InvoiceList() {
  const { t } = useTranslation()
  const [q, setQ] = useState('')
  const [filter, setFilter] = useState<(typeof FILTERS)[number]['key']>('all')
  const [page, setPage] = useState(1)
  const search = useDebounced(q, 250)
  const f = FILTERS.find((x) => x.key === filter) ?? FILTERS[0]
  const list = useInvoices({ q: search, status: 'status' in f ? f.status : undefined, unpaid: 'unpaid' in f ? f.unpaid : undefined, page })

  return (
    <div className="flex flex-col gap-4">
      <div className="relative w-full max-w-xs">
        <MagnifyingGlassIcon aria-hidden className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-icon" />
        <input
          type="search"
          aria-label={t('billing.search')}
          placeholder={t('billing.search')}
          value={q}
          onChange={(e) => {
            setQ(e.target.value)
            setPage(1)
          }}
          className="min-h-touch w-full rounded-lg bg-surface pr-3 pl-9 text-[13px] shadow-sm ring-1 ring-ring outline-none focus:ring-2 focus:ring-accent"
        />
      </div>
      <div className="flex flex-wrap gap-1.5">
        {FILTERS.map((x) => (
          <button
            key={x.key}
            type="button"
            aria-pressed={filter === x.key}
            onClick={() => {
              setFilter(x.key)
              setPage(1)
            }}
            className={cn('min-h-touch rounded-full px-3 text-[13px] font-medium ring-1 transition duration-75', filter === x.key ? 'bg-accent text-on-accent ring-accent' : 'bg-surface text-muted ring-ring hover:bg-sunken')}
          >
            {t(`billing.filters.${x.key}`)}
          </button>
        ))}
      </div>
      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[760px] text-left text-[13px]">
          <thead className="bg-sunken">
            <tr>
              <th className="px-4 py-2.5 font-semibold">{t('billing.number')}</th>
              <th className="px-4 py-2.5 font-semibold">{t('reservations.guest')}</th>
              <th className="px-4 py-2.5 font-semibold">{t('billing.date')}</th>
              <th className="px-4 py-2.5 font-semibold">{t('reservations.status')}</th>
              <th className="px-4 py-2.5 text-right font-semibold">{t('reservations.total')}</th>
              <th className="px-4 py-2.5 text-right font-semibold">{t('reservations.balance')}</th>
            </tr>
          </thead>
          <tbody>
            {list.data?.data.map((i) => (
              <tr key={i.id} className="border-t border-border transition duration-75 hover:bg-sunken">
                <td className="px-4 py-1.5">
                  <Link to={`/billing/${i.id}`} className="flex min-h-touch flex-col justify-center">
                    <span className="font-medium text-accent-text">{i.number}</span>
                    <span className="text-xs text-muted">{i.reservation?.code}</span>
                  </Link>
                </td>
                <td className="px-4 py-1.5">{i.guest?.name}</td>
                <td className="px-4 py-1.5 text-muted">{date(localDate(i.issued_at))}</td>
                <td className="px-4 py-1.5">
                  <Badge tone={INVOICE_TONES[i.status]}>{t(`billing.statuses.${i.status}`)}</Badge>
                </td>
                <td className="px-4 py-1.5 text-right tabular-nums">{money(i.total, i.currency)}</td>
                <td className={cn('px-4 py-1.5 text-right tabular-nums', Number(i.balance) > 0 && i.status !== 'void' && 'font-semibold text-danger-text')}>{money(i.balance, i.currency)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {list.data?.data.length === 0 && (
          <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
            <span className="flex size-12 items-center justify-center rounded-full bg-sunken">
              <DocumentTextIcon aria-hidden className="size-6 text-icon" />
            </span>
            <p className="text-muted">{t('billing.empty')}</p>
          </div>
        )}
      </Card>
      {list.data && list.data.last_page > 1 && <Pager page={page} last={list.data.last_page} onChange={setPage} />}
    </div>
  )
}

function PaymentList() {
  const { t } = useTranslation()
  const [page, setPage] = useState(1)
  const list = usePayments({ page })
  return (
    <div className="flex flex-col gap-4">
      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[760px] text-left text-[13px]">
          <thead className="bg-sunken">
            <tr>
              <th className="px-4 py-2.5 font-semibold">{t('billing.date')}</th>
              <th className="px-4 py-2.5 font-semibold">{t('reservations.paymentType')}</th>
              <th className="px-4 py-2.5 font-semibold">{t('reservations.method')}</th>
              <th className="px-4 py-2.5 font-semibold">{t('reservations.guest')}</th>
              <th className="px-4 py-2.5 font-semibold">{t('billing.takenBy')}</th>
              <th className="px-4 py-2.5 text-right font-semibold">{t('reservations.amount')}</th>
            </tr>
          </thead>
          <tbody>
            {list.data?.data.map((p) => (
              <tr key={p.id} className="border-t border-border">
                <td className="px-4 py-2 text-muted">
                  {date(localDate(p.paid_at))} {localTime(p.paid_at)}
                </td>
                <td className="px-4 py-2">{t(`reservations.paymentTypes.${p.type}`)}</td>
                <td className="px-4 py-2">
                  {p.method}
                  {p.reference && <span className="text-xs text-muted"> · {p.reference}</span>}
                </td>
                <td className="px-4 py-2">
                  {p.reservation ? (
                    <Link to={`/reservations/${p.reservation.id}`} className="text-accent-text">
                      {p.reservation.guest} · {p.reservation.code}
                    </Link>
                  ) : (
                    '—'
                  )}
                </td>
                <td className="px-4 py-2 text-muted">{p.by}</td>
                <td className={cn('px-4 py-2 text-right tabular-nums', p.type === 'refund' && 'text-danger-text')}>
                  {p.type === 'refund' ? '− ' : ''}
                  {money(p.amount, p.currency)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {list.data?.data.length === 0 && <p className="p-6 text-center text-muted">{t('billing.noPayments')}</p>}
      </Card>
      {list.data && list.data.last_page > 1 && <Pager page={page} last={list.data.last_page} onChange={setPage} />}
    </div>
  )
}
