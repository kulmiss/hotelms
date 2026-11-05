import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { Buildings, CheckCircle, HourglassMedium, Plus, WarningCircle } from '@phosphor-icons/react'
import { Card } from '@/components/ui/Card'
import { PageHeader } from '@/components/ui/PageHeader'
import { StatTile } from '@/components/ui/StatTile'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { useAdminDashboard } from '@/lib/admin'
import { date, money } from '@/lib/format'

export function AdminDashboardPage() {
  const { t } = useTranslation()
  const dash = useAdminDashboard()
  const d = dash.data
  const unpaid = d ? (d.hotels.by_status.grace ?? 0) + (d.hotels.by_status.suspended ?? 0) : undefined
  const income = d ? Object.entries(d.income_this_month) : []

  return (
    <div className="mx-auto flex max-w-6xl flex-col gap-8">
      <PageHeader
        title={t('nav.commandCenter')}
        subtitle={t('admin.dash.subtitle')}
        actions={
          <Link
            to="/admin/hotels/new"
            className="inline-flex min-h-touch items-center gap-2 rounded-xl bg-accent px-4 text-sm font-semibold text-on-accent shadow-soft transition-colors hover:bg-accent-hover"
          >
            <Plus aria-hidden weight="bold" className="size-5" />
            {t('admin.hotels.new')}
          </Link>
        }
      />

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatTile label={t('admin.dash.totalHotels')} value={d?.hotels.total} icon={Buildings} />
        <StatTile label={t('hotelStatus.active')} value={d?.hotels.by_status.active} icon={CheckCircle} tone="ok" />
        <StatTile label={t('hotelStatus.trial')} value={d?.hotels.by_status.trial} icon={HourglassMedium} tone="info" />
        <StatTile label={t('admin.dash.unpaid')} value={unpaid} icon={WarningCircle} tone={unpaid ? 'warn' : 'ok'} />
      </div>

      {d && d.hotels.total === 0 ? (
        <Card className="flex flex-col items-start gap-3 p-8">
          <h2 className="text-lg font-semibold">{t('admin.dash.emptyTitle')}</h2>
          <p className="max-w-prose text-muted">{t('admin.dash.emptyBody')}</p>
          <Link
            to="/admin/hotels/new"
            className="mt-2 inline-flex min-h-touch items-center gap-2 rounded-xl bg-accent px-4 font-semibold text-on-accent shadow-soft hover:bg-accent-hover"
          >
            <Plus aria-hidden weight="bold" className="size-5" />
            {t('admin.dash.createFirst')}
          </Link>
        </Card>
      ) : (
        <div className="grid gap-4 lg:grid-cols-3">
          <Card className="flex flex-col gap-2">
            <h2 className="text-sm font-medium text-muted">{t('admin.dash.incomeThisMonth')}</h2>
            {(income.length ? income : [['ETB', '0.00']]).map(([cur, total]) => (
              <p key={cur} className="text-3xl font-semibold tracking-tight">
                {money(total, cur)}
              </p>
            ))}
          </Card>

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
        </div>
      )}
    </div>
  )
}

function HotelList({ title, empty, items }: { title: string; empty: string; items: { id: number; name: string; right: ReactNode }[] }) {
  return (
    <Card className="flex flex-col gap-3">
      <h2 className="text-sm font-medium text-muted">{title}</h2>
      {items.length ? (
        <ul className="-mx-2 flex flex-col">
          {items.map((h) => (
            <li key={h.id}>
              <Link to={`/admin/hotels/${h.id}`} className="flex min-h-touch items-center justify-between gap-2 rounded-xl px-2 transition-colors hover:bg-sunken">
                <span className="truncate font-medium">{h.name}</span>
                {h.right}
              </Link>
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-sm text-muted">{empty}</p>
      )}
    </Card>
  )
}
