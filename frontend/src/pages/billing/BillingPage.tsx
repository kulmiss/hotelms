import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { FileTextIcon, SearchIcon } from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import { PageHeader } from '@/components/ui/PageHeader'
import { Tabs } from '@/components/ui/Tabs'
import { cn } from '@/lib/cn'
import { INVOICE_TONES, useInvoices, usePayments, type InvoiceSummary, type PaymentRow } from '@/lib/desk'
import { DateRangeFilter } from '@/components/ui/DateRangeFilter'
import { rangeChip, type DateRange } from '@/lib/dateRange'
import { ToolbarSearch, type ActiveFilter } from '@/components/ui/CollapsibleToolbar'
import { date, money } from '@/lib/format'
import { listFileUrl, localDate, localTime } from '@/lib/front'
import { useCan } from '@/lib/hotel'
import { useDebounced } from '@/lib/useDebounced'
import { DataTable, type SortState } from '@/components/ui/DataTable'
import { inputClass } from '@/components/ui/formStyles'

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
  const [range, setRange] = useState<DateRange>({})
  const search = useDebounced(q, 250)
  const f = FILTERS.find((x) => x.key === filter) ?? FILTERS[0]
  const [perPage, setPerPage] = useState(25)
  const [sort, setSort] = useState<SortState>({ by: 'issued', dir: 'desc' })
  const can = useCan()
  const filters = { q: search, status: 'status' in f ? f.status : undefined, unpaid: 'unpaid' in f ? f.unpaid : undefined, ...range, sort: sort.by, dir: sort.dir }
  const list = useInvoices({ ...filters, page, per_page: perPage })

  const active: ActiveFilter[] = [
    ...rangeChip(t, 'dates', t('dates.label'), range, () => (setRange({}), setPage(1))),
    ...(filter !== 'all' ? [{ key: 'status', label: t(`billing.filters.${filter}`), onRemove: () => (setFilter('all'), setPage(1)) }] : []),
  ]

  return (
    <DataTable<InvoiceSummary>
      id="bills"
      search={
        <div className="relative w-full lg:max-w-xs">
          <SearchIcon aria-hidden className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-icon" />
          <input
            type="search"
            aria-label={t('billing.search')}
            placeholder={t('billing.search')}
            value={q}
            onChange={(e) => {
              setQ(e.target.value)
              setPage(1)
            }}
            className={cn(inputClass, 'w-full ps-9')}
          />
        </div>
      }
      filters={
        <>
          <div className="flex flex-wrap items-end justify-between gap-3">
            <ToolbarSearch />
            <DateRangeFilter
              value={range}
              onChange={(r) => {
                setRange(r)
                setPage(1)
              }}
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
                className={cn('min-h-touch rounded-full px-3 text-sm font-medium border shadow-xs transition-colors', filter === x.key ? 'border-primary bg-primary text-primary-foreground' : 'bg-background text-muted hover:bg-sunken dark:bg-input/30')}
              >
                {t(`billing.filters.${x.key}`)}
              </button>
            ))}
          </div>
        </>
      }
      activeFilters={active}
      onClearFilters={() => {
        setRange({})
        setFilter('all')
        setPage(1)
      }}
      rows={list.data?.data ?? []}
      rowKey={(i) => i.id}
      loading={list.isLoading}
      error={list.error}
      onRetry={() => list.refetch()}
      sort={sort}
      onSort={(x) => {
        setSort(x)
        setPage(1)
      }}
      paging={{ page, last: list.data?.last_page ?? 1, total: list.data?.total, perPage, onPage: setPage, onPerPage: (n) => (setPerPage(n), setPage(1)) }}
      exportUrl={can('reports.export') ? listFileUrl('/invoices', { ...filters }) : null}
      minWidth="760px"
      empty={
        <div className="flex flex-col items-center gap-3 py-6">
          <span className="flex size-12 items-center justify-center rounded-full bg-sunken">
            <FileTextIcon aria-hidden className="size-6 text-icon" />
          </span>
          <p>{t('billing.empty')}</p>
        </div>
      }
      columns={[
        {
          key: 'number', label: t('billing.number'), sort: 'number', hideable: false,
          render: (i) => (
            <Link to={`/billing/${i.id}`} className="flex min-h-touch flex-col justify-center">
              <span className="font-medium text-accent-text">{i.number}</span>
              <span className="text-xs text-muted">{i.reservation?.code}</span>
            </Link>
          ),
        },
        { key: 'guest', label: t('reservations.guest'), sort: 'guest', render: (i) => i.guest?.name },
        { key: 'date', label: t('billing.date'), sort: 'issued', render: (i) => <span className="text-muted">{date(localDate(i.issued_at))}</span> },
        { key: 'status', label: t('reservations.status'), sort: 'status', render: (i) => <Badge tone={INVOICE_TONES[i.status]}>{t(`billing.statuses.${i.status}`)}</Badge> },
        { key: 'total', label: t('reservations.total'), sort: 'total', align: 'right', render: (i) => money(i.total, i.currency) },
        {
          key: 'balance', label: t('reservations.balance'), sort: 'balance', align: 'right',
          render: (i) => <span className={cn(Number(i.balance) > 0 && i.status !== 'void' && 'font-semibold text-danger-text')}>{money(i.balance, i.currency)}</span>,
        },
      ]}
    />
  )
}

function PaymentList() {
  const { t } = useTranslation()
  const [page, setPage] = useState(1)
  const [range, setRange] = useState<DateRange>({})
  const [perPage, setPerPage] = useState(25)
  const [sort, setSort] = useState<SortState>({ by: 'paid', dir: 'desc' })
  const can = useCan()
  const list = usePayments({ page, ...range, per_page: perPage, sort: sort.by, dir: sort.dir })
  return (
    <DataTable<PaymentRow>
      id="payments"
      filters={
        <DateRangeFilter
          value={range}
          onChange={(r) => {
            setRange(r)
            setPage(1)
          }}
        />
      }
      activeFilters={rangeChip(t, 'dates', t('dates.label'), range, () => (setRange({}), setPage(1)))}
      onClearFilters={() => (setRange({}), setPage(1))}
      rows={list.data?.data ?? []}
      rowKey={(p) => p.id}
      loading={list.isLoading}
      error={list.error}
      onRetry={() => list.refetch()}
      sort={sort}
      onSort={(x) => {
        setSort(x)
        setPage(1)
      }}
      paging={{ page, last: list.data?.last_page ?? 1, total: list.data?.total, perPage, onPage: setPage, onPerPage: (n) => (setPerPage(n), setPage(1)) }}
      exportUrl={can('reports.export') ? listFileUrl('/payments', { ...range, sort: sort.by, dir: sort.dir }) : null}
      minWidth="760px"
      empty={t('billing.noPayments')}
      columns={[
        { key: 'date', label: t('billing.date'), sort: 'paid', hideable: false, render: (p) => <span className="text-muted">{date(localDate(p.paid_at))} {localTime(p.paid_at)}</span> },
        { key: 'type', label: t('reservations.paymentType'), sort: 'type', render: (p) => t(`reservations.paymentTypes.${p.type}`) },
        {
          key: 'method', label: t('reservations.method'),
          render: (p) => (
            <>
              {p.method}
              {p.reference && <span className="text-xs text-muted"> · {p.reference}</span>}
            </>
          ),
        },
        {
          key: 'guest', label: t('reservations.guest'),
          render: (p) =>
            p.reservation ? (
              <Link to={`/reservations/${p.reservation.id}`} className="text-accent-text">
                {p.reservation.guest} · {p.reservation.code}
              </Link>
            ) : (
              '—'
            ),
        },
        { key: 'by', label: t('billing.takenBy'), render: (p) => <span className="text-muted">{p.by}</span> },
        {
          key: 'amount', label: t('reservations.amount'), sort: 'amount', align: 'right',
          render: (p) => (
            <span className={cn(p.type === 'refund' && 'text-danger-text')}>
              {p.type === 'refund' ? '− ' : ''}
              {money(p.amount, p.currency)}
            </span>
          ),
        },
      ]}
    />
  )
}
