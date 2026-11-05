import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { Card } from '@/components/ui/Card'
import { PageHeader } from '@/components/ui/PageHeader'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { useAdminDashboard } from '@/lib/admin'
import { date, money } from '@/lib/format'

export function AdminDashboardPage() {
  const { t } = useTranslation()
  const dash = useAdminDashboard()
  const d = dash.data

  const stats = [
    { label: t('admin.dash.totalHotels'), value: d?.hotels.total },
    { label: t('hotelStatus.active'), value: d?.hotels.by_status.active },
    { label: t('hotelStatus.trial'), value: d?.hotels.by_status.trial },
    { label: t('admin.dash.unpaid'), value: d ? (d.hotels.by_status.grace ?? 0) + (d.hotels.by_status.suspended ?? 0) : undefined },
  ]
  const income = d ? Object.entries(d.income_this_month) : []

  return (
    <div className="mx-auto flex max-w-6xl flex-col gap-6">
      <PageHeader title={t('nav.commandCenter')} subtitle={t('admin.dash.subtitle')} />

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        {stats.map((s) => (
          <Card key={s.label} className="flex flex-col gap-1">
            <span className="text-sm text-muted">{s.label}</span>
            <span className="text-3xl font-bold">{s.value ?? '—'}</span>
          </Card>
        ))}
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="flex flex-col gap-2">
          <h2 className="font-semibold">{t('admin.dash.incomeThisMonth')}</h2>
          {income.length === 0 ? (
            <p className="text-2xl font-bold">{money('0.00')}</p>
          ) : (
            income.map(([cur, total]) => (
              <p key={cur} className="text-2xl font-bold">
                {money(total, cur)}
              </p>
            ))
          )}
        </Card>

        <Card className="flex flex-col gap-3">
          <h2 className="font-semibold">{t('admin.dash.trialsEnding')}</h2>
          {d?.trials_ending_soon.length ? (
            <ul className="flex flex-col gap-1">
              {d.trials_ending_soon.map((h) => (
                <li key={h.id}>
                  <Link to={`/admin/hotels/${h.id}`} className="flex min-h-touch items-center justify-between gap-2 rounded-lg px-2 hover:bg-sunken">
                    <span className="truncate font-medium">{h.name}</span>
                    <span className="text-sm text-muted">{date(h.trial_ends_at)}</span>
                  </Link>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-muted">{t('admin.dash.none')}</p>
          )}
        </Card>

        <Card className="flex flex-col gap-3">
          <h2 className="font-semibold">{t('admin.dash.needsAttention')}</h2>
          {d?.needs_attention.length ? (
            <ul className="flex flex-col gap-1">
              {d.needs_attention.map((h) => (
                <li key={h.id}>
                  <Link to={`/admin/hotels/${h.id}`} className="flex min-h-touch items-center justify-between gap-2 rounded-lg px-2 hover:bg-sunken">
                    <span className="truncate font-medium">{h.name}</span>
                    <StatusBadge status={h.status} />
                  </Link>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-muted">{t('admin.dash.allGood')}</p>
          )}
        </Card>
      </div>
    </div>
  )
}
