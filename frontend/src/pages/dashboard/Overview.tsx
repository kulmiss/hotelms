import { useState } from 'react'
import { Link } from 'react-router'
import { useQueries } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ChevronRight } from 'lucide-react'
import { Donut, OccRevenueChart, PaceChart } from '@/components/charts/charts'
import { KpiCard } from '@/components/charts/KpiCard'
import { ChartCard, KpiGrid, Legend, ROOM_COLORS, SERIES, WidgetBoundary, widgetState } from '@/components/charts/kit'
import { api } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { previousRange, useDashToday, useOutstanding, usePaymentsSplit, usePendingApprovals, usePerformance, useTrend, type DashFilters, type TrendRow } from '@/lib/dashboard'
import { addMoney } from '@/lib/decimal'
import type { BoardRoom } from '@/lib/desk'
import { date, money } from '@/lib/format'
import { useCan } from '@/lib/hotel'
import { change } from '@/lib/reports'
import { cn } from '@/lib/cn'
import { DeskLists, useTodayAll, type DeskTab } from './DeskLists'
import { DaySheet, InvoiceSheet, ReservationSheet, SideSheet, type StayAction } from './Sheets'

/** Occupancy of a period from the trend rows (rooms sold ÷ rooms × days). */
function occupancyOf(rows: TrendRow[] | undefined, rooms: number): number | null {
  if (!rows?.length || !rooms) return null
  const sold = rows.reduce((s, r) => s + r.in_house, 0)
  return Math.round((sold / (rooms * rows.length)) * 1000) / 10
}

type KpiKey = 'occupancy' | 'adr' | 'revpar' | 'revenue' | 'arrivals'

/** The Overview tab: KPIs, occupancy and revenue, what needs attention, rooms, pace, payments and today's lists. */
export function Overview({ f, onTab }: { f: DashFilters; onTab: (tab: 'operations') => void }) {
  const { t } = useTranslation()
  const can = useCan()
  const me = useMe()
  const currency = me.data?.hotel?.default_currency ?? 'ETB'
  const fmt = (v: string | number) => money(String(v), currency)
  const moneyRole = can('dashboard.view_revenue')
  const reportRole = can(['reports.revenue', 'reports.occupancy'])

  const dash = useDashToday(f.branchId)
  const trend = useTrend(f)
  const prev = useTrend({ ...f, ...previousRange(f.from, f.to) })
  const perf = usePerformance(f, moneyRole && reportRole)
  const pays = usePaymentsSplit(f, moneyRole && can(['reports.payments', 'reports.revenue']))
  const unpaid = useOutstanding(f, can('reports.outstanding'))

  const [deskTab, setDeskTab] = useState<DeskTab | null>(null)
  const [stay, setStay] = useState<{ id: number; action: StayAction } | null>(null)
  const [invoice, setInvoice] = useState<number | null>(null)
  const [day, setDay] = useState<string | null>(null)
  const [kpi, setKpi] = useState<KpiKey | null>(null)
  const [rooms, setRooms] = useState<string | null>(null)
  const [metric, setMetric] = useState<'both' | 'occupancy' | 'revenue'>('both')

  const d = dash.data?.data
  const rows = trend.data?.data
  const roomCount = trend.data?.rooms ?? 0
  const occ = occupancyOf(rows, roomCount)
  const occBefore = occupancyOf(prev.data?.data, prev.data?.rooms ?? 0)
  const k = perf.data?.data.kpis
  const c = perf.data?.data.compare
  const series = (key: keyof TrendRow) => rows?.map((r) => Number(r[key] ?? 0))
  const goDesk = (tab: DeskTab) => {
    setDeskTab(tab)
    document.getElementById('dash-desk')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  return (
    <div className="flex flex-col gap-4">
      <KpiGrid>
        <KpiCard
          label={t('dash.kpi.occupancy')}
          loading={trend.isPending}
          value={occ === null ? '—' : `${occ}%`}
          change={occ !== null && occBefore !== null ? Math.round((occ - occBefore) * 10) / 10 : null}
          changeText={occ !== null && occBefore !== null ? t('dash.points', { n: Math.abs(Math.round((occ - occBefore) * 10) / 10) }) : undefined}
          sub={d ? t('dash.todayValue', { value: `${Math.round(d.occupancy)}%` }) : undefined}
          spark={series('occupancy')}
          onOpen={() => setKpi('occupancy')}
        />
        {moneyRole && reportRole && (
          <>
            <KpiCard label={t('dash.kpi.adr')} loading={perf.isPending} value={k ? fmt(k.adr) : '—'} change={k && c ? change(k.adr, c.adr) : null} spark={series('adr')} onOpen={() => setKpi('adr')} />
            <KpiCard label={t('dash.kpi.revpar')} loading={perf.isPending} value={k ? fmt(k.revpar) : '—'} change={k && c ? change(k.revpar, c.revpar) : null} spark={series('revpar')} onOpen={() => setKpi('revpar')} />
            <KpiCard label={t('dash.kpi.revenue')} loading={perf.isPending} value={k ? fmt(k.revenue) : '—'} change={k && c ? change(k.revenue, c.revenue) : null} spark={series('revenue')} onOpen={() => setKpi('revenue')} />
          </>
        )}
        {moneyRole && d?.unpaid_balances !== undefined && (
          <KpiCard
            label={t('dash.kpi.outstanding')}
            value={fmt(d.unpaid_balances)}
            sub={unpaid.data ? t('dash.bills', { count: unpaid.data.data.rows.length }) : undefined}
            onOpen={can('reports.outstanding') ? () => goDesk('unpaid') : undefined}
          />
        )}
        <KpiCard
          label={t('dash.kpi.arrivingLeaving')}
          loading={dash.isPending}
          value={d ? `${d.arrivals} · ${d.departures}` : '—'}
          sub={d ? t('dash.soFar', { in: d.arrived, out: d.departed, house: d.in_house }) : undefined}
          spark={series('arrivals')}
          onOpen={() => setKpi('arrivals')}
        />
        {!moneyRole && d && <KpiCard label={t('dash.kpi.toClean')} value={String(d.rooms.by_status.dirty ?? 0)} onOpen={() => setRooms('dirty')} />}
      </KpiGrid>

      <div className="flex flex-wrap gap-4">
        <WidgetBoundary title={t('dash.occRevenue')} className="min-w-0 flex-[2_1_560px]">
          <ChartCard
            className="min-w-0 flex-[2_1_560px]"
            title={moneyRole ? t('dash.occRevenue') : t('dash.occupancyOverTime')}
            description={`${date(f.from)} – ${date(f.to)}`}
            state={widgetState(trend, (rows?.length ?? 0) > 0)}
            onRetry={() => trend.refetch()}
            action={
              moneyRole && (
                <div role="group" aria-label={t('dash.metric')} className="flex gap-0.5 rounded-lg bg-sunken p-[3px]">
                  {(['both', 'occupancy', 'revenue'] as const).map((m) => (
                    <button
                      key={m}
                      type="button"
                      aria-pressed={metric === m}
                      onClick={() => setMetric(m)}
                      className={cn('min-h-7 rounded-md px-2.5 text-xs font-medium pointer-coarse:min-h-11', metric === m ? 'bg-background text-text shadow-sm' : 'text-muted hover:text-text')}
                    >
                      {t(`dash.metrics.${m}`)}
                    </button>
                  ))}
                </div>
              )
            }
          >
            <OccRevenueChart
              data={(rows ?? []).map((r) => ({ date: r.date, occupancy: r.occupancy, revenue: r.revenue !== undefined ? Number(r.revenue) : undefined }))}
              metric={moneyRole ? metric : 'occupancy'}
              money={(v) => fmt(v.toFixed(2))}
              onSelect={reportRole ? setDay : undefined}
            />
          </ChartCard>
        </WidgetBoundary>
        <WidgetBoundary title={t('dash.attention.title')} className="min-w-0 flex-[1_1_320px]">
          <NeedsAttention f={f} className="min-w-0 flex-[1_1_320px]" onDesk={goDesk} onRooms={setRooms} onApprovals={() => onTab('operations')} unpaidCount={unpaid.data?.data.rows.length} />
        </WidgetBoundary>
      </div>

      <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,300px),1fr))] gap-4">
        <WidgetBoundary title={t('dash.roomStatus')}>
          <ChartCard title={t('dash.roomStatus')} state={widgetState(dash, (d?.rooms.total ?? 0) > 0)} onRetry={() => dash.refetch()}>
            {d && (
              <div className="flex flex-wrap items-center gap-4">
                <Donut
                  label={t('dash.roomStatusAria', { total: d.rooms.total })}
                  slices={Object.entries(d.rooms.by_status).map(([s, n]) => ({ key: s, label: t(`roomStatus.${s}`), value: n, color: ROOM_COLORS[s] ?? SERIES[4] }))}
                  onSelect={setRooms}
                  center={
                    <>
                      <span className="text-xl font-bold">{Math.round(d.occupancy)}%</span>
                      <span className="text-[11px] text-muted">{t('dash.ofRooms', { count: d.rooms.total })}</span>
                    </>
                  }
                />
                <Legend
                  onSelect={setRooms}
                  items={Object.entries(d.rooms.by_status).map(([s, n]) => ({ key: s, label: t(`roomStatus.${s}`), value: String(n), color: ROOM_COLORS[s] ?? SERIES[4] }))}
                />
              </div>
            )}
          </ChartCard>
        </WidgetBoundary>
        <WidgetBoundary title={t('dash.pace')}>
          <ChartCard
            title={t('dash.pace')}
            description={t('dash.paceHelp', { days: d?.next_days?.length ?? 7 })}
            state={widgetState(dash, !!d)}
            onRetry={() => dash.refetch()}
            footer={
              can('reservations.view') && (
                <Link to="/reservations?view=calendar" className="tap-link font-medium text-accent-text">
                  {t('dash.openCalendar')} →
                </Link>
              )
            }
          >
            {d && <PaceChart data={d.next_days ?? d.next_7_days} />}
          </ChartCard>
        </WidgetBoundary>
        {moneyRole && can(['reports.payments', 'reports.revenue']) && (
          <WidgetBoundary title={t('dash.paymentMethods')}>
            <PaymentMethods q={pays} fmt={fmt} />
          </WidgetBoundary>
        )}
      </div>

      <WidgetBoundary title={t('dash.desk.title')}>
        <DeskLists f={f} tab={deskTab} setTab={setDeskTab} onStay={(id, action) => setStay({ id, action })} onInvoice={setInvoice} />
      </WidgetBoundary>

      <ReservationSheet key={stay ? `${stay.id}-${stay.action}` : 'none'} id={stay?.id ?? null} action={stay?.action ?? 'view'} onClose={() => setStay(null)} />
      <InvoiceSheet id={invoice} onClose={() => setInvoice(null)} />
      <DaySheet day={day} branchId={f.branchId} money={moneyRole} onClose={() => setDay(null)} />
      <KpiSheet metric={kpi} rows={rows ?? []} fmt={fmt} onClose={() => setKpi(null)} />
      <RoomsSheet status={rooms} branchId={f.branchId} onClose={() => setRooms(null)} />
    </div>
  )
}

/** Payments by method (donut + legend). */
export function PaymentMethods({ q, fmt }: { q: ReturnType<typeof usePaymentsSplit>; fmt: (v: string) => string }) {
  const { t } = useTranslation()
  const rows = q.data?.data.by_method ?? []
  const total = q.data?.data.total ?? '0'
  const totalNum = Number(total) || 1
  const count = rows.reduce((s, r) => s + r.count, 0)
  return (
    <ChartCard
      title={t('dash.paymentMethods')}
      state={widgetState(q, rows.length > 0)}
      onRetry={() => q.refetch()}
      emptyText={t('dash.noPayments')}
      footer={
        <Link to="/reports?report=payments" className="tap-link font-medium text-accent-text">
          {t('dash.viewAll')} →
        </Link>
      }
    >
      <div className="flex flex-wrap items-center gap-4">
        <Donut
          label={rows.map((r) => `${r.method} ${Math.round((Number(r.amount_in_base) / totalNum) * 100)}%`).join(', ')}
          slices={rows.map((r, i) => ({ key: r.method, label: r.method, value: Number(r.amount_in_base), color: SERIES[i % SERIES.length] }))}
          center={
            <>
              <span className="text-sm font-bold">{fmt(total)}</span>
              <span className="text-[11px] text-muted">{t('dash.payments', { count })}</span>
            </>
          }
        />
        <Legend
          items={rows.map((r, i) => ({
            key: r.method,
            label: r.method,
            value: `${Math.round((Number(r.amount_in_base) / totalNum) * 100)}%`,
            sub: `${fmt(r.amount_in_base)} · ${r.count}`,
            color: SERIES[i % SERIES.length],
          }))}
        />
      </div>
    </ChartCard>
  )
}

/** "Needs attention": each line opens its list in place (the desk list below, a rooms sheet, or the Operations tab). */
function NeedsAttention({
  f,
  className,
  onDesk,
  onRooms,
  onApprovals,
  unpaidCount,
}: {
  f: DashFilters
  className?: string
  onDesk: (t: DeskTab) => void
  onRooms: (status: string) => void
  onApprovals: () => void
  unpaidCount?: number
}) {
  const { t } = useTranslation()
  const can = useCan()
  const me = useMe()
  const currency = me.data?.hotel?.default_currency ?? 'ETB'
  const dash = useDashToday(f.branchId)
  const today = useTodayAll(f.branchId, can('frontdesk.view'))
  const approvals = usePendingApprovals(can('approvals.decide'))
  const d = dash.data?.data
  type Item = { key: string; title: string; detail: string; count: number; tone: 'danger' | 'warn' | 'info' | 'ok'; onClick?: () => void; to?: string }
  const overdue = today.data?.in_house.filter((r) => r.overdue) ?? []
  const items: Item[] = []
  if (can('frontdesk.view') && today.data)
    items.push({ key: 'overdue', title: t('dash.attention.overdue'), detail: overdue.map((r) => r.rooms.map((x) => x.room_number).filter(Boolean).join(', ')).filter(Boolean).join(', ') || t('dash.attention.none'), count: overdue.length, tone: overdue.length ? 'danger' : 'ok', onClick: () => onDesk('departures') })
  if (d) items.push({ key: 'late', title: t('dash.attention.late'), detail: t('dash.attention.lateHelp'), count: d.late_arrivals, tone: d.late_arrivals ? 'warn' : 'ok', onClick: can('frontdesk.view') ? () => onDesk('arrivals') : undefined })
  if (d?.unpaid_balances !== undefined)
    items.push({ key: 'unpaid', title: t('dash.attention.unpaid'), detail: money(d.unpaid_balances, currency), count: unpaidCount ?? 0, tone: unpaidCount ? 'warn' : 'ok', onClick: can('reports.outstanding') ? () => onDesk('unpaid') : undefined, to: can('reports.outstanding') ? undefined : '/billing' })
  if (d) items.push({ key: 'dirty', title: t('dash.attention.dirty'), detail: t('dash.attention.dirtyHelp'), count: d.rooms.by_status.dirty ?? 0, tone: d.rooms.by_status.dirty ? 'warn' : 'ok', onClick: () => onRooms('dirty') })
  if (can('approvals.decide') && approvals.data)
    items.push({ key: 'approvals', title: t('dash.attention.approvals'), detail: approvals.data.data[0]?.title ?? t('dash.attention.none'), count: approvals.data.data.length, tone: approvals.data.data.length ? 'info' : 'ok', onClick: onApprovals })
  if (d && d.pending_expiring > 0) items.push({ key: 'expiring', title: t('dash.attention.expiring'), detail: t('dash.attention.expiringHelp'), count: d.pending_expiring, tone: 'warn', to: '/reservations' })
  if (d?.cashier_shortages !== undefined) items.push({ key: 'shortages', title: t('dash.attention.shortages'), detail: t('dash.attention.shortagesHelp'), count: d.cashier_shortages, tone: d.cashier_shortages ? 'danger' : 'ok', to: can(['cashier.view_all', 'cashier.own_shift']) ? '/cashier' : undefined })
  items.sort((a, b) => Number(b.count > 0) - Number(a.count > 0))
  const open = items.filter((i) => i.count > 0).length
  const dot = { danger: 'bg-danger', warn: 'bg-warn-text', info: 'bg-info-text', ok: 'bg-ok-text' }
  const pill = { danger: 'bg-danger-bg text-danger-text', warn: 'bg-warn-bg text-warn-text', info: 'bg-info-bg text-info-text', ok: 'bg-ok-bg text-ok-text' }
  return (
    <ChartCard className={className} title={t('dash.attention.title')} action={<span className="text-xs text-muted">{t('dash.attention.open', { count: open })}</span>} state={widgetState(dash, items.length > 0)} onRetry={() => dash.refetch()}>
      <ul className="flex flex-col gap-2">
        {items.map((i) => {
          const body = (
            <>
              <span aria-hidden className={cn('size-2 shrink-0 rounded-full', dot[i.tone])} />
              <span className="flex min-w-0 flex-1 flex-col">
                <span className="font-medium">{i.title}</span>
                <span className="truncate text-xs text-muted">{i.detail}</span>
              </span>
              <span className={cn('rounded-full px-2 py-0.5 text-[13px] font-semibold tabular-nums', pill[i.tone])}>{i.count}</span>
              {(i.onClick || i.to) && <ChevronRight aria-hidden className="size-4 text-muted" />}
            </>
          )
          const cls = 'flex min-h-11 w-full items-center gap-2.5 rounded-lg border border-border px-3 py-2 text-left text-sm'
          return (
            <li key={i.key}>
              {i.onClick ? (
                <button type="button" onClick={i.onClick} className={cn(cls, 'hover:bg-sunken/50')}>
                  {body}
                </button>
              ) : i.to ? (
                <Link to={i.to} className={cn(cls, 'hover:bg-sunken/50')}>
                  {body}
                </Link>
              ) : (
                <div className={cls}>{body}</div>
              )}
            </li>
          )
        })}
      </ul>
    </ChartCard>
  )
}

/** A KPI opened in place: its value per day for the period, with a link to the full report. */
function KpiSheet({ metric, rows, fmt, onClose }: { metric: KpiKey | null; rows: TrendRow[]; fmt: (v: string) => string; onClose: () => void }) {
  const { t } = useTranslation()
  const can = useCan()
  const value = (r: TrendRow): string => {
    switch (metric) {
      case 'occupancy':
        return `${r.occupancy}% · ${t('dash.roomsSoldShort', { count: r.in_house })}`
      case 'adr':
        return fmt(r.adr ?? '0')
      case 'revpar':
        return fmt(r.revpar ?? '0')
      case 'revenue':
        return fmt(r.revenue ?? '0')
      default:
        return t('dash.inOut', { in: r.arrivals, out: r.departures })
    }
  }
  const total = metric === 'revenue' ? addMoney(...rows.map((r) => r.revenue ?? '0')) : null
  return (
    <SideSheet open={metric !== null} onClose={onClose} title={metric ? t(`dash.kpi.${metric === 'arrivals' ? 'arrivingLeaving' : metric}`) : ''} description={total ? t('dash.totalFor', { value: fmt(total) }) : t('dash.perDay')}>
      <ul className="flex flex-col divide-y divide-border rounded-lg border border-border text-sm">
        {[...rows].reverse().map((r) => (
          <li key={r.date} className="flex min-h-10 items-center justify-between gap-3 px-3">
            <span className="text-muted">{date(r.date)}</span>
            <span className="font-medium tabular-nums">{value(r)}</span>
          </li>
        ))}
      </ul>
      {can(['reports.revenue', 'reports.occupancy']) && (
        <Link to="/reports?report=performance" className="tap-link w-fit font-medium text-accent-text">
          {t('dash.viewAll')} →
        </Link>
      )}
    </SideSheet>
  )
}

/** Rooms of one status (e.g. dirty), from the room board, in a side sheet. */
export function RoomsSheet({ status, branchId, onClose }: { status: string | null; branchId: number | null; onClose: () => void }) {
  const { t } = useTranslation()
  const can = useCan()
  const me = useMe()
  const allowed = can('frontdesk.view') && status !== null
  const ids = branchId ? [branchId] : (me.data?.branches ?? []).map((b) => b.id)
  const boards = useQueries({ queries: ids.map((id) => ({ queryKey: ['desk', 'board', id, null], queryFn: () => api<{ data: BoardRoom[] }>(`/front-desk/board?branch_id=${id}`), enabled: allowed })) })
  const rooms = boards.flatMap((b) => b.data?.data ?? []).filter((r) => r.status === status)
  return (
    <SideSheet open={status !== null} onClose={onClose} title={status ? t(`roomStatus.${status}`) : ''} description={allowed ? t('dash.roomsCount', { count: rooms.length }) : undefined}>
      {!allowed && <p className="text-sm text-muted">{t('dash.roomsNoBoard')}</p>}
      {allowed && boards.some((b) => b.isPending) && <div aria-hidden className="h-32 animate-pulse rounded-lg bg-sunken" />}
      {allowed && (
        <ul className="grid grid-cols-3 gap-2">
          {rooms.map((r) => (
            <li key={r.id} className="flex flex-col rounded-lg border border-border p-2">
              <span className="text-base font-bold">{r.number}</span>
              <span className="truncate text-xs text-muted">{r.room_type}</span>
            </li>
          ))}
        </ul>
      )}
      {(can('frontdesk.view') || can('housekeeping.view')) && (
        <Link to={can('frontdesk.view') ? '/rooms' : '/housekeeping'} className="tap-link w-fit font-medium text-accent-text">
          {t('dash.viewAll')} →
        </Link>
      )}
    </SideSheet>
  )
}
