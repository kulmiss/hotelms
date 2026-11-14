import { useState, type ReactNode } from 'react'
import { Link } from 'react-router'
import { useIsFetching, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { CircleCheck, CircleX, PlusIcon, RefreshCw } from 'lucide-react'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Donut } from '@/components/charts/charts'
import { KpiCard } from '@/components/charts/KpiCard'
import { BarList, ChartCard, KpiGrid, Legend, SERIES, WidgetBoundary, useReducedMotion, widgetState } from '@/components/charts/kit'
import { Badge } from '@/components/ui/Badge'
import { Button, buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { PageHeader } from '@/components/ui/PageHeader'
import { SmartHints } from '@/components/ui/SmartHints'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { useAdminDashboard, useHotelHealth, usePlatformReport, type HotelHealth } from '@/lib/admin'
import { api } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { cn } from '@/lib/cn'
import { date, dateTime, money } from '@/lib/format'
import { useWatchdog, type SupportSummary, type SystemHealth } from '@/lib/platform'
import { useSmart } from '@/lib/smart'
import { SideSheet } from '@/pages/dashboard/Sheets'

const PERIODS = ['1', '3', '12'] as const
type Period = (typeof PERIODS)[number]
const STATUS_COLORS: Record<string, string> = { trial: 'var(--chart-2)', active: 'var(--chart-1)', grace: 'var(--chart-3)', suspended: 'var(--danger)' }
const HEALTH_TONE: Record<string, 'ok' | 'warn' | 'danger' | 'grey'> = { healthy: 'ok', watch: 'warn', at_risk: 'danger', stuck: 'grey' }

/** "2026-11" → "Nov" (chart labels). */
const monthName = (m: string) => new Date(`${m}-01T12:00:00Z`).toLocaleString('en-GB', { month: 'short', timeZone: 'UTC' })

/** Super Admin command center (P18): hotels, money, sign-ups, health, support and system on one page. Demo hotels are left out. */
export function AdminDashboardPage() {
  const { t } = useTranslation()
  const me = useMe()
  const perms = me.data?.platform?.permissions ?? []
  const has = (p: string) => perms.includes(p)
  const qc = useQueryClient()
  const fetching = useIsFetching({ queryKey: ['admin'] }) > 0
  const dash = useAdminDashboard()
  const report = usePlatformReport()
  const health = useHotelHealth()
  const smart = useSmart('admin')
  const watch = useWatchdog('open', has('watchdog.view'))
  const system = useQuery({ queryKey: ['admin', 'system'], queryFn: () => api<{ data: SystemHealth }>('/admin/system'), refetchInterval: 60_000, enabled: has('system.view') })
  const support = useQuery({ queryKey: ['admin', 'support', { status: 'open' }], queryFn: () => api<{ data: SupportSummary[] }>('/admin/support?status=open'), refetchInterval: 60_000, enabled: has('support.answer') })
  const [period, setPeriod] = useState<Period>('12')
  const [hotel, setHotel] = useState<HotelHealth | null>(null)
  const d = dash.data
  const r = report.data?.data
  const serious = watch.data?.counts.critical ?? 0
  const months = (r?.income ?? []).slice(-Number(period))
  const income = d ? Object.entries(d.income_this_month) : []
  const signups = (r?.signups ?? []).slice(-Number(period))
  const churned = (r?.churned ?? []).slice(-Number(period))
  const healthRows = health.data?.data ?? []
  const counts = Array.isArray(health.data?.counts) ? {} : (health.data?.counts ?? {})

  return (
    <div className="mx-auto flex w-full max-w-[1440px] flex-col gap-4">
      <PageHeader
        title={t('nav.commandCenter')}
        subtitle={t('admin.dash.subtitle')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <div role="group" aria-label={t('dash.period')} className="flex h-9 overflow-hidden rounded-md border border-border pointer-coarse:h-auto">
              {PERIODS.map((p) => (
                <button key={p} type="button" aria-pressed={period === p} onClick={() => setPeriod(p)} className={cn('px-3 text-sm font-medium transition-colors duration-150 pointer-coarse:min-h-11', period === p ? 'bg-primary text-primary-foreground' : 'hover:bg-sunken')}>
                  {t(`admin.cc.periods.${p}`)}
                </button>
              ))}
            </div>
            <Button variant="secondary" aria-label={t('dash.refresh')} title={t('dash.refresh')} onClick={() => void qc.invalidateQueries({ queryKey: ['admin'] })}>
              <RefreshCw aria-hidden className={cn(fetching && 'animate-spin motion-reduce:animate-none')} />
            </Button>
            <Link to="/admin/hotels/new" className={buttonClass('primary')}>
              <PlusIcon aria-hidden className="size-4" />
              {t('admin.hotels.new')}
            </Link>
          </div>
        }
      />

      {serious > 0 && (
        <Link to="/admin/watchdog" className="rounded-lg border border-danger-text/20 bg-danger-bg px-4 py-3 text-sm font-medium text-danger-text hover:brightness-95">
          {t('watchdog.alert', { count: serious })}
        </Link>
      )}
      <SmartHints hints={smart.data?.data} />

      <KpiGrid>
        <KpiCard loading={dash.isPending} label={t('admin.dash.totalHotels')} value={String(d?.hotels.total ?? '—')} sub={d ? t('admin.cc.hotelsSub', { trial: d.hotels.by_status.trial ?? 0, active: d.hotels.by_status.active ?? 0 }) : undefined} />
        <KpiCard
          loading={dash.isPending}
          label={t('admin.dash.incomeThisMonth')}
          value={income.length ? income.map(([cur, total]) => money(total, cur)).join(' · ') : '—'}
          sub={income.length ? undefined : t('admin.cc.noIncome')}
        />
        <KpiCard loading={report.isPending} label={t('admin.reports.newThisMonth')} value={String(r?.new_this_month ?? '—')} />
        <KpiCard loading={report.isPending} label={t('admin.reports.lost')} value={String(r?.lost ?? '—')} sub={t('admin.cc.lostSub')} />
        {d?.today && <KpiCard label={t('admin.dash.openSupport')} value={String(d.today.open_support)} />}
        {d?.today && <KpiCard label={t('admin.dash.staffOnline')} value={String(d.today.staff_online)} sub={t('admin.cc.last15')} />}
        {d?.today && <KpiCard label={t('admin.dash.bookingsToday')} value={String(d.today.bookings)} />}
        {d?.today && (
          <KpiCard
            label={t('admin.dash.moneyToday')}
            value={Object.entries(d.today.money).length ? Object.entries(d.today.money).map(([cur, total]) => money(total, cur)).join(' · ') : money('0', 'ETB')}
            sub={t('admin.dash.moneyTodayHint')}
          />
        )}
        {d?.today && <KpiCard label={t('admin.dash.occupancyTonight')} value={`${d.today.occupancy}%`} sub={t('hotelTabs.roomsLine', { occupied: d.today.occupied, rooms: d.today.rooms })} />}
      </KpiGrid>

      {d && d.hotels.total === 0 && (
        <Card className="flex flex-col items-start gap-3 p-8">
          <h2 className="text-lg font-semibold">{t('admin.dash.emptyTitle')}</h2>
          <p className="max-w-prose text-muted">{t('admin.dash.emptyBody')}</p>
          <Link to="/admin/hotels/new" className={buttonClass('primary', 'md', 'mt-2')}>
            <PlusIcon aria-hidden className="size-4" />
            {t('admin.dash.createFirst')}
          </Link>
        </Card>
      )}

      <div className="flex flex-wrap gap-4">
        <WidgetBoundary title={t('admin.reports.income')} className="min-w-0 flex-[2_1_560px]">
          <ChartCard
            className="min-w-0 flex-[2_1_560px]"
            title={t('admin.reports.income')}
            description={t('admin.cc.incomeHelp')}
            state={widgetState(report, months.some((m) => m.by_plan.length > 0))}
            onRetry={() => report.refetch()}
            emptyText={t('admin.cc.noIncomeYet')}
          >
            <IncomeChart months={months} />
          </ChartCard>
        </WidgetBoundary>
        <WidgetBoundary title={t('admin.reports.byStatus')} className="min-w-0 flex-[1_1_300px]">
          <ChartCard className="min-w-0 flex-[1_1_300px]" title={t('admin.reports.byStatus')} state={widgetState(dash, (d?.hotels.total ?? 0) > 0)} onRetry={() => dash.refetch()}>
            {d && (
              <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-center gap-4">
                  <Donut
                    size={120}
                    label={Object.entries(d.hotels.by_status).map(([s, n]) => `${t(`hotelStatus.${s}`)} ${n}`).join(', ')}
                    slices={Object.entries(d.hotels.by_status).map(([s, n]) => ({ key: s, label: t(`hotelStatus.${s}`), value: n, color: STATUS_COLORS[s] ?? SERIES[4] }))}
                    center={
                      <>
                        <span className="text-xl font-bold">{d.hotels.total}</span>
                        <span className="text-[11px] text-muted">{t('admin.cc.hotels', { count: d.hotels.total })}</span>
                      </>
                    }
                  />
                  <Legend items={Object.entries(d.hotels.by_status).map(([s, n]) => ({ key: s, label: t(`hotelStatus.${s}`), value: String(n), color: STATUS_COLORS[s] ?? SERIES[4] }))} />
                </div>
                {(r?.by_plan?.length ?? 0) > 0 && (
                  <div className="flex flex-col gap-2 border-t border-border pt-3">
                    <h3 className="text-sm font-semibold">{t('admin.cc.byPlan')}</h3>
                    <BarList items={(r?.by_plan ?? []).map((p) => ({ key: p.plan, label: p.plan, value: p.hotels, display: String(p.hotels) }))} />
                  </div>
                )}
              </div>
            )}
          </ChartCard>
        </WidgetBoundary>
      </div>

      <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,360px),1fr))] gap-4">
        <WidgetBoundary title={t('admin.cc.signups')}>
          <ChartCard title={t('admin.cc.signups')} state={widgetState(report, signups.length > 0)} onRetry={() => report.refetch()}>
            <SignupsChart signups={signups} churned={churned} />
          </ChartCard>
        </WidgetBoundary>

        <WidgetBoundary title={t('admin.reports.health')}>
          <ChartCard
            title={t('admin.reports.health')}
            action={
              <Link to="/admin/reports" className="tap-link text-sm font-medium text-accent-text">
                {t('dash.viewAll')} →
              </Link>
            }
            state={widgetState(health, true)}
            onRetry={() => health.refetch()}
            bodyClassName="p-0"
          >
            <div className="grid grid-cols-4 gap-2 px-4 pb-3">
              {(['healthy', 'watch', 'at_risk', 'stuck'] as const).map((h) => (
                <div key={h} className="flex flex-col rounded-lg border border-border p-2">
                  <span className="text-xs text-muted">{t(`admin.reports.healthStates.${h}`)}</span>
                  <span className={cn('text-lg font-bold tabular-nums', h === 'healthy' && 'text-ok-text', h === 'watch' && 'text-warn-text', h === 'at_risk' && 'text-danger-text')}>{(counts as Record<string, number>)[h] ?? 0}</span>
                </div>
              ))}
            </div>
            <ul className="flex flex-col">
              {healthRows
                .filter((h) => h.health !== 'healthy')
                .slice(0, 5)
                .map((h) => (
                  <li key={h.id}>
                    <button type="button" onClick={() => setHotel(h)} className="flex min-h-12 w-full items-center gap-3 border-t border-border px-4 py-2 text-left text-sm hover:bg-sunken/40">
                      <span className="flex min-w-0 flex-1 flex-col">
                        <span className="font-medium">{h.name}</span>
                        <span className="truncate text-xs text-muted">{[t(`hotelStatus.${h.status}`), h.plan, t('admin.cc.setup', { percent: h.setup_percent })].filter(Boolean).join(' · ')}</span>
                      </span>
                      <Badge tone={HEALTH_TONE[h.health]}>{t(`admin.reports.healthStates.${h.health}`)}</Badge>
                    </button>
                  </li>
                ))}
            </ul>
          </ChartCard>
        </WidgetBoundary>

        {has('system.view') && (
          <WidgetBoundary title={t('admin.cc.system')}>
            <ChartCard
              title={t('admin.cc.system')}
              action={system.data && (Object.values(system.data.data.checks).every((c) => c.ok) ? <Badge tone="ok">{t('admin.cc.allOk')}</Badge> : <Badge tone="danger">{t('admin.cc.problem')}</Badge>)}
              state={widgetState(system, true)}
              onRetry={() => system.refetch()}
              footer={
                <Link to="/admin/system" className="tap-link font-medium text-accent-text">
                  {t('dash.viewAll')} →
                </Link>
              }
            >
              <ul className="grid grid-cols-2 gap-2 text-sm">
                {Object.entries(system.data?.data.checks ?? {}).map(([k, c]) => (
                  <li key={k} className="flex items-center gap-2 rounded-lg border border-border p-2">
                    {c.ok ? <CircleCheck aria-hidden className="size-4 text-ok-text" /> : <CircleX aria-hidden className="size-4 text-danger-text" />}
                    <span className="flex-1">{t(`system.checks.${k}`, { defaultValue: k })}</span>
                    <span className="sr-only">{c.ok ? t('admin.cc.allOk') : t('admin.cc.problem')}</span>
                  </li>
                ))}
              </ul>
            </ChartCard>
          </WidgetBoundary>
        )}
      </div>

      <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,360px),1fr))] gap-4">
        {has('support.answer') && (
          <WidgetBoundary title={t('admin.cc.support')}>
            <ChartCard
              title={t('admin.cc.support')}
              action={
                <Link to="/admin/support" className="tap-link text-sm font-medium text-accent-text">
                  {t('admin.cc.inbox')} →
                </Link>
              }
              state={widgetState(support, (support.data?.data.length ?? 0) > 0)}
              onRetry={() => support.refetch()}
              emptyText={t('admin.cc.noTickets')}
              bodyClassName="p-0"
            >
              <ul className="flex flex-col">
                {(support.data?.data ?? []).slice(0, 6).map((s) => (
                  <li key={s.id}>
                    <Link to="/admin/support" className="flex min-h-12 items-center gap-3 border-t border-border px-4 py-2 text-sm hover:bg-sunken/40">
                      <span className="flex min-w-0 flex-1 flex-col">
                        <span className="truncate font-medium">{s.subject}</span>
                        <span className="truncate text-xs text-muted">{s.from}</span>
                      </span>
                      {s.priority === 'urgent' && <Badge tone="danger">{t('admin.cc.urgent')}</Badge>}
                    </Link>
                  </li>
                ))}
              </ul>
            </ChartCard>
          </WidgetBoundary>
        )}
        {(d?.pending_signups ?? 0) > 0 && (
          <Card className="flex flex-wrap items-center justify-between gap-3">
            <span className="text-sm font-medium">{t('admin.dash.pendingSignups', { count: d?.pending_signups ?? 0 })}</span>
            <Link to="/admin/signups" className={buttonClass('secondary')}>{t('nav.signups')}</Link>
          </Card>
        )}
        {(d?.inactive_free?.length ?? 0) > 0 && (
          <HotelList
            title={t('admin.dash.inactiveFree')}
            empty={t('admin.dash.none')}
            items={(d?.inactive_free ?? []).map((h) => ({ id: h.id, name: h.name, right: <span className="text-sm text-muted">{t('admin.dash.lastUsed', { date: date(h.inactive_since) })}</span> }))}
          />
        )}
        <HotelList
          title={t('admin.dash.trialsEnding')}
          empty={t('admin.dash.none')}
          items={(d?.trials_ending_soon ?? []).map((h) => ({ id: h.id, name: h.name, right: <span className="text-sm text-muted">{date(h.trial_ends_at)}</span> }))}
        />
        <HotelList
          title={t('admin.dash.needsAttention')}
          empty={t('admin.dash.allGood')}
          items={(d?.needs_attention ?? []).map((h) => ({ id: h.id, name: h.name, right: <StatusBadge status={h.status} /> }))}
        />
        <HotelList
          title={t('admin.dash.topHotels')}
          empty={t('admin.dash.none')}
          items={(d?.top_hotels ?? []).map((h) => ({ id: h.id, name: h.name, right: <span className="text-sm text-muted">{money(h.total, h.currency)}</span> }))}
        />
        <WidgetBoundary title={t('admin.dash.liveActivity')}>
          <ChartCard title={t('admin.dash.liveActivity')} state={widgetState(dash, (d?.activity?.length ?? 0) > 0)} onRetry={() => dash.refetch()} emptyText={t('admin.dash.none')} bodyClassName="p-0">
            <ul className="flex flex-col text-sm">
              {(d?.activity ?? []).slice(0, 8).map((a) => (
                <li key={a.id} className="flex flex-col border-t border-border px-4 py-2">
                  <span className="line-clamp-2">{a.description}</span>
                  <span className="text-xs text-muted">
                    {a.hotel?.name}
                    {a.user && ` · ${a.user}`} · {dateTime(a.at)}
                  </span>
                </li>
              ))}
            </ul>
          </ChartCard>
        </WidgetBoundary>
      </div>

      <SideSheet open={hotel !== null} onClose={() => setHotel(null)} title={hotel?.name ?? ''} description={hotel ? `${t(`hotelStatus.${hotel.status}`)}${hotel.plan ? ` · ${hotel.plan}` : ''}` : undefined}>
        {hotel && (
          <>
            <dl className="grid grid-cols-2 gap-3 rounded-lg border border-border p-3 text-sm">
              <div className="flex flex-col">
                <dt className="text-xs text-muted">{t('admin.cc.healthLabel')}</dt>
                <dd>
                  <Badge tone={HEALTH_TONE[hotel.health]}>{t(`admin.reports.healthStates.${hotel.health}`)}</Badge>
                </dd>
              </div>
              <div className="flex flex-col">
                <dt className="text-xs text-muted">{t('admin.cc.setupLabel')}</dt>
                <dd>{hotel.setup_percent}%</dd>
              </div>
              <div className="flex flex-col">
                <dt className="text-xs text-muted">{t('admin.cc.lastActivity')}</dt>
                <dd>{hotel.last_activity ? dateTime(hotel.last_activity) : '—'}</dd>
              </div>
              <div className="flex flex-col">
                <dt className="text-xs text-muted">{t('admin.cc.bookings30')}</dt>
                <dd className="tabular-nums">{hotel.bookings_30_days}</dd>
              </div>
            </dl>
            <Link to={`/admin/hotels/${hotel.id}`} className="tap-link w-fit font-medium text-accent-text">
              {t('admin.cc.openHotel')} →
            </Link>
          </>
        )}
      </SideSheet>
    </div>
  )
}

/** Subscription income per month, one bar part per plan (main currency of each payment kept apart). */
function IncomeChart({ months }: { months: { month: string; by_plan: { plan: string; currency: string; total: string }[] }[] }) {
  const { t } = useTranslation()
  const reduced = useReducedMotion()
  const plans = [...new Set(months.flatMap((m) => m.by_plan.map((p) => `${p.plan} (${p.currency})`)))]
  const data = months.map((m) => ({ month: m.month, ...Object.fromEntries(m.by_plan.map((p) => [`${p.plan} (${p.currency})`, Number(p.total)])) }))
  return (
    <div role="img" aria-label={t('admin.cc.incomeAria', { months: months.length })} className="h-60 w-full">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={data} margin={{ top: 8, right: 4, bottom: 0, left: 4 }}>
          <CartesianGrid vertical={false} stroke="var(--chart-grid)" />
          <XAxis dataKey="month" tickFormatter={monthName} tick={{ fontSize: 11, fill: 'var(--muted)' }} tickLine={false} axisLine={false} />
          <YAxis tick={{ fontSize: 11, fill: 'var(--muted)' }} tickLine={false} axisLine={false} width={56} />
          <Tooltip cursor={{ fill: 'var(--sunken)', opacity: 0.6 }} contentStyle={{ background: 'var(--surface)', border: '1px solid var(--border)', borderRadius: 8, fontSize: 12 }} labelFormatter={(m) => monthName(String(m))} />
          {plans.map((p, i) => (
            <Bar key={p} dataKey={p} stackId="plans" fill={SERIES[i % SERIES.length]} isAnimationActive={!reduced} animationDuration={200} radius={i === plans.length - 1 ? [3, 3, 0, 0] : 0} />
          ))}
        </BarChart>
      </ResponsiveContainer>
    </div>
  )
}

/** New hotels (brand) and lost hotels (red) per month. */
function SignupsChart({ signups, churned }: { signups: { month: string; count: number }[]; churned: { month: string; count: number }[] }) {
  const { t } = useTranslation()
  const reduced = useReducedMotion()
  const data = signups.map((s, i) => ({ month: s.month, new: s.count, lost: churned[i]?.count ?? 0 }))
  return (
    <>
      <div role="img" aria-label={t('admin.cc.signupsAria', { months: data.length })} className="h-44 w-full">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={data} margin={{ top: 8, right: 0, bottom: 0, left: 0 }}>
            <CartesianGrid vertical={false} stroke="var(--chart-grid)" />
            <XAxis dataKey="month" tickFormatter={monthName} tick={{ fontSize: 11, fill: 'var(--muted)' }} tickLine={false} axisLine={false} />
            <YAxis allowDecimals={false} tick={{ fontSize: 11, fill: 'var(--muted)' }} tickLine={false} axisLine={false} width={28} />
            <Tooltip cursor={{ fill: 'var(--sunken)', opacity: 0.6 }} contentStyle={{ background: 'var(--surface)', border: '1px solid var(--border)', borderRadius: 8, fontSize: 12 }} labelFormatter={(m) => monthName(String(m))} />
            <Bar dataKey="new" name={t('admin.cc.new')} fill="var(--chart-1)" radius={[3, 3, 0, 0]} isAnimationActive={!reduced} animationDuration={200} />
            <Bar dataKey="lost" name={t('admin.cc.lostShort')} fill="var(--danger)" radius={[3, 3, 0, 0]} isAnimationActive={!reduced} animationDuration={200} />
          </BarChart>
        </ResponsiveContainer>
      </div>
      <div className="mt-2 flex gap-4 text-xs text-muted">
        <span className="flex items-center gap-1.5">
          <span aria-hidden className="size-2.5 rounded-[3px] bg-chart-1" />
          {t('admin.cc.new')}
        </span>
        <span className="flex items-center gap-1.5">
          <span aria-hidden className="size-2.5 rounded-[3px] bg-danger" />
          {t('admin.cc.lostShort')}
        </span>
      </div>
    </>
  )
}

function HotelList({ title, empty, items }: { title: string; empty: string; items: { id: number; name: string; right: ReactNode }[] }) {
  return (
    <ChartCard title={title} state={items.length ? 'ready' : 'empty'} emptyText={empty} bodyClassName="p-0">
      <ul className="flex flex-col">
        {items.map((h) => (
          <li key={h.id}>
            <Link to={`/admin/hotels/${h.id}`} className="flex min-h-12 items-center justify-between gap-2 border-t border-border px-4 transition-colors duration-150 hover:bg-sunken/40">
              <span className="truncate font-medium">{h.name}</span>
              {h.right}
            </Link>
          </li>
        ))}
      </ul>
    </ChartCard>
  )
}
