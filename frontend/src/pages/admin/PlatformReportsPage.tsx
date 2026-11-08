import { useState } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { Badge, type Tone } from '@/components/ui/Badge'
import { Card } from '@/components/ui/Card'
import { Bars } from '@/components/ui/Delta'
import { PageHeader } from '@/components/ui/PageHeader'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { useHotelHealth, usePlatformReport, type HotelHealth } from '@/lib/admin'
import { cn } from '@/lib/cn'
import { date, money } from '@/lib/format'

const HEALTH: HotelHealth['health'][] = ['healthy', 'watch', 'at_risk', 'stuck']
const TONE: Record<HotelHealth['health'], Tone> = { healthy: 'ok', watch: 'warn', at_risk: 'danger', stuck: 'info' }

/** Super Admin: income by month, hotel counts and the health of every customer hotel. */
export function PlatformReportsPage() {
  const { t } = useTranslation()
  const p = usePlatformReport().data?.data
  const h = useHotelHealth().data
  const [filter, setFilter] = useState<HotelHealth['health'] | null>(null)
  const currencies = [...new Set(p?.income.flatMap((m) => Object.keys(m.totals)) ?? [])]
  const [currency, setCurrency] = useState<string | null>(null)
  const cur = currency ?? currencies[0] ?? 'ETB'
  const counts = (h?.counts ?? {}) as Record<string, number>
  const statuses = (p?.hotels ?? {}) as Record<string, number>
  const rows = (h?.data ?? []).filter((r) => !filter || r.health === filter)

  return (
    <div className="mx-auto flex max-w-6xl flex-col gap-6">
      <PageHeader title={t('nav.platformReports')} subtitle={t('admin.reports.subtitle')} />
      {p && (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {(
              [
                ['newThisMonth', p.new_this_month],
                ['rooms', p.rooms],
                ['lost', p.lost],
                ['trialsEnding', p.trials_ending.length],
              ] as const
            ).map(([k, v]) => (
              <Card key={k} className="flex flex-col gap-1 p-4">
                <span className="text-sm text-muted">{t(`admin.reports.${k}`)}</span>
                <span className="text-xl font-semibold tabular-nums">{v}</span>
              </Card>
            ))}
          </div>
          <Card className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h2 className="text-sm font-semibold">{t('admin.reports.income')}</h2>
              {currencies.length > 1 && (
                <div className="flex gap-1">
                  {currencies.map((c) => (
                    <button key={c} type="button" aria-pressed={cur === c} onClick={() => setCurrency(c)} className={cn('min-h-touch rounded-lg px-2.5 text-xs font-medium', cur === c ? 'bg-sunken border' : 'text-muted')}>
                      {c}
                    </button>
                  ))}
                </div>
              )}
            </div>
            <Bars
              label={t('admin.reports.income')}
              items={p.income.map((m) => ({ key: m.month, label: m.month, value: Number((m.totals as Record<string, string>)[cur] ?? 0) }))}
              format={(v) => money(v.toFixed(2), cur)}
            />
            <table className="w-full text-left text-sm">
              <tbody>
                {[...p.income].reverse().slice(0, 3).flatMap((m) =>
                  m.by_plan.map((b) => (
                    <tr key={`${m.month}-${b.plan}-${b.currency}`} className="border-t border-border">
                      <td className="py-1.5 pr-3 text-muted">{m.month}</td>
                      <td className="py-1.5 pr-3 font-medium">{b.plan}</td>
                      <td className="py-1.5 pr-3 text-right tabular-nums">{t('admin.reports.payments', { count: b.payments })}</td>
                      <td className="py-1.5 text-right tabular-nums">{money(b.total, b.currency)}</td>
                    </tr>
                  )),
                )}
              </tbody>
            </table>
          </Card>
          <div className="grid gap-4 lg:grid-cols-2">
            <Card className="flex flex-col gap-2">
              <h2 className="text-sm font-semibold">{t('admin.reports.byStatus')}</h2>
              {Object.entries(statuses).map(([s, c]) => (
                <div key={s} className="flex items-center justify-between text-sm">
                  <StatusBadge status={s} />
                  <span className="tabular-nums">{c}</span>
                </div>
              ))}
            </Card>
            <Card className="flex flex-col gap-2">
              <h2 className="text-sm font-semibold">{t('admin.reports.trialsEnding')}</h2>
              {p.trials_ending.length === 0 && <p className="text-sm text-muted">{t('reports.nothing')}</p>}
              {p.trials_ending.map((x) => (
                <Link key={x.id} to={`/admin/hotels/${x.id}`} className="flex justify-between text-sm hover:text-accent-text">
                  <span className="font-medium">{x.name}</span>
                  <span className="text-muted">{date(x.trial_ends_at)}</span>
                </Link>
              ))}
            </Card>
          </div>
        </>
      )}
      {h && (
        <Card className="flex flex-col gap-3 overflow-x-auto p-0">
          <div className="flex flex-wrap items-center justify-between gap-2 px-4 pt-4">
            <h2 className="text-sm font-semibold">{t('admin.reports.health')}</h2>
            <div className="flex flex-wrap gap-1.5">
              {HEALTH.map((k) => (
                <button
                  key={k}
                  type="button"
                  aria-pressed={filter === k}
                  onClick={() => setFilter(filter === k ? null : k)}
                  className={cn('min-h-touch rounded-full px-3 text-sm font-medium border shadow-xs transition-colors', filter === k ? 'border-primary bg-primary text-primary-foreground' : 'bg-background text-muted hover:bg-sunken dark:bg-input/30')}
                >
                  {t(`admin.reports.healthStates.${k}`)} · {counts[k] ?? 0}
                </button>
              ))}
            </div>
          </div>
          <p className="px-4 text-xs text-muted">{t('admin.reports.healthHelp')}</p>
          <table className="w-full min-w-[720px] text-left text-sm">
            <thead className="text-muted">
              <tr>
                <th className="px-4 py-1.5 font-medium">{t('admin.reports.hotel')}</th>
                <th className="px-4 py-1.5 font-medium">{t('admin.reports.state')}</th>
                <th className="px-4 py-1.5 text-right font-medium">{t('admin.reports.setup')}</th>
                <th className="px-4 py-1.5 font-medium">{t('admin.reports.lastUsed')}</th>
                <th className="px-4 py-1.5 text-right font-medium">{t('admin.reports.bookings30')}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.id} className="border-t border-border">
                  <td className="px-4 py-2">
                    <Link to={`/admin/hotels/${r.id}`} className="font-medium text-accent-text">
                      {r.name}
                    </Link>
                    <span className="block text-xs text-muted">
                      {r.plan ?? '—'} · {t(`hotelStatus.${r.status}`)}
                    </span>
                  </td>
                  <td className="px-4 py-2">
                    <Badge tone={TONE[r.health]}>{t(`admin.reports.healthStates.${r.health}`)}</Badge>
                  </td>
                  <td className="px-4 py-2 text-right tabular-nums">{r.setup_percent}%</td>
                  <td className="px-4 py-2 text-muted">{r.quiet_days === null ? t('admin.reports.never') : r.quiet_days === 0 ? t('admin.reports.today') : t('admin.reports.daysAgo', { count: r.quiet_days })}</td>
                  <td className="px-4 py-2 text-right tabular-nums">{r.bookings_30_days}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {rows.length === 0 && <p className="p-6 text-center text-muted">{t('reports.nothing')}</p>}
        </Card>
      )}
    </div>
  )
}
