import { useTranslation } from 'react-i18next'
import { Bar, BarChart, Cell, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Donut } from '@/components/charts/charts'
import { KpiCard } from '@/components/charts/KpiCard'
import { ChartCard, KpiGrid, Legend, ROOM_COLORS, SERIES, WidgetBoundary, useReducedMotion, widgetState } from '@/components/charts/kit'
import { useMe } from '@/lib/auth'
import { addMoney, compareMoney, isNegative } from '@/lib/decimal'
import { date, dateTime, money } from '@/lib/format'
import { useCan } from '@/lib/hotel'
import { useHkBoard, useShifts, useTasks, type CashierSession } from '@/lib/modules'

/** Cashier overview (P18), above the shift form: my drawer now, and short / over for the last closed shifts. */
export function CashierOverview({ shift }: { shift: CashierSession | null | undefined }) {
  const { t } = useTranslation()
  const can = useCan()
  const me = useMe()
  const reduced = useReducedMotion()
  const currency = me.data?.hotel?.default_currency ?? 'ETB'
  const recent = useShifts({ per_page: 20 })
  const rows = recent.data?.data ?? []
  const closed = rows.filter((s) => s.status !== 'open')
  const diffs = closed.map((s) => s.balances.find((b) => b.currency === currency)?.difference ?? '0.00')
  const totalDiff = addMoney(...diffs)
  const short = diffs.filter((d) => isNegative(d)).length
  const expected = shift?.expected?.[currency]
  const data = [...closed].reverse().map((s) => {
    const d = s.balances.find((b) => b.currency === currency)?.difference ?? '0.00'
    return { id: s.id, label: `${date(s.opened_at)} · ${s.user?.name ?? ''}`, value: Number(d), raw: d }
  })

  return (
    <div className="flex flex-col gap-4">
      <KpiGrid>
        {can('cashier.own_shift') && (
          <KpiCard label={t('overviews.cashier.myShift')} value={shift ? t('overviews.cashier.open') : t('overviews.cashier.notOpen')} sub={shift ? t('overviews.cashier.since', { time: dateTime(shift.opened_at) }) : undefined} />
        )}
        {expected && <KpiCard label={t('overviews.cashier.expected')} value={money(expected.expected, currency)} sub={t('overviews.cashier.expectedSub', { start: money(expected.opening, currency), cash: money(expected.cash_in, currency) })} />}
        {shift?.payments && <KpiCard label={t('overviews.cashier.paymentsShift')} value={String(shift.payments.length)} />}
        {can('cashier.view_all') && <KpiCard loading={recent.isPending} label={t('overviews.cashier.openNow')} value={String(rows.filter((s) => s.status === 'open').length)} />}
        {can('cashier.view_all') && <KpiCard loading={recent.isPending} label={t('overviews.cashier.toApprove')} value={String(rows.filter((s) => s.status === 'closed').length)} />}
        <KpiCard
          loading={recent.isPending}
          label={t('overviews.cashier.shortOver', { count: closed.length })}
          value={money(totalDiff, currency)}
          sub={short ? t('overviews.cashier.shortCount', { count: short }) : t('overviews.cashier.noDifferences')}
        />
      </KpiGrid>
      <WidgetBoundary title={t('overviews.cashier.chart')}>
        <ChartCard title={t('overviews.cashier.chart')} description={t('overviews.cashier.chartHelp')} state={widgetState(recent, data.length > 0)} onRetry={() => recent.refetch()} emptyText={t('overviews.cashier.noClosed')}>
          <div role="img" aria-label={t('overviews.cashier.chartAria', { count: data.length, short })} className="h-36 w-full">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={data} margin={{ top: 8, right: 0, bottom: 0, left: 0 }}>
                <XAxis dataKey="id" hide />
                <YAxis tick={{ fontSize: 11, fill: 'var(--muted)' }} tickLine={false} axisLine={false} width={48} />
                <ReferenceLine y={0} stroke="var(--border)" />
                <Tooltip
                  cursor={{ fill: 'var(--sunken)', opacity: 0.6 }}
                  content={({ active, payload }) => {
                    const p = active && payload?.[0] ? (payload[0].payload as (typeof data)[number]) : null
                    return p ? (
                      <div className="rounded-lg border border-border bg-surface px-3 py-2 text-xs shadow-md">
                        <p className="font-medium">{p.label}</p>
                        <p className="tabular-nums">{money(p.raw, currency)}</p>
                      </div>
                    ) : null
                  }}
                />
                <Bar dataKey="value" minPointSize={2} isAnimationActive={!reduced} animationDuration={200}>
                  {data.map((d) => (
                    <Cell key={d.id} fill={compareMoney(d.raw, '0') < 0 ? 'var(--danger)' : compareMoney(d.raw, '0') > 0 ? 'var(--chart-1)' : 'var(--chart-5)'} />
                  ))}
                </Bar>
              </BarChart>
            </ResponsiveContainer>
          </div>
        </ChartCard>
      </WidgetBoundary>
    </div>
  )
}

/** Housekeeping overview (P18), above the tabs: rooms by status, tasks today, who is cleaning what. */
export function HousekeepingOverview({ branchId }: { branchId: number | null }) {
  const { t } = useTranslation()
  const can = useCan()
  const board = useHkBoard(branchId)
  const tasks = useTasks(!can('housekeeping.assign'), branchId)
  const rooms = board.data?.data ?? []
  const byStatus = rooms.reduce<Record<string, number>>((a, r) => ({ ...a, [r.status]: (a[r.status] ?? 0) + 1 }), {})
  const list = tasks.data?.data ?? []
  const counts = { pending: list.filter((x) => x.status === 'pending').length, in_progress: list.filter((x) => x.status === 'in_progress').length, done: list.filter((x) => x.status === 'done').length }
  const total = Math.max(1, counts.pending + counts.in_progress + counts.done)
  const people = new Map<string, string[]>()
  for (const x of list.filter((y) => y.status !== 'done')) {
    const who = x.assigned_to?.name ?? t('dash.ops.anyone')
    people.set(who, [...(people.get(who) ?? []), `${x.room?.number ?? '?'} ${t(`housekeeping.statuses.${x.status}`).toLowerCase()}`])
  }
  const dirty = (byStatus.dirty ?? 0) + (byStatus.cleaning ?? 0)

  return (
    <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,300px),1fr))] gap-4">
      <WidgetBoundary title={t('dash.roomStatus')}>
        <ChartCard title={t('dash.roomStatus')} state={widgetState(board, rooms.length > 0)} onRetry={() => board.refetch()}>
          <div className="flex flex-wrap items-center gap-4">
            <Donut
              size={110}
              label={Object.entries(byStatus).map(([s, n]) => `${t(`roomStatus.${s}`)} ${n}`).join(', ')}
              slices={Object.entries(byStatus).map(([s, n]) => ({ key: s, label: t(`roomStatus.${s}`), value: n, color: ROOM_COLORS[s] ?? SERIES[4] }))}
              center={
                <>
                  <span className="text-xl font-bold">{dirty}</span>
                  <span className="text-[11px] text-muted">{t('overviews.hk.toClean')}</span>
                </>
              }
            />
            <Legend items={Object.entries(byStatus).map(([s, n]) => ({ key: s, label: t(`roomStatus.${s}`), value: String(n), color: ROOM_COLORS[s] ?? SERIES[4] }))} />
          </div>
        </ChartCard>
      </WidgetBoundary>
      <WidgetBoundary title={t('overviews.hk.tasksToday')}>
        <ChartCard title={t('overviews.hk.tasksToday')} state={widgetState(tasks, list.length > 0)} onRetry={() => tasks.refetch()} emptyText={t('housekeeping.allDone')}>
          <div className="flex flex-col gap-3">
            <div role="img" aria-label={t('overviews.hk.tasksAria', counts)} className="flex h-7 overflow-hidden rounded-md bg-sunken">
              <span className="bg-room-clean" style={{ width: `${(counts.in_progress / total) * 100}%` }} />
              <span className="bg-room-dirty" style={{ width: `${(counts.pending / total) * 100}%` }} />
              <span className="bg-room-available" style={{ width: `${(counts.done / total) * 100}%` }} />
            </div>
            <Legend
              items={[
                { key: 'in_progress', label: t('housekeeping.statuses.in_progress'), value: String(counts.in_progress), color: 'var(--room-clean)' },
                { key: 'pending', label: t('housekeeping.statuses.pending'), value: String(counts.pending), color: 'var(--room-dirty)' },
                { key: 'done', label: t('housekeeping.statuses.done'), value: String(counts.done), color: 'var(--room-available)' },
              ]}
            />
          </div>
        </ChartCard>
      </WidgetBoundary>
      {can('housekeeping.assign') && (
        <WidgetBoundary title={t('overviews.hk.who')}>
          <ChartCard title={t('overviews.hk.who')} state={widgetState(tasks, people.size > 0)} onRetry={() => tasks.refetch()} emptyText={t('housekeeping.allDone')}>
            <ul className="flex flex-col gap-2 text-sm">
              {[...people.entries()].map(([who, items]) => (
                <li key={who} className="flex items-center gap-3">
                  <span aria-hidden className="flex size-8 shrink-0 items-center justify-center rounded-full bg-sunken text-xs font-semibold">
                    {who
                      .split(' ')
                      .map((w) => w[0])
                      .join('')
                      .slice(0, 2)}
                  </span>
                  <span className="min-w-0 flex-1 font-medium">{who}</span>
                  <span className="truncate text-xs text-muted">{items.join(' · ')}</span>
                </li>
              ))}
            </ul>
          </ChartCard>
        </WidgetBoundary>
      )}
    </div>
  )
}
