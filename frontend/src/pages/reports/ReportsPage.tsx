import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { SmartHints } from '@/components/ui/SmartHints'
import { useSmart } from '@/lib/smart'
import { ArrowDownTrayIcon, PrinterIcon } from '@heroicons/react/24/outline'
import { Badge } from '@/components/ui/Badge'
import { Bars, Delta } from '@/components/ui/Delta'
import { buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Select } from '@/components/ui/Select'
import { useMe } from '@/lib/auth'
import { cn } from '@/lib/cn'
import { addMoney } from '@/lib/decimal'
import { date, money } from '@/lib/format'
import { addDays, today } from '@/lib/front'
import { useCan } from '@/lib/hotel'
import { AlertsReport, CustomReportBuilder, SavedViews, SchedulesReport } from './AutomationReports'
import { AccountingReport, AuditReport, DailyReport, ForecastReport, MyShiftReport, MyWorkReport } from './InsightReports'
import { change, reportFileUrl, useDrilldown, useReport, type Kpis, type ReportFilters } from '@/lib/reports'

/** Report key → permissions (any). Keep in step with ReportController::REPORTS. */
const LIBRARY: { key: string; perms: string[]; module?: string }[] = [
  { key: 'my_shift', perms: ['reports.my_shift'] },
  { key: 'my_work', perms: ['reports.my_work'], module: 'housekeeping' },
  { key: 'daily', perms: ['reports.revenue'] },
  { key: 'performance', perms: ['reports.revenue', 'reports.occupancy'] },
  { key: 'forecast', perms: ['reports.forecast', 'reports.targets_manage'] },
  { key: 'payments', perms: ['reports.payments'] },
  { key: 'outstanding', perms: ['reports.outstanding'] },
  { key: 'tax', perms: ['reports.tax'] },
  { key: 'guests', perms: ['reports.guests'] },
  { key: 'cancellations', perms: ['reports.cancellations'] },
  { key: 'branches', perms: ['reports.branch_compare'] },
  { key: 'housekeeping', perms: ['reports.occupancy'] },
  { key: 'audit', perms: ['reports.audit'] },
  { key: 'accounting', perms: ['reports.accounting_export'] },
  { key: 'custom', perms: ['reports.custom'] },
  { key: 'schedules', perms: ['reports.schedule'] },
  { key: 'alerts', perms: ['alerts.manage'] },
]

/** Reports with their own period controls (no shared filter bar / export). */
const OWN_FILTERS = ['my_shift', 'my_work', 'daily', 'forecast', 'outstanding', 'schedules', 'alerts']
const NO_EXPORT = ['my_shift', 'my_work', 'daily', 'forecast', 'audit', 'accounting', 'custom', 'schedules', 'alerts']

function presets(): { key: string; from: string; to: string }[] {
  const t = today()
  const monthStart = `${t.slice(0, 8)}01`
  const lastMonthEnd = addDays(monthStart, -1)
  return [
    { key: 'thisMonth', from: monthStart, to: t },
    { key: 'last7', from: addDays(t, -6), to: t },
    { key: 'last30', from: addDays(t, -29), to: t },
    { key: 'lastMonth', from: `${lastMonthEnd.slice(0, 8)}01`, to: lastMonthEnd },
    { key: 'thisYear', from: `${t.slice(0, 4)}-01-01`, to: t },
  ]
}

/** Reports home and library (reports.md): every report has period, compare, branch, export and print. */
export function ReportsPage() {
  const { t } = useTranslation()
  const can = useCan()
  const me = useMe()
  const branches = me.data?.branches ?? []
  const [params, setParams] = useSearchParams()
  const modules = me.data?.hotel?.modules ?? []
  const allowed = LIBRARY.filter((r) => r.perms.some((p) => can(p)) && (!r.module || modules.includes(r.module)))
  // People who do not work the desk (e.g. the Accountant) start on the daily report, not on "My shift".
  const first = !can('cashier.own_shift') && allowed.some((r) => r.key === 'daily') ? 'daily' : allowed[0]?.key
  const key = allowed.find((r) => r.key === params.get('report'))?.key ?? first
  const [f, setF] = useState<ReportFilters>(() => ({ from: presets()[0].from, to: presets()[0].to, compare: 'prev', branch_id: null }))

  if (!key) {
    return (
      <div className="flex flex-col gap-6">
        <PageHeader title={t('nav.reports')} />
        <Card>{t('reports.noAccess')}</Card>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-6 print:gap-3">
      <PageHeader
        title={t('nav.reports')}
        subtitle={t(allowed.length === 1 && ['my_work', 'my_shift'].includes(key) ? 'reports.subtitleMine' : 'reports.subtitle')}
        actions={
          <div className="flex flex-wrap gap-2 print:hidden">
            <button type="button" className={buttonClass('secondary')} onClick={() => window.print()}>
              <PrinterIcon aria-hidden className="size-4" />
              {t('billing.print')}
            </button>
            {can('reports.export') && !NO_EXPORT.includes(key) && (
              <>
                <a className={buttonClass('secondary')} href={reportFileUrl(key, f, 'xlsx')} download>
                  <ArrowDownTrayIcon aria-hidden className="size-4" />
                  Excel
                </a>
                <a className={buttonClass('ghost')} href={reportFileUrl(key, f, 'csv')} download>
                  CSV
                </a>
              </>
            )}
          </div>
        }
      />
      <div className="flex flex-col gap-6 lg:flex-row">
        <nav aria-label={t('reports.library')} className="lg:w-52 lg:shrink-0 print:hidden">
          <ul className="no-scrollbar flex gap-1 overflow-x-auto lg:flex-col">
            {allowed.map((r) => (
              <li key={r.key}>
                <button
                  type="button"
                  aria-current={key === r.key ? 'page' : undefined}
                  onClick={() => setParams({ report: r.key })}
                  className={cn(
                    'flex min-h-touch w-full items-center rounded-lg px-3 text-left text-[13px] font-medium whitespace-nowrap transition duration-75',
                    key === r.key ? 'bg-surface text-accent-text shadow-sm ring-1 ring-ring' : 'text-muted hover:bg-sunken hover:text-text',
                  )}
                >
                  {t(`reports.names.${r.key}`)}
                </button>
              </li>
            ))}
          </ul>
        </nav>
        <div className="flex min-w-0 flex-1 flex-col gap-4">
          <h2 className="hidden text-lg font-semibold print:block">
            {t(`reports.names.${key}`)} · {date(f.from)} – {date(f.to)}
          </h2>
          {!OWN_FILTERS.includes(key) && <Filters f={f} setF={setF} branches={branches} />}
          {!OWN_FILTERS.includes(key) && key !== 'custom' && <ReportSummary f={f} />}
          {!OWN_FILTERS.includes(key) && key !== 'custom' && <SavedViews key={key} reportKey={key} f={f} apply={setF} />}
          {key === 'custom' && <CustomReportBuilder f={f} />}
          {key === 'schedules' && <SchedulesReport />}
          {key === 'alerts' && <AlertsReport />}
          {key === 'my_shift' && <MyShiftReport />}
          {key === 'my_work' && <MyWorkReport />}
          {key === 'daily' && <DailyReport branchId={f.branch_id} />}
          {key === 'forecast' && <ForecastReport branchId={f.branch_id} />}
          {key === 'audit' && <AuditReport f={f} />}
          {key === 'accounting' && <AccountingReport f={f} />}
          {key === 'performance' && <Performance f={f} />}
          {key === 'payments' && <Payments f={f} />}
          {key === 'outstanding' && <Outstanding f={f} />}
          {key === 'tax' && <Tax f={f} />}
          {key === 'guests' && <Guests f={f} />}
          {key === 'cancellations' && <Cancellations f={f} />}
          {key === 'branches' && <BranchesReport f={f} />}
          {key === 'housekeeping' && <HousekeepingReport f={f} />}
        </div>
      </div>
    </div>
  )
}

function Filters({ f, setF, branches }: { f: ReportFilters; setF: (f: ReportFilters) => void; branches: { id: number; name: string }[] }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-wrap items-end gap-3 print:hidden">
      <div className="flex flex-wrap gap-1.5">
        {presets().map((p) => (
          <button
            key={p.key}
            type="button"
            aria-pressed={f.from === p.from && f.to === p.to}
            onClick={() => setF({ ...f, from: p.from, to: p.to })}
            className={cn('min-h-touch rounded-full px-3 text-[13px] font-medium ring-1 transition duration-75', f.from === p.from && f.to === p.to ? 'bg-accent text-on-accent ring-accent' : 'bg-surface text-muted ring-ring hover:bg-sunken')}
          >
            {t(`reports.presets.${p.key}`)}
          </button>
        ))}
      </div>
      <div className="w-36">
        <Field label={t('settings.rates.from')} type="date" value={f.from} max={f.to} onChange={(e) => setF({ ...f, from: e.target.value })} />
      </div>
      <div className="w-36">
        <Field label={t('settings.rates.to')} type="date" value={f.to} min={f.from} onChange={(e) => setF({ ...f, to: e.target.value })} />
      </div>
      <div className="w-44">
        <Select label={t('reports.compareWith')} value={f.compare ?? 'prev'} onChange={(e) => setF({ ...f, compare: e.target.value as ReportFilters['compare'] })}>
          <option value="prev">{t('reports.compare.prev')}</option>
          <option value="ly">{t('reports.compare.ly')}</option>
          <option value="none">{t('reports.compare.none')}</option>
        </Select>
      </div>
      {branches.length > 1 && (
        <div className="w-44">
          <Select label={t('settings.rooms.branch')} value={String(f.branch_id ?? '')} onChange={(e) => setF({ ...f, branch_id: e.target.value ? Number(e.target.value) : null })}>
            <option value="">{t('reservations.allBranches')}</option>
            {branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </Select>
        </div>
      )}
    </div>
  )
}

/* ------------------------------------------------------------- performance */

const KPI_TILES: { key: keyof Kpis; money?: boolean; pct?: boolean; invert?: boolean }[] = [
  { key: 'occupancy', pct: true },
  { key: 'adr', money: true },
  { key: 'revpar', money: true },
  { key: 'revenue', money: true },
  { key: 'room_nights' },
  { key: 'average_stay' },
  { key: 'cancellation_rate', pct: true, invert: true },
  { key: 'lead_time' },
]

export function KpiGrid({ kpis, compare, currency }: { kpis: Kpis; compare: Kpis | null; currency: string }) {
  const { t } = useTranslation()
  return (
    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      {KPI_TILES.map((k) => {
        const v = kpis[k.key]
        return (
          <Card key={k.key} className="flex flex-col gap-1 p-4">
            <span className="text-[13px] font-medium text-muted">{t(`reports.kpis.${k.key}`)}</span>
            <span className="text-xl font-semibold tabular-nums">{k.money ? money(String(v), currency) : k.pct ? `${v}%` : String(v)}</span>
            {compare && (
              <span className="flex items-center gap-1.5 text-xs text-muted">
                <Delta value={change(v, compare[k.key])} invert={k.invert} />
                {t('reports.was', { value: k.money ? money(String(compare[k.key]), currency) : k.pct ? `${compare[k.key]}%` : String(compare[k.key]) })}
              </span>
            )}
          </Card>
        )
      })}
    </div>
  )
}

type DailyRow = { date: string; room_nights: number; occupancy: number; room_revenue: string; adr: string; revenue: string; payments: string }
type Perf = { kpis: Kpis; compare: Kpis | null; daily: DailyRow[]; room_types: { room_type_id: number; name: string; room_nights: number; occupancy: number; room_revenue: string }[]; sources: { source: string; bookings: number; value: string }[] }
const METRICS = ['occupancy', 'revenue', 'room_nights', 'adr', 'payments'] as const

function Performance({ f }: { f: ReportFilters }) {
  const { t } = useTranslation()
  const q = useReport<Perf>('performance', f)
  const [metric, setMetric] = useState<(typeof METRICS)[number]>('occupancy')
  const [day, setDay] = useState<string | null>(null)
  const d = q.data?.data
  if (!d) return null
  const c = d.currency
  const fmt = (v: number) => (metric === 'occupancy' ? `${v}%` : metric === 'room_nights' ? String(v) : money(v.toFixed(2), c))

  return (
    <div className="flex flex-col gap-4">
      <KpiGrid kpis={d.kpis} compare={d.compare} currency={c} />
      <Card className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h3 className="text-sm font-semibold">{t('reports.dayByDay')}</h3>
          <div className="flex flex-wrap gap-1">
            {METRICS.map((m) => (
              <button key={m} type="button" aria-pressed={metric === m} onClick={() => setMetric(m)} className={cn('min-h-touch rounded-lg px-2.5 text-xs font-medium', metric === m ? 'bg-sunken text-text ring-1 ring-ring' : 'text-muted hover:text-text')}>
                {t(`reports.kpis.${m}`)}
              </button>
            ))}
          </div>
        </div>
        <Bars label={t('reports.dayByDay')} items={d.daily.map((x) => ({ key: x.date, label: date(x.date), value: Number(x[metric]) }))} format={fmt} selected={day} onSelect={(k) => setDay(day === k ? null : k)} />
        <div className="flex justify-between text-[11px] text-muted">
          <span>{date(d.from)}</span>
          <span>{t('reports.tapBar')}</span>
          <span>{date(d.to)}</span>
        </div>
        {day && <Drilldown date={day} metric={metric === 'payments' ? 'payments' : 'room_nights'} branchId={f.branch_id} currency={c} />}
      </Card>
      <div className="grid gap-4 lg:grid-cols-2">
        <Card className="overflow-x-auto p-0">
          <h3 className="px-4 pt-4 text-sm font-semibold">{t('reports.byRoomType')}</h3>
          <table className="mt-2 w-full text-left text-[13px]">
            <thead className="text-muted">
              <tr>
                <th className="px-4 py-1.5 font-medium">{t('settings.rooms.type')}</th>
                <th className="px-4 py-1.5 text-right font-medium">{t('reports.kpis.room_nights')}</th>
                <th className="px-4 py-1.5 text-right font-medium">{t('reports.kpis.occupancy')}</th>
                <th className="px-4 py-1.5 text-right font-medium">{t('reports.kpis.room_revenue')}</th>
              </tr>
            </thead>
            <tbody>
              {d.room_types.map((r) => (
                <tr key={r.room_type_id} className="border-t border-border">
                  <td className="px-4 py-1.5 font-medium">{r.name}</td>
                  <td className="px-4 py-1.5 text-right tabular-nums">{r.room_nights}</td>
                  <td className="px-4 py-1.5 text-right tabular-nums">{r.occupancy}%</td>
                  <td className="px-4 py-1.5 text-right tabular-nums">{money(r.room_revenue, c)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
        <Card className="overflow-x-auto p-0">
          <h3 className="px-4 pt-4 text-sm font-semibold">{t('reports.bySource')}</h3>
          <SimpleTable rows={d.sources.map((s) => [t(`reservations.sources.${s.source}`), String(s.bookings), money(s.value, c)])} head={[t('reservations.form.source'), t('reports.bookings'), t('reports.value')]} />
        </Card>
      </div>
    </div>
  )
}

function Drilldown({ date: day, metric, branchId, currency }: { date: string; metric: 'room_nights' | 'payments'; branchId?: number | null; currency: string }) {
  const { t } = useTranslation()
  const q = useDrilldown(metric, day, branchId)
  return (
    <div className="flex flex-col gap-1 rounded-lg bg-sunken p-3 text-[13px]">
      <span className="text-xs font-semibold text-muted">{t('reports.behind', { date: date(day) })}</span>
      {q.data?.data.length === 0 && <span className="text-muted">{t('reports.nothing')}</span>}
      {q.data?.data.map((r, i) => (
        <Link key={i} to={`/reservations/${r.reservation_id}`} className="flex justify-between gap-2 hover:text-accent-text">
          <span>
            {r.code} · {r.guest} {r.room && `· ${r.room}`} {r.time && `· ${r.time}`} {r.method && `· ${r.method}`}
          </span>
          <span className="tabular-nums">{money(String(r.amount), currency)}</span>
        </Link>
      ))}
    </div>
  )
}

function SimpleTable({ head, rows, right = true }: { head: string[]; rows: (string | number)[][]; right?: boolean }) {
  const { t } = useTranslation()
  return (
    <table className="mt-2 w-full text-left text-[13px]">
      <thead className="text-muted">
        <tr>
          {head.map((h, i) => (
            <th key={h} className={cn('px-4 py-1.5 font-medium', right && i > 0 && 'text-right')}>
              {h}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {rows.map((r, i) => (
          <tr key={i} className="border-t border-border">
            {r.map((c, j) => (
              <td key={j} className={cn('px-4 py-1.5', j === 0 && 'font-medium', right && j > 0 && 'text-right tabular-nums')}>
                {c}
              </td>
            ))}
          </tr>
        ))}
        {rows.length === 0 && (
          <tr>
            <td colSpan={head.length} className="px-4 py-4 text-center text-muted">
              {t('reports.nothing')}
            </td>
          </tr>
        )}
      </tbody>
    </table>
  )
}

/* ------------------------------------------------------------- other reports */

function Payments({ f }: { f: ReportFilters }) {
  const { t } = useTranslation()
  const q = useReport<{ by_method: { method: string; method_type: string; currency: string; count: number; amount: string; amount_in_base: string }[]; by_staff: { name: string; count: number; amount: string }[]; total: string }>('payments', f)
  const d = q.data?.data
  if (!d) return null
  return (
    <div className="flex flex-col gap-4">
      <Card className="flex items-baseline justify-between">
        <span className="text-[13px] text-muted">{t('reports.received')}</span>
        <span className="text-xl font-semibold tabular-nums">{money(d.total, d.currency)}</span>
      </Card>
      <div className="grid gap-4 lg:grid-cols-2">
        <Card className="p-0">
          <h3 className="px-4 pt-4 text-sm font-semibold">{t('reports.byMethod')}</h3>
          <SimpleTable head={[t('reservations.method'), t('reports.count'), t('reservations.amount')]} rows={d.by_method.map((r) => [`${r.method} (${r.currency})`, r.count, money(r.amount, r.currency)])} />
        </Card>
        <Card className="p-0">
          <h3 className="px-4 pt-4 text-sm font-semibold">{t('reports.byStaff')}</h3>
          <SimpleTable head={[t('cashier.person'), t('reports.count'), t('reservations.amount')]} rows={d.by_staff.map((r) => [r.name, r.count, money(r.amount, d.currency)])} />
        </Card>
      </div>
    </div>
  )
}

function Outstanding({ f }: { f: ReportFilters }) {
  const { t } = useTranslation()
  const q = useReport<{ rows: { id: number; number: string; guest: string; phone: string | null; reservation: string | null; issued_at: string; age_days: number; balance: string; currency: string; status: string }[] }>('outstanding', f)
  const rows = q.data?.data.rows ?? []
  const total = addMoney(...rows.map((r) => r.balance)) // exact sum, no float maths (CLAUDE.md)
  return (
    <Card className="overflow-x-auto p-0">
      <div className="flex items-baseline justify-between px-4 pt-4">
        <h3 className="text-sm font-semibold">{t('reports.unpaidBills', { count: rows.length })}</h3>
        <span className="font-semibold tabular-nums text-danger-text">{money(total, q.data?.data.currency)}</span>
      </div>
      <table className="mt-2 w-full min-w-[640px] text-left text-[13px]">
        <tbody>
          {rows.map((r) => (
            <tr key={r.id} className="border-t border-border">
              <td className="px-4 py-2">
                <Link to={`/billing/${r.id}`} className="font-medium text-accent-text">
                  {r.number}
                </Link>
                <span className="block text-xs text-muted">{r.reservation}</span>
              </td>
              <td className="px-4 py-2">
                {r.guest}
                <span className="block text-xs text-muted">{r.phone}</span>
              </td>
              <td className="px-4 py-2 text-muted">
                {date(r.issued_at)} · {t('reports.daysOld', { count: r.age_days })}
              </td>
              <td className="px-4 py-2">{r.status === 'outstanding' && <Badge tone="danger">{t('billing.statuses.outstanding')}</Badge>}</td>
              <td className="px-4 py-2 text-right font-semibold tabular-nums">{money(r.balance, r.currency)}</td>
            </tr>
          ))}
        </tbody>
      </table>
      {rows.length === 0 && <p className="p-6 text-center text-muted">{t('reports.allPaid')}</p>}
    </Card>
  )
}

function Tax({ f }: { f: ReportFilters }) {
  const { t } = useTranslation()
  const q = useReport<{ rows: { name: string; rate: string; is_inclusive: boolean; base: string; tax: string; invoices: number }[] }>('tax', f)
  const d = q.data?.data
  if (!d) return null
  return (
    <Card className="p-0">
      <h3 className="px-4 pt-4 text-sm font-semibold">{t('reports.taxForFiling')}</h3>
      <SimpleTable
        head={[t('settings.taxes.name'), t('reports.base'), t('reports.taxAmount'), t('reports.invoiceCount')]}
        rows={d.rows.map((r) => [`${r.name} ${Number(r.rate)}%${r.is_inclusive ? ` (${t('settings.taxes.inPrice')})` : ''}`, money(r.base, d.currency), money(r.tax, d.currency), r.invoices])}
      />
    </Card>
  )
}

function Guests({ f }: { f: ReportFilters }) {
  const { t } = useTranslation()
  const q = useReport<{ guests: number; repeat: number; nationalities: { nationality: string; guests: number }[]; top: { id: number; name: string; bills: number; spent: string }[]; sources: { source: string; bookings: number; value: string }[] }>('guests', f)
  const d = q.data?.data
  if (!d) return null
  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-3 sm:grid-cols-2">
        <Card className="flex flex-col gap-1 p-4">
          <span className="text-[13px] text-muted">{t('reports.guestsStayed')}</span>
          <span className="text-xl font-semibold">{d.guests}</span>
        </Card>
        <Card className="flex flex-col gap-1 p-4">
          <span className="text-[13px] text-muted">{t('reports.repeatGuests')}</span>
          <span className="text-xl font-semibold">{d.repeat}</span>
        </Card>
      </div>
      <div className="grid gap-4 lg:grid-cols-2">
        <Card className="p-0">
          <h3 className="px-4 pt-4 text-sm font-semibold">{t('reports.nationalities')}</h3>
          <SimpleTable head={[t('guests.nationality'), t('nav.guests')]} rows={d.nationalities.map((n) => [n.nationality, n.guests])} />
        </Card>
        <Card className="p-0">
          <h3 className="px-4 pt-4 text-sm font-semibold">{t('reports.topGuests')}</h3>
          <SimpleTable head={[t('guests.name'), t('reports.bills'), t('reports.spent')]} rows={d.top.map((g) => [g.name, g.bills, money(g.spent, d.currency)])} />
        </Card>
      </div>
    </div>
  )
}

function Cancellations({ f }: { f: ReportFilters }) {
  const { t } = useTranslation()
  const q = useReport<{ cancelled: number; expired: number; no_show: number; fees: string; lost_value: string; rows: { id: number; code: string; status: string; reason: string | null; guest: string; fee: string; total: string; cancelled_on: string; check_in: string }[] }>('cancellations', f)
  const d = q.data?.data
  if (!d) return null
  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {(
          [
            ['cancelled', String(d.cancelled)],
            ['expired', String(d.expired)],
            ['no_show', String(d.no_show)],
            ['fees', money(d.fees, d.currency)],
          ] as const
        ).map(([k, v]) => (
          <Card key={k} className="flex flex-col gap-1 p-4">
            <span className="text-[13px] text-muted">{t(`reports.cancel.${k}`)}</span>
            <span className="text-xl font-semibold tabular-nums">{v}</span>
          </Card>
        ))}
      </div>
      <Card className="p-0">
        <SimpleTable
          right={false}
          head={[t('reservations.code'), t('reservations.guest'), t('reservations.status'), t('reservations.reason'), t('reservations.form.checkIn'), t('reservations.cancellationFee')]}
          rows={d.rows.map((r) => [r.code, r.guest, t(`reservations.statuses.${r.status}`), r.reason === 'expired' ? t('reservations.expiredReason') : (r.reason ?? ''), date(r.check_in), money(r.fee, d.currency)])}
        />
      </Card>
    </div>
  )
}

function BranchesReport({ f }: { f: ReportFilters }) {
  const { t } = useTranslation()
  const q = useReport<{ rows: (Kpis & { branch: string })[] }>('branches', f)
  const d = q.data?.data
  if (!d) return null
  return (
    <Card className="overflow-x-auto p-0">
      <SimpleTable
        head={[t('settings.rooms.branch'), t('reports.kpis.occupancy'), t('reports.kpis.adr'), t('reports.kpis.revpar'), t('reports.kpis.revenue'), t('reports.kpis.payments')]}
        rows={d.rows.map((r) => [r.branch, `${r.occupancy}%`, money(r.adr, d.currency), money(r.revpar, d.currency), money(r.revenue, d.currency), money(r.payments, d.currency)])}
      />
    </Card>
  )
}

function HousekeepingReport({ f }: { f: ReportFilters }) {
  const { t } = useTranslation()
  const q = useReport<{ rows: { user_id: number | null; name: string; type: string; tasks: number; minutes: number | null }[] }>('housekeeping', f)
  const d = q.data?.data
  if (!d) return null
  return (
    <Card className="p-0">
      <SimpleTable head={[t('cashier.person'), t('housekeeping.type'), t('reports.tasksDone'), t('reports.minutesEach')]} rows={d.rows.map((r) => [r.name, t(`housekeeping.types.${r.type}`), r.tasks, r.minutes ?? '—'])} />
    </Card>
  )
}

/** ✨ One line at the top of a report: how the period went against the one before. */
function ReportSummary({ f }: { f: ReportFilters }) {
  const can = useCan()
  const smart = useSmart('report', { from: f.from, to: f.to, branch_id: f.branch_id }, can(['reports.revenue', 'reports.occupancy']))
  return <SmartHints hints={smart.data?.data} className="print:hidden" />
}
