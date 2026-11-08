import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { TriangleAlertIcon } from 'lucide-react'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button, buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Delta } from '@/components/ui/Delta'
import { Field } from '@/components/ui/Field'
import { api, apiUrl, ApiError, put } from '@/lib/api'
import { cn } from '@/lib/cn'
import { date, money } from '@/lib/format'
import { addDays, localDate, localTime, today } from '@/lib/front'
import { useCan } from '@/lib/hotel'
import { change, type Kpis, type ReportFilters } from '@/lib/reports'

const qs = (f: Record<string, string | number | null | undefined>) =>
  new URLSearchParams(Object.entries(f).filter(([, v]) => v !== undefined && v !== null && v !== '').map(([k, v]) => [k, String(v)])).toString()

function useInsight<T>(path: string, params: Record<string, string | number | null | undefined>) {
  return useQuery({ queryKey: ['reports', path, params], queryFn: () => api<{ data: T }>(`/reports/${path}?${qs(params)}`) })
}

function Tile({ label, value, sub, tone }: { label: string; value: string; sub?: ReactNode; tone?: 'danger' | 'ok' }) {
  return (
    <Card className="flex flex-col gap-1 p-4">
      <span className="text-sm text-muted">{label}</span>
      <span className={cn('text-xl font-semibold tabular-nums', tone === 'danger' && 'text-danger-text', tone === 'ok' && 'text-ok-text')}>{value}</span>
      {sub && <span className="text-xs text-muted">{sub}</span>}
    </Card>
  )
}

/* ------------------------------------------------------------- daily manager report */

type Daily = {
  date: string
  kpis: Kpis
  last_week: Kpis
  arrivals: number
  departures: number
  no_shows: number
  walk_ins: number
  payments: { method: string; currency: string; count: number; amount: string; amount_in_base: string }[]
  cashier: { user: string | null; status: string; differences: { currency: string; difference: string }[] }[]
  watch: { discounts: string; waived: number; voids: number; refunds: string; unpaid: string }
  tomorrow: { date: string; arrivals: number; departures: number; occupancy: number }
  currency: string
}

export function DailyReport({ branchId }: { branchId?: number | null }) {
  const { t } = useTranslation()
  const [day, setDay] = useState(addDays(today(), -1))
  const q = useInsight<Daily>('daily', { date: day, branch_id: branchId })
  const d = q.data?.data
  return (
    <div className="flex flex-col gap-4">
      <div className="w-44 print:hidden">
        <Field label={t('billing.date')} type="date" max={today()} value={day} onChange={(e) => setDay(e.target.value)} />
      </div>
      {d && (
        <>
          <h3 className="text-base font-semibold">{t('insights.dailyTitle', { date: date(d.date) })}</h3>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Tile label={t('reports.kpis.occupancy')} value={`${d.kpis.occupancy}%`} sub={<Delta value={change(d.kpis.occupancy, d.last_week.occupancy)} />} />
            <Tile label={t('reports.kpis.revenue')} value={money(d.kpis.revenue, d.currency)} sub={<Delta value={change(d.kpis.revenue, d.last_week.revenue)} />} />
            <Tile label={t('reports.kpis.adr')} value={money(d.kpis.adr, d.currency)} sub={<Delta value={change(d.kpis.adr, d.last_week.adr)} />} />
            <Tile label={t('reports.kpis.payments')} value={money(d.kpis.payments, d.currency)} sub={<Delta value={change(d.kpis.payments, d.last_week.payments)} />} />
          </div>
          <p className="text-xs text-muted">{t('insights.vsLastWeek')}</p>
          <div className="grid gap-4 lg:grid-cols-3">
            <Card className="flex flex-col gap-1 text-sm">
              <h4 className="text-sm font-semibold">{t('insights.rooms')}</h4>
              <Row label={t('insights.arrived')} value={d.arrivals} />
              <Row label={t('insights.left')} value={d.departures} />
              <Row label={t('insights.walkIns')} value={d.walk_ins} />
              <Row label={t('reports.cancel.no_show')} value={d.no_shows} />
              <Row label={t('reports.kpis.room_nights')} value={d.kpis.room_nights} />
            </Card>
            <Card className="flex flex-col gap-1 text-sm">
              <h4 className="text-sm font-semibold">{t('insights.moneyIn')}</h4>
              {d.payments.length === 0 && <span className="text-muted">{t('reports.nothing')}</span>}
              {d.payments.map((p) => (
                <Row key={`${p.method}${p.currency}`} label={`${p.method} (${p.count})`} value={money(p.amount, p.currency)} />
              ))}
              {d.cashier.map((c, i) => (
                <Row key={i} label={t('insights.shiftOf', { name: c.user ?? '—' })} value={c.differences.map((x) => money(x.difference, x.currency)).join(' · ') || t(`cashier.statuses.${c.status}`)} />
              ))}
            </Card>
            <Card className="flex flex-col gap-1 text-sm">
              <h4 className="text-sm font-semibold">{t('insights.watch')}</h4>
              <Row label={t('billing.discounts')} value={money(d.watch.discounts, d.currency)} />
              <Row label={t('insights.waivedFees')} value={d.watch.waived} />
              <Row label={t('insights.voids')} value={d.watch.voids} />
              <Row label={t('insights.refunds')} value={money(d.watch.refunds, d.currency)} />
              <Row label={t('dashboard.unpaid')} value={money(d.watch.unpaid, d.currency)} />
            </Card>
          </div>
          <Card className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
            <span className="font-semibold">{t('insights.tomorrow', { date: date(d.tomorrow.date) })}</span>
            <span>{t('insights.tomorrowLine', { arrivals: d.tomorrow.arrivals, departures: d.tomorrow.departures, occ: d.tomorrow.occupancy })}</span>
          </Card>
        </>
      )}
    </div>
  )
}

function Row({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="flex justify-between gap-3 border-b border-border py-1 last:border-0">
      <span className="text-muted">{label}</span>
      <span className="tabular-nums">{value}</span>
    </div>
  )
}

/* ------------------------------------------------------------- forecast + targets */

type Forecast = { rooms: number; days: { date: string; room_nights: number; occupancy: number; usual_occupancy: number; room_revenue: string; arrivals: number; season: string | null }[]; pickup: { date: string; bookings: number; room_nights: number }[]; currency: string }

/** Colour for a day by how full it is (heatmap). */
const heat = (occ: number) => (occ >= 90 ? 'bg-accent text-on-accent' : occ >= 70 ? 'bg-accent/70 text-on-accent' : occ >= 50 ? 'bg-accent/45' : occ >= 25 ? 'bg-accent/25' : occ > 0 ? 'bg-accent/10' : 'bg-sunken')

export function ForecastReport({ branchId }: { branchId?: number | null }) {
  const { t } = useTranslation()
  const can = useCan()
  const q = useInsight<Forecast>('forecast', { days: 90, branch_id: branchId })
  const [picked, setPicked] = useState<string | null>(null)
  const d = q.data?.data
  const sel = d?.days.find((x) => x.date === picked) ?? d?.days[0]
  const maxPick = Math.max(1, ...(d?.pickup.map((p) => p.room_nights) ?? [1]))

  return (
    <div className="flex flex-col gap-4">
      {d && (
        <div className="grid items-start gap-4 lg:grid-cols-[1fr_18rem]">
          <Card className="flex flex-col gap-3">
            <h3 className="text-sm font-semibold">{t('insights.next90')}</h3>
            <div className="grid grid-cols-7 gap-1" role="grid" aria-label={t('insights.next90')}>
              {d.days.map((x) => (
                <button
                  key={x.date}
                  type="button"
                  aria-label={`${date(x.date)}: ${x.occupancy}%`}
                  onClick={() => setPicked(x.date)}
                  className={cn('relative flex aspect-square min-h-9 flex-col items-center justify-center rounded-md text-xs tabular-nums transition duration-75', heat(x.occupancy), sel?.date === x.date && 'ring-2 ring-text')}
                >
                  <span>{Number(x.date.slice(8, 10))}</span>
                  {x.season && <span className="absolute top-0.5 right-1 size-1.5 rounded-full bg-warn-text" />}
                </button>
              ))}
            </div>
            <div className="flex flex-wrap items-center gap-3 text-xs text-muted">
              {[0, 25, 50, 70, 90].map((v) => (
                <span key={v} className="flex items-center gap-1">
                  <span className={cn('size-3 rounded-sm', heat(v === 0 ? 1 : v))} />
                  {v}%+
                </span>
              ))}
              <span className="flex items-center gap-1">
                <span className="size-1.5 rounded-full bg-warn-text" />
                {t('insights.season')}
              </span>
            </div>
          </Card>
          {sel && (
            <Card className="flex flex-col gap-1 text-sm">
              <h3 className="text-sm font-semibold">{date(sel.date)}</h3>
              <Row label={t('reports.kpis.occupancy')} value={`${sel.occupancy}%`} />
              <Row label={t('insights.usuallyByNow')} value={`${sel.usual_occupancy}%`} />
              <Row label={t('reports.kpis.room_nights')} value={`${sel.room_nights} / ${d.rooms}`} />
              <Row label={t('dashboard.arrivals')} value={sel.arrivals} />
              <Row label={t('reports.kpis.room_revenue')} value={money(sel.room_revenue, d.currency)} />
              {sel.season && <Badge tone="warn">{sel.season}</Badge>}
            </Card>
          )}
        </div>
      )}
      {d && (
        <Card className="flex flex-col gap-2">
          <h3 className="text-sm font-semibold">{t('insights.pickup')}</h3>
          <div className="flex h-24 items-end gap-[3px]">
            {d.pickup.map((p) => (
              <span key={p.date} title={`${date(p.date)}: ${p.bookings} · ${p.room_nights}`} className="flex-1 rounded-t-sm bg-accent/50" style={{ height: `${Math.max(2, (p.room_nights / maxPick) * 100)}%` }} />
            ))}
          </div>
          <p className="text-xs text-muted">{t('insights.pickupHelp')}</p>
        </Card>
      )}
      {(can('reports.targets_manage') || can('reports.forecast')) && <Targets branchId={branchId} />}
    </div>
  )
}

type TargetRow = { branch_id: number; branch: string; month: string; revenue_target: string | null; occupancy_target: string | null; earned: string; booked: string; projection: string; projected_occupancy: number; revenue_progress: number | null }

function Targets({ branchId }: { branchId?: number | null }) {
  const { t } = useTranslation()
  const can = useCan()
  const qc = useQueryClient()
  const [month, setMonth] = useState(today().slice(0, 7))
  const q = useInsight<{ month: string; rows: TargetRow[]; currency: string }>('targets', { month, branch_id: branchId })
  const save = useMutation({
    mutationFn: (v: { branch_id: number; month: string; revenue_target: string | null; occupancy_target: string | null }) => put('/reports/targets', v),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['reports', 'targets'] }),
  })
  const [edit, setEdit] = useState<Record<number, { revenue: string; occupancy: string }>>({})
  const d = q.data?.data

  return (
    <Card className="flex flex-col gap-3">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <h3 className="text-sm font-semibold">{t('insights.targets')}</h3>
        <div className="w-40">
          <Field label={t('insights.month')} type="month" value={month} onChange={(e) => setMonth(e.target.value)} />
        </div>
      </div>
      {save.error instanceof ApiError && <Alert>{Object.values(save.error.errors)[0]?.[0] ?? save.error.message}</Alert>}
      {d?.rows.map((r) => {
        const pct = r.revenue_progress
        const e = edit[r.branch_id]
        return (
          <div key={r.branch_id} className="flex flex-col gap-2 rounded-lg bg-sunken p-3 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="font-semibold">{r.branch}</span>
              <span className="text-muted">{t('insights.projectionLine', { earned: money(r.earned, d.currency), booked: money(r.booked, d.currency), projection: money(r.projection, d.currency) })}</span>
            </div>
            {pct !== null && (
              <div className="flex items-center gap-2">
                <div className="h-2 flex-1 overflow-hidden rounded-full bg-surface border">
                  <div className={cn('h-full rounded-full', pct >= 100 ? 'bg-ok-text' : pct >= 85 ? 'bg-accent' : 'bg-danger')} style={{ width: `${Math.min(100, pct)}%` }} />
                </div>
                <span className={cn('w-14 text-right font-semibold tabular-nums', pct < 85 && 'text-danger-text')}>{pct}%</span>
              </div>
            )}
            <span className="text-xs text-muted">{r.occupancy_target ? t('insights.occupancyLine', { projected: r.projected_occupancy, target: Number(r.occupancy_target) }) : t('insights.occupancyLineNoTarget', { projected: r.projected_occupancy })}</span>
            {can('reports.targets_manage') &&
              (e ? (
                <div className="flex flex-wrap items-end gap-2">
                  <div className="w-40">
                    <Field label={t('insights.revenueTarget')} inputMode="decimal" value={e.revenue} onChange={(ev) => setEdit({ ...edit, [r.branch_id]: { ...e, revenue: ev.target.value.replace(/[^0-9.]/g, '') } })} />
                  </div>
                  <div className="w-32">
                    <Field label={t('insights.occupancyTarget')} inputMode="decimal" value={e.occupancy} onChange={(ev) => setEdit({ ...edit, [r.branch_id]: { ...e, occupancy: ev.target.value.replace(/[^0-9.]/g, '') } })} />
                  </div>
                  <Button
                    disabled={save.isPending}
                    onClick={() =>
                      save.mutate(
                        { branch_id: r.branch_id, month, revenue_target: e.revenue || null, occupancy_target: e.occupancy || null },
                        { onSuccess: () => setEdit(Object.fromEntries(Object.entries(edit).filter(([k]) => Number(k) !== r.branch_id))) },
                      )
                    }
                  >
                    {t('common.save')}
                  </Button>
                </div>
              ) : (
                <div>
                  <Button variant="secondary" onClick={() => setEdit({ ...edit, [r.branch_id]: { revenue: r.revenue_target ? String(Number(r.revenue_target)) : '', occupancy: r.occupancy_target ? String(Number(r.occupancy_target)) : '' } })}>
                    {t('insights.setTarget')}
                  </Button>
                </div>
              ))}
          </div>
        )
      })}
    </Card>
  )
}

/* ------------------------------------------------------------- staff audit */

type AuditRow = { user_id: number; name: string; discounts: string; discount_count: number; waived: string; waived_count: number; voids: number; void_value: string; refunds: string; refund_count: number; cash_short: string; cash_over: string; flags: string[] }

export function AuditReport({ f }: { f: ReportFilters }) {
  const { t } = useTranslation()
  const q = useInsight<{ rows: AuditRow[]; currency: string }>('audit', { from: f.from, to: f.to, branch_id: f.branch_id })
  const [who, setWho] = useState<number | null>(null)
  const d = q.data?.data
  if (!d) return null
  return (
    <div className="flex flex-col gap-4">
      {d.rows.some((r) => r.flags.length) && <Alert>{t('insights.flagged', { count: d.rows.filter((r) => r.flags.length).length })}</Alert>}
      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[820px] text-left text-sm">
          <thead>
            <tr>
              <th className="px-4 h-10 font-medium">{t('cashier.person')}</th>
              <th className="px-4 h-10 text-right font-medium">{t('billing.discounts')}</th>
              <th className="px-4 h-10 text-right font-medium">{t('insights.waivedFees')}</th>
              <th className="px-4 h-10 text-right font-medium">{t('insights.voids')}</th>
              <th className="px-4 h-10 text-right font-medium">{t('insights.refunds')}</th>
              <th className="px-4 h-10 text-right font-medium">{t('insights.cashDiff')}</th>
              <th className="px-4 h-10" />
            </tr>
          </thead>
          <tbody>
            {d.rows.map((r) => (
              <tr key={r.user_id} className={cn('border-t border-border', r.flags.length > 0 && 'bg-danger-bg/30')}>
                <td className="px-4 py-2 font-medium">
                  <span className="flex items-center gap-1.5">
                    {r.flags.length > 0 && <TriangleAlertIcon aria-hidden className="size-4 text-danger-text" />}
                    {r.name}
                  </span>
                  {r.flags.map((fl) => (
                    <Badge key={fl} tone="danger">
                      {t(`insights.flags.${fl}`)}
                    </Badge>
                  ))}
                </td>
                <td className="px-4 py-2 text-right tabular-nums">
                  {money(r.discounts, d.currency)} <span className="text-xs text-muted">({r.discount_count})</span>
                </td>
                <td className="px-4 py-2 text-right tabular-nums">
                  {money(r.waived, d.currency)} <span className="text-xs text-muted">({r.waived_count})</span>
                </td>
                <td className="px-4 py-2 text-right tabular-nums">{r.voids}</td>
                <td className="px-4 py-2 text-right tabular-nums">
                  {money(r.refunds, d.currency)} <span className="text-xs text-muted">({r.refund_count})</span>
                </td>
                <td className={cn('px-4 py-2 text-right tabular-nums', Number(r.cash_short) < 0 && 'text-danger-text')}>{money(r.cash_short, d.currency)}</td>
                <td className="px-4 py-2 text-right">
                  <Button variant="ghost" onClick={() => setWho(who === r.user_id ? null : r.user_id)}>
                    {t('insights.timeline')}
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {d.rows.length === 0 && <p className="p-6 text-center text-muted">{t('insights.cleanAudit')}</p>}
      </Card>
      {who && <Timeline userId={who} f={f} />}
    </div>
  )
}

function Timeline({ userId, f }: { userId: number; f: ReportFilters }) {
  const { t } = useTranslation()
  const q = useQuery({ queryKey: ['reports', 'timeline', userId, f.from, f.to], queryFn: () => api<{ data: { user: { name: string }; rows: { id: number; event: string; description: string; time: string }[] } }>(`/reports/audit/${userId}/timeline?${qs({ from: f.from, to: f.to })}`) })
  return (
    <Card className="flex flex-col gap-2">
      <h3 className="text-sm font-semibold">{t('insights.timelineOf', { name: q.data?.data.user.name ?? '' })}</h3>
      <TimelineList rows={q.data?.data.rows ?? []} />
    </Card>
  )
}

function TimelineList({ rows }: { rows: { id: number; event: string; description: string; time: string }[] }) {
  const { t } = useTranslation()
  if (!rows.length) return <p className="text-sm text-muted">{t('reports.nothing')}</p>
  return (
    <ol className="flex max-h-96 flex-col gap-1 overflow-y-auto text-sm">
      {rows.map((r) => (
        <li key={r.id} className="flex gap-3 border-l-2 border-border py-1 pl-3">
          <span className="w-24 shrink-0 text-xs text-muted tabular-nums">
            {date(localDate(r.time))} {localTime(r.time)}
          </span>
          <span>{r.description}</span>
        </li>
      ))}
    </ol>
  )
}

/* ------------------------------------------------------------- my shift / my work */

type MyShift = {
  from: string
  to: string
  session: { id: number; status: string; expected: Record<string, { opening: string; cash_in: string; cash_out: string; expected: string }> | null; balances: { currency: string; expected_amount: string | null; counted_amount: string | null; difference: string | null }[] } | null
  sessions: { id: number; opened_at: string; closed_at: string | null; status: string }[]
  check_ins: number
  check_outs: number
  walk_ins: number
  bookings: number
  payments: { method: string; currency: string; count: number; amount: string; amount_in_base: string }[]
  payments_total: string
  discounts: string
  max_discount_percent: string
  waived: number
  timeline: { id: number; event: string; description: string; time: string }[]
  currency: string
}

export function MyShiftReport() {
  const { t } = useTranslation()
  const [session, setSession] = useState<number | null>(null)
  const q = useInsight<MyShift>('my-shift', { session })
  const d = q.data?.data
  if (!d) return null
  return (
    <div className="flex flex-col gap-4">
      {d.sessions.length > 0 && (
        <div className="flex flex-wrap gap-1.5 print:hidden">
          {d.sessions.slice(0, 10).map((s) => (
            <button key={s.id} type="button" aria-pressed={(session ?? d.session?.id) === s.id} onClick={() => setSession(s.id)} className={cn('min-h-touch rounded-full border px-3 text-sm shadow-xs transition-colors', (session ?? d.session?.id) === s.id ? 'border-primary bg-primary text-primary-foreground' : 'bg-background text-muted hover:bg-sunken dark:bg-input/30')}>
              {date(localDate(s.opened_at))} {localTime(s.opened_at)}
            </button>
          ))}
        </div>
      )}
      <p className="text-sm text-muted">{t('insights.shiftWindow', { from: `${date(localDate(d.from))} ${localTime(d.from)}`, to: localTime(d.to) })}</p>
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <Tile label={t('insights.checkIns')} value={String(d.check_ins)} />
        <Tile label={t('insights.checkOuts')} value={String(d.check_outs)} />
        <Tile label={t('insights.walkIns')} value={String(d.walk_ins)} />
        <Tile label={t('insights.bookingsMade')} value={String(d.bookings)} />
      </div>
      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="flex flex-col gap-1 text-sm">
          <h4 className="text-sm font-semibold">{t('insights.paymentsTaken')}</h4>
          {d.payments.map((p) => (
            <Row key={`${p.method}${p.currency}`} label={`${p.method} (${p.count})`} value={money(p.amount, p.currency)} />
          ))}
          <Row label={t('reservations.total')} value={money(d.payments_total, d.currency)} />
        </Card>
        <Card className="flex flex-col gap-1 text-sm">
          <h4 className="text-sm font-semibold">{t('insights.myDrawer')}</h4>
          {!d.session && <span className="text-muted">{t('insights.noDrawer')}</span>}
          {d.session?.expected &&
            Object.entries(d.session.expected).map(([c, r]) => <Row key={c} label={t('cashier.inDrawer')} value={money(r.expected, c)} />)}
          {d.session &&
            !d.session.expected &&
            d.session.balances.map((b) => (
              <Row key={b.currency} label={`${t('cashier.counted', { currency: b.currency })} / ${t('insights.expected')}`} value={`${money(b.counted_amount ?? '0', b.currency)} / ${money(b.expected_amount ?? '0', b.currency)}`} />
            ))}
          {d.session && <Row label={t('reservations.status')} value={t(`cashier.statuses.${d.session.status}`)} />}
        </Card>
        <Card className="flex flex-col gap-1 text-sm">
          <h4 className="text-sm font-semibold">{t('insights.discountsAndFees')}</h4>
          <Row label={t('billing.discounts')} value={money(d.discounts, d.currency)} />
          <Row label={t('insights.myLimit')} value={`${Number(d.max_discount_percent)}%`} />
          <Row label={t('insights.waivedFees')} value={d.waived} />
        </Card>
      </div>
      <Card className="flex flex-col gap-2">
        <h4 className="text-sm font-semibold">{t('insights.timeline')}</h4>
        <TimelineList rows={d.timeline} />
      </Card>
    </div>
  )
}

type MyWork = { period: string; cleaned: number; inspections: number; maintenance_reported: number; minutes: number | null; team_minutes: number | null; per_day: { date: string; rooms: number }[]; today: { id: number; room: string; type: string; status: string; started_at: string | null; completed_at: string | null; minutes: number | null }[] }

export function MyWorkReport() {
  const { t } = useTranslation()
  const [period, setPeriod] = useState<'today' | 'week' | 'month'>('today')
  const q = useInsight<MyWork>('my-work', { period })
  const d = q.data?.data
  const max = Math.max(1, ...(d?.per_day.map((x) => x.rooms) ?? [1]))
  return (
    <div className="flex flex-col gap-4">
      <div className="flex gap-1.5">
        {(['today', 'week', 'month'] as const).map((p) => (
          <button key={p} type="button" aria-pressed={period === p} onClick={() => setPeriod(p)} className={cn('min-h-touch rounded-full border px-4 text-sm font-medium shadow-xs transition-colors', period === p ? 'border-primary bg-primary text-primary-foreground' : 'bg-background text-muted hover:bg-sunken dark:bg-input/30')}>
            {t(`insights.periods.${p}`)}
          </button>
        ))}
      </div>
      {d && (
        <>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Tile label={t('insights.roomsCleaned')} value={String(d.cleaned)} />
            <Tile label={t('insights.minutesPerRoom')} value={d.minutes !== null ? String(d.minutes) : '—'} sub={d.team_minutes !== null ? t('insights.teamAverage', { minutes: d.team_minutes }) : undefined} tone={d.minutes !== null && d.team_minutes !== null && d.minutes <= d.team_minutes ? 'ok' : undefined} />
            <Tile label={t('insights.inspections')} value={String(d.inspections)} />
            <Tile label={t('insights.maintenanceReported')} value={String(d.maintenance_reported)} />
          </div>
          <Card className="flex flex-col gap-2">
            <h4 className="text-sm font-semibold">{t('insights.thisWeek')}</h4>
            <div className="flex h-28 items-end gap-2">
              {d.per_day.map((x) => (
                <div key={x.date} className="flex h-full flex-1 flex-col items-center justify-end gap-1">
                  <span className="text-xs tabular-nums">{x.rooms}</span>
                  <span className="w-full rounded-t-md bg-accent/60" style={{ height: `${Math.max(3, (x.rooms / max) * 100)}%` }} />
                  <span className="text-xs text-muted">{t(`common.weekdays.${new Date(`${x.date}T00:00:00`).getDay() || 7}`)}</span>
                </div>
              ))}
            </div>
          </Card>
          <Card className="flex flex-col gap-1 text-sm">
            <h4 className="text-sm font-semibold">{t('insights.todaysRooms')}</h4>
            {d.today.length === 0 && <span className="text-muted">{t('reports.nothing')}</span>}
            {d.today.map((r) => (
              <div key={r.id} className="flex min-h-touch items-center justify-between gap-3 border-b border-border last:border-0">
                <span className="text-base font-semibold">{r.room}</span>
                <span className="text-muted">
                  {t(`housekeeping.types.${r.type}`)} · {r.started_at ? localTime(r.started_at) : '—'}–{r.completed_at ? localTime(r.completed_at) : '…'}
                  {r.minutes !== null && ` · ${t('insights.minutes', { count: r.minutes })}`}
                </span>
                <Badge tone={r.status === 'done' ? 'ok' : r.status === 'in_progress' ? 'info' : 'grey'}>{t(`housekeeping.statuses.${r.status}`)}</Badge>
              </div>
            ))}
          </Card>
        </>
      )}
    </div>
  )
}

/* ------------------------------------------------------------- accounting */

export function AccountingReport({ f }: { f: ReportFilters }) {
  const { t } = useTranslation()
  const q = useInsight<{ accounts: { account: string; debit: string; credit: string }[]; currency: string }>('accounting', { from: f.from, to: f.to, branch_id: f.branch_id })
  const d = q.data?.data
  const params = qs({ from: f.from, to: f.to, branch_id: f.branch_id })
  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap gap-2">
        <a className={buttonClass('primary')} href={apiUrl(`/reports/accounting?${params}&format=xlsx`)} download>
          {t('insights.downloadJournal')}
        </a>
        <a className={buttonClass('ghost')} href={apiUrl(`/reports/accounting?${params}&format=csv`)} download>
          CSV
        </a>
      </div>
      <p className="max-w-prose text-sm text-muted">{t('insights.journalHelp')}</p>
      <Card className="p-0">
        <table className="w-full text-left text-sm">
          <thead>
            <tr>
              <th className="px-4 h-10 font-medium">{t('insights.account')}</th>
              <th className="px-4 h-10 text-right font-medium">{t('insights.debit')}</th>
              <th className="px-4 h-10 text-right font-medium">{t('insights.credit')}</th>
            </tr>
          </thead>
          <tbody>
            {d?.accounts.map((s) => (
              <tr key={s.account} className="border-t border-border">
                <td className="px-4 py-1.5 font-medium">{s.account}</td>
                <td className="px-4 py-1.5 text-right tabular-nums">{money(s.debit, d.currency)}</td>
                <td className="px-4 py-1.5 text-right tabular-nums">{money(s.credit, d.currency)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
    </div>
  )
}
