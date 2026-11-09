import { useState } from 'react'
import { Link } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { RevenuePaymentsChart } from '@/components/charts/charts'
import { KpiCard } from '@/components/charts/KpiCard'
import { BarList, ChartCard, KpiGrid, SERIES, WidgetBoundary, widgetState } from '@/components/charts/kit'
import { Badge } from '@/components/ui/Badge'
import { api } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { useOutstanding, usePaymentsSplit, usePerformance, useTrend, type DashFilters } from '@/lib/dashboard'
import { addMoney } from '@/lib/decimal'
import type { PaymentRow } from '@/lib/desk'
import type { Page } from '@/lib/front'
import { date, dateTime, money } from '@/lib/format'
import { useCan } from '@/lib/hotel'
import { change } from '@/lib/reports'
import { UnpaidRows } from './DeskLists'
import { PaymentMethods } from './Overview'
import { DaySheet, InvoiceSheet } from './Sheets'

/** The Revenue tab (roles with dashboard.view_revenue): where the money comes from and what is still owed. */
export function RevenueTab({ f }: { f: DashFilters }) {
  const { t } = useTranslation()
  const can = useCan()
  const me = useMe()
  const currency = me.data?.hotel?.default_currency ?? 'ETB'
  const fmt = (v: string | number) => money(String(v), currency)
  const reports = can(['reports.revenue', 'reports.occupancy'])
  const perf = usePerformance(f, reports)
  const trend = useTrend(f)
  const pays = usePaymentsSplit(f, can(['reports.payments', 'reports.revenue']))
  const unpaid = useOutstanding(f, can('reports.outstanding'))
  const recent = useQuery({ queryKey: ['dash', 'recent-payments'], queryFn: () => api<Page<PaymentRow>>('/payments?per_page=8&sort=paid&dir=desc'), enabled: can('payments.view') })
  const [day, setDay] = useState<string | null>(null)
  const [invoice, setInvoice] = useState<number | null>(null)
  const k = perf.data?.data.kpis
  const c = perf.data?.data.compare
  const kpi = (key: 'revenue' | 'room_revenue' | 'extras' | 'payments' | 'tax', label: string) => (
    <KpiCard label={label} loading={perf.isPending} value={k ? fmt(k[key]) : '—'} change={k && c ? change(k[key], c[key]) : null} />
  )
  // The same room type can exist in several branches: one bar per name.
  const types = new Map<string, { nights: number; revenue: string }>()
  for (const r of perf.data?.data.room_types ?? []) {
    const before = types.get(r.name) ?? { nights: 0, revenue: '0' }
    types.set(r.name, { nights: before.nights + r.room_nights, revenue: addMoney(before.revenue, r.room_revenue) })
  }
  const unpaidRows = unpaid.data?.data.rows ?? []

  return (
    <div className="flex flex-col gap-4">
      {reports && (
        <KpiGrid>
          {kpi('revenue', t('dash.kpi.revenue'))}
          {kpi('room_revenue', t('dash.kpi.roomRevenue'))}
          {kpi('extras', t('dash.kpi.extras'))}
          {kpi('payments', t('dash.kpi.moneyTaken'))}
          {kpi('tax', t('dash.kpi.tax'))}
          {unpaid.data && <KpiCard label={t('dash.kpi.outstanding')} value={fmt(addMoney(...unpaidRows.map((r) => r.balance)))} sub={t('dash.bills', { count: unpaidRows.length })} />}
        </KpiGrid>
      )}

      <WidgetBoundary title={t('dash.revPay')}>
        <ChartCard title={t('dash.revPay')} description={`${date(f.from)} – ${date(f.to)} · ${t('dash.revPayHelp')}`} state={widgetState(trend, (trend.data?.data.length ?? 0) > 0)} onRetry={() => trend.refetch()}>
          <RevenuePaymentsChart
            data={(trend.data?.data ?? []).map((r) => ({ date: r.date, occupancy: r.occupancy, revenue: Number(r.revenue ?? 0), payments: Number(r.payments ?? 0) }))}
            money={(v) => fmt(v.toFixed(2))}
            onSelect={reports ? setDay : undefined}
          />
          <div className="mt-2 flex flex-wrap gap-4 text-xs text-muted">
            <span className="flex items-center gap-1.5">
              <span aria-hidden className="size-2.5 rounded-[3px] bg-chart-1" />
              {t('dash.kpi.revenue')}
            </span>
            <span className="flex items-center gap-1.5">
              <span aria-hidden className="size-2.5 rounded-[3px] bg-chart-2" />
              {t('dash.kpi.moneyTaken')}
            </span>
          </div>
        </ChartCard>
      </WidgetBoundary>

      {reports && (
        <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,340px),1fr))] gap-4">
          <WidgetBoundary title={t('dash.bySource')}>
            <ChartCard title={t('dash.bySource')} state={widgetState(perf, (perf.data?.data.sources.length ?? 0) > 0)} onRetry={() => perf.refetch()}>
              <BarList
                items={(perf.data?.data.sources ?? []).map((s) => ({ key: s.source, label: t(`reservations.sources.${s.source}`, { defaultValue: s.source }), sub: t('dash.bookingsCount', { count: s.bookings }), value: Number(s.value), display: fmt(s.value) }))}
              />
            </ChartCard>
          </WidgetBoundary>
          <WidgetBoundary title={t('dash.byRoomType')}>
            <ChartCard title={t('dash.byRoomType')} state={widgetState(perf, types.size > 0)} onRetry={() => perf.refetch()}>
              <BarList
                color={SERIES[1]}
                items={[...types.entries()].map(([name, v]) => ({ key: name, label: name, sub: t('dash.nightsCount', { count: v.nights }), value: Number(v.revenue), display: fmt(v.revenue) }))}
              />
            </ChartCard>
          </WidgetBoundary>
          {can(['reports.payments', 'reports.revenue']) && (
            <WidgetBoundary title={t('dash.paymentMethods')}>
              <PaymentMethods q={pays} fmt={fmt} />
            </WidgetBoundary>
          )}
        </div>
      )}

      <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,520px),1fr))] gap-4">
        {can('reports.outstanding') && (
          <WidgetBoundary title={t('dash.unpaidBills')}>
            <ChartCard
              title={t('dash.unpaidBills')}
              description={unpaid.data ? `${t('dash.bills', { count: unpaidRows.length })} · ${fmt(addMoney(...unpaidRows.map((r) => r.balance)))}` : undefined}
              action={
                <Link to="/billing" className="tap-link text-sm font-medium text-accent-text">
                  {t('dash.viewAll')} →
                </Link>
              }
              state={widgetState(unpaid, unpaidRows.length > 0)}
              onRetry={() => unpaid.refetch()}
              emptyText={t('dash.desk.empty.unpaid')}
              bodyClassName="p-0"
            >
              <UnpaidRows rows={unpaidRows.slice(0, 8)} onInvoice={setInvoice} />
            </ChartCard>
          </WidgetBoundary>
        )}
        {can('payments.view') && (
          <WidgetBoundary title={t('dash.recentPayments')}>
            <ChartCard
              title={t('dash.recentPayments')}
              action={
                <Link to="/billing?tab=payments" className="tap-link text-sm font-medium text-accent-text">
                  {t('dash.viewAll')} →
                </Link>
              }
              state={widgetState(recent, (recent.data?.data.length ?? 0) > 0)}
              onRetry={() => recent.refetch()}
              emptyText={t('dash.noPayments')}
              bodyClassName="p-0"
            >
              <ul className="flex flex-col">
                {(recent.data?.data ?? []).map((p) => (
                  <li key={p.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-border px-4 py-2.5 text-sm">
                    <span className="w-28 text-muted tabular-nums">{dateTime(p.paid_at)}</span>
                    <span className="min-w-0 flex-1 truncate">
                      <span className="font-medium">{p.reservation?.guest ?? '—'}</span> <span className="text-xs text-muted">{p.reservation?.code}</span>
                    </span>
                    <Badge tone={p.type === 'refund' ? 'danger' : 'grey'}>{p.type === 'refund' ? t('dash.refund') : (p.method ?? '—')}</Badge>
                    <span className="w-28 text-right font-semibold tabular-nums">{money(p.type === 'refund' ? `-${p.amount_in_base}` : p.amount_in_base, currency)}</span>
                  </li>
                ))}
              </ul>
            </ChartCard>
          </WidgetBoundary>
        )}
      </div>

      <DaySheet day={day} branchId={f.branchId} money onClose={() => setDay(null)} />
      <InvoiceSheet id={invoice} onClose={() => setInvoice(null)} />
    </div>
  )
}
