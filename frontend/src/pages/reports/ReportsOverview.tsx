import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { ChevronRight } from 'lucide-react'
import { Donut, Sparkline } from '@/components/charts/charts'
import { KpiCard } from '@/components/charts/KpiCard'
import { KpiGrid, SERIES, WidgetBoundary } from '@/components/charts/kit'
import { useMe } from '@/lib/auth'
import { addMoney } from '@/lib/decimal'
import { money } from '@/lib/format'
import { useCan } from '@/lib/hotel'
import { change, useReport, type Kpis, type ReportFilters } from '@/lib/reports'

type Perf = { kpis: Kpis; compare: Kpis | null; daily: { date: string; occupancy: number; revenue: string; payments: string }[] }
type Pays = { by_method: { method: string; amount_in_base: string; count: number }[]; total: string }
type Owed = { rows: { balance: string }[] }

/**
 * The reports landing (P18): the main numbers of the chosen period with their change, and one small card per report.
 * A card opens its report on this page.
 */
export function ReportsOverview({ f, open }: { f: ReportFilters; open: (key: string) => void }) {
  const { t } = useTranslation()
  const can = useCan()
  const me = useMe()
  const currency = me.data?.hotel?.default_currency ?? 'ETB'
  const fmt = (v: string) => money(v, currency)
  const perf = useReport<Perf>('performance', f)
  const pays = useReport<Pays>('payments', f, can('reports.payments'))
  const owed = useReport<Owed>('outstanding', f, can('reports.outstanding'))
  const k = perf.data?.data.kpis
  const c = perf.data?.data.compare
  const daily = perf.data?.data.daily ?? []
  const money_ = can('reports.revenue')
  const methods = pays.data?.data.by_method ?? []
  const payTotal = Number(pays.data?.data.total ?? 0) || 1
  const owedRows = owed.data?.data.rows ?? []

  return (
    <div className="flex flex-col gap-4">
      <KpiGrid>
        <KpiCard label={t('dash.kpi.occupancy')} loading={perf.isPending} value={k ? `${k.occupancy}%` : '—'} change={k && c ? Math.round((k.occupancy - c.occupancy) * 10) / 10 : null} changeText={k && c ? t('dash.points', { n: Math.abs(Math.round((k.occupancy - c.occupancy) * 10) / 10) }) : undefined} spark={daily.map((d) => d.occupancy)} onOpen={() => open('performance')} />
        {money_ && <KpiCard label={t('dash.kpi.adr')} loading={perf.isPending} value={k ? fmt(k.adr) : '—'} change={k && c ? change(k.adr, c.adr) : null} onOpen={() => open('performance')} />}
        {money_ && <KpiCard label={t('dash.kpi.revpar')} loading={perf.isPending} value={k ? fmt(k.revpar) : '—'} change={k && c ? change(k.revpar, c.revpar) : null} onOpen={() => open('performance')} />}
        {money_ && <KpiCard label={t('dash.kpi.revenue')} loading={perf.isPending} value={k ? fmt(k.revenue) : '—'} change={k && c ? change(k.revenue, c.revenue) : null} spark={daily.map((d) => Number(d.revenue))} onOpen={() => open(can('reports.revenue') ? 'daily' : 'performance')} />}
        <KpiCard label={t('reports.overview.bookings')} loading={perf.isPending} value={k ? String(k.bookings) : '—'} change={k && c ? change(k.bookings, c.bookings) : null} onOpen={() => open('performance')} />
        <KpiCard label={t('reports.overview.cancellationRate')} loading={perf.isPending} value={k ? `${k.cancellation_rate}%` : '—'} change={k && c ? Math.round((k.cancellation_rate - c.cancellation_rate) * 10) / 10 : null} changeText={k && c ? t('dash.points', { n: Math.abs(Math.round((k.cancellation_rate - c.cancellation_rate) * 10) / 10) }) : undefined} invert onOpen={can('reports.cancellations') ? () => open('cancellations') : undefined} />
        <KpiCard label={t('reports.overview.leadTime')} loading={perf.isPending} value={k ? t('reports.overview.days', { n: k.lead_time }) : '—'} sub={c ? t('reports.overview.was', { value: t('reports.overview.days', { n: c.lead_time }) }) : undefined} />
        <KpiCard label={t('reports.overview.averageStay')} loading={perf.isPending} value={k ? t('reports.overview.nights', { n: k.average_stay }) : '—'} sub={c ? t('reports.overview.was', { value: t('reports.overview.nights', { n: c.average_stay }) }) : undefined} />
      </KpiGrid>

      <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,280px),1fr))] gap-4">
        <WidgetBoundary title={t('reports.names.performance')}>
          <ReportCard title={t('reports.names.performance')} line={k ? t('reports.overview.perfLine', { occ: k.occupancy, revenue: money_ ? fmt(k.revenue) : '—' }) : ''} onOpen={() => open('performance')}>
            <Sparkline values={daily.map((d) => d.occupancy)} label={t('dash.sparkAria', { label: t('dash.kpi.occupancy') })} />
          </ReportCard>
        </WidgetBoundary>
        {can('reports.payments') && (
          <WidgetBoundary title={t('reports.names.payments')}>
            <ReportCard title={t('reports.names.payments')} line={pays.data ? t('reports.overview.paysLine', { total: fmt(pays.data.data.total), count: methods.reduce((s: number, m) => s + m.count, 0) }) : ''} onOpen={() => open('payments')}>
              {methods.length > 0 && (
                <Donut
                  size={56}
                  label={methods.map((m) => `${m.method} ${Math.round((Number(m.amount_in_base) / payTotal) * 100)}%`).join(', ')}
                  slices={methods.map((m, i) => ({ key: m.method, label: m.method, value: Number(m.amount_in_base), color: SERIES[i % SERIES.length] }))}
                  center={null}
                />
              )}
            </ReportCard>
          </WidgetBoundary>
        )}
        {can('reports.outstanding') && (
          <ReportCard title={t('reports.names.outstanding')} line={owed.data ? t('reports.overview.owedLine', { count: owedRows.length, total: fmt(addMoney(...owedRows.map((r) => r.balance))) }) : ''} onOpen={() => open('outstanding')} />
        )}
        {can('reports.cancellations') && <ReportCard title={t('reports.names.cancellations')} line={k ? t('reports.overview.cancelLine', { rate: k.cancellation_rate, count: k.bookings }) : ''} onOpen={() => open('cancellations')} />}
        {can('reports.tax') && <ReportCard title={t('reports.names.tax')} line={k ? t('reports.overview.taxLine', { tax: fmt(k.tax) }) : ''} onOpen={() => open('tax')} />}
        {can('reports.guests') && <ReportCard title={t('reports.names.guests')} line={t('reports.overview.guestsLine')} onOpen={() => open('guests')} />}
      </div>
    </div>
  )
}

function ReportCard({ title, line, onOpen, children }: { title: string; line: string; onOpen: () => void; children?: ReactNode }) {
  const { t } = useTranslation()
  return (
    <button type="button" onClick={onOpen} className="panel flex min-h-24 items-center gap-3 p-4 text-left transition-colors duration-150 hover:bg-sunken/40">
      <span className="flex min-w-0 flex-1 flex-col gap-1">
        <span className="font-semibold">{title}</span>
        <span className="text-sm text-muted">{line}</span>
        <span className="flex items-center gap-1 text-sm font-medium text-accent-text">
          {t('reports.overview.open')}
          <ChevronRight aria-hidden className="size-4" />
        </span>
      </span>
      {children}
    </button>
  )
}
