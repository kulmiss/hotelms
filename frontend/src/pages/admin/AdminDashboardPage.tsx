import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { SmartHints } from '@/components/ui/SmartHints'
import { useSmart } from '@/lib/smart'
import { BuildingOffice2Icon, CalendarDaysIcon, CheckCircleIcon, ClockIcon, ExclamationTriangleIcon, HomeModernIcon, LifebuoyIcon, UsersIcon } from '@heroicons/react/24/outline'
import { PlusIcon } from '@heroicons/react/20/solid'
import { buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { PageHeader } from '@/components/ui/PageHeader'
import { StatTile } from '@/components/ui/StatTile'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { useAdminDashboard } from '@/lib/admin'
import { useMe } from '@/lib/auth'
import { useWatchdog } from '@/lib/platform'
import { date, money, dateTime } from '@/lib/format'

export function AdminDashboardPage() {
  const { t } = useTranslation()
  const dash = useAdminDashboard()
  const smart = useSmart('admin')
  const me = useMe()
  const watch = useWatchdog('open', !!me.data?.platform?.permissions.includes('watchdog.view'))
  const serious = watch.data?.counts.critical ?? 0
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
            className={buttonClass('primary')}
          >
            <PlusIcon aria-hidden className="size-4" />
            {t('admin.hotels.new')}
          </Link>
        }
      />

      {serious > 0 && (
        <Link to="/admin/watchdog" className="rounded-lg bg-danger-bg px-4 py-3 text-sm font-medium text-danger-text ring-1 ring-danger-text/20 hover:brightness-95">
          {t('watchdog.alert', { count: serious })}
        </Link>
      )}
      <SmartHints hints={smart.data?.data} />

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatTile label={t('admin.dash.totalHotels')} value={d?.hotels.total} icon={BuildingOffice2Icon} />
        <StatTile label={t('hotelStatus.active')} value={d?.hotels.by_status.active} icon={CheckCircleIcon} tone="ok" />
        <StatTile label={t('hotelStatus.trial')} value={d?.hotels.by_status.trial} icon={ClockIcon} tone="info" />
        <StatTile label={t('admin.dash.unpaid')} value={unpaid} icon={ExclamationTriangleIcon} tone={unpaid ? 'warn' : 'ok'} />
      </div>

      {d?.today && (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          <StatTile label={t('admin.dash.bookingsToday')} value={d.today.bookings} icon={CalendarDaysIcon} />
          <StatTile label={t('admin.dash.occupancyTonight')} value={d.today.occupancy} icon={HomeModernIcon} tone="info" footer={t('hotelTabs.roomsLine', { occupied: d.today.occupied, rooms: d.today.rooms })} />
          <StatTile label={t('admin.dash.staffOnline')} value={d.today.staff_online} icon={UsersIcon} tone="ok" />
          <StatTile label={t('admin.dash.openSupport')} value={d.today.open_support} icon={LifebuoyIcon} tone={d.today.open_support ? 'warn' : 'ok'} />
        </div>
      )}

      {d && d.hotels.total === 0 ? (
        <Card className="flex flex-col items-start gap-3 p-8">
          <h2 className="text-lg font-semibold">{t('admin.dash.emptyTitle')}</h2>
          <p className="max-w-prose text-muted">{t('admin.dash.emptyBody')}</p>
          <Link
            to="/admin/hotels/new"
            className={buttonClass('primary', 'md', 'mt-2')}
          >
            <PlusIcon aria-hidden className="size-4" />
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
          <Card className="flex flex-col gap-2">
            <h2 className="text-sm font-medium text-muted">{t('admin.dash.moneyToday')}</h2>
            {Object.entries(d?.today?.money ?? {}).length ? (
              Object.entries(d?.today?.money ?? {}).map(([cur, total]) => (
                <p key={cur} className="text-2xl font-semibold tracking-tight">
                  {money(total, cur)}
                </p>
              ))
            ) : (
              <p className="text-2xl font-semibold tracking-tight">{money('0', 'ETB')}</p>
            )}
            <p className="text-xs text-muted">{t('admin.dash.moneyTodayHint')}</p>
          </Card>
          <HotelList
            title={t('admin.dash.topHotels')}
            empty={t('admin.dash.none')}
            items={(d?.top_hotels ?? []).map((h) => ({ id: h.id, name: h.name, right: <span className="text-sm text-muted">{money(h.total, h.currency)}</span> }))}
          />
          <Card className="flex flex-col gap-3">
            <h2 className="text-sm font-medium text-muted">{t('admin.dash.liveActivity')}</h2>
            {d?.activity?.length ? (
              <ul className="flex flex-col gap-2 text-[13px]">
                {d.activity.map((a) => (
                  <li key={a.id} className="flex flex-col">
                    <span className="line-clamp-2">{a.description}</span>
                    <span className="text-xs text-muted">
                      {a.hotel?.name}
                      {a.user && ` · ${a.user}`} · {new Date(a.at).toDateString() === new Date().toDateString() ? new Date(a.at).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }) : dateTime(a.at)}
                    </span>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-sm text-muted">{t('admin.dash.none')}</p>
            )}
          </Card>
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
              <Link to={`/admin/hotels/${h.id}`} className="flex min-h-touch items-center justify-between gap-2 rounded-lg px-2 transition duration-75 hover:bg-sunken">
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
