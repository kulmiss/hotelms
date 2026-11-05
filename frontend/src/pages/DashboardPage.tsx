import { useState } from 'react'
import { Link } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import {
  ArrowRightEndOnRectangleIcon,
  ArrowRightStartOnRectangleIcon,
  BanknotesIcon,
  ChartPieIcon,
  ExclamationTriangleIcon,
  HomeModernIcon,
  UsersIcon,
} from '@heroicons/react/24/outline'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Select } from '@/components/ui/Select'
import { StatTile } from '@/components/ui/StatTile'
import { getHealth } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { cn } from '@/lib/cn'
import { ROOM_DOTS } from '@/lib/desk'
import { date, money } from '@/lib/format'
import { useCan } from '@/lib/hotel'
import { useDashboard } from '@/lib/reports'

/** Today at a glance (screen-list.md 2.1). Money figures only for roles that may see revenue. */
export function DashboardPage() {
  const { t } = useTranslation()
  const me = useMe()
  const can = useCan()
  const branches = me.data?.branches ?? []
  const [branchId, setBranchId] = useState<number | null>(null)
  const isHotelUser = !!me.data?.hotel
  const dash = useDashboard(branchId)
  const d = isHotelUser && can('dashboard.view') ? dash.data?.data : undefined

  return (
    <div className="mx-auto flex max-w-6xl flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-bold">{t('dashboard.title')}</h1>
          {d && <p className="text-[13px] text-muted">{date(d.date)}</p>}
        </div>
        {d && branches.length > 1 && (
          <div className="w-48">
            <Select label={t('settings.rooms.branch')} value={String(branchId ?? '')} onChange={(e) => setBranchId(e.target.value ? Number(e.target.value) : null)}>
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
      <FinishSetupCard />

      {d && (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile label={t('dashboard.arrivals')} value={d.arrivals} icon={ArrowRightEndOnRectangleIcon} tone="info" footer={t('dashboard.alreadyIn', { count: d.arrived })} />
            <StatTile label={t('dashboard.departures')} value={d.departures} icon={ArrowRightStartOnRectangleIcon} tone="warn" footer={t('dashboard.alreadyOut', { count: d.departed })} />
            <StatTile label={t('dashboard.inHouse')} value={d.in_house} icon={UsersIcon} tone="accent" />
            <StatTile label={t('dashboard.occupancy')} value={Math.round(d.occupancy)} icon={ChartPieIcon} tone="ok" footer="%" />
          </div>

          {(d.pending_expiring > 0 || d.late_arrivals > 0 || (d.cashier_shortages ?? 0) > 0) && (
            <div className="flex flex-col gap-2">
              {d.late_arrivals > 0 && (
                <Alert tone="info">
                  <Link to="/rooms?tab=arrivals" className="underline">
                    {t('dashboard.lateArrivals', { count: d.late_arrivals })}
                  </Link>
                </Alert>
              )}
              {d.pending_expiring > 0 && (
                <Alert tone="info">
                  <Link to="/reservations" className="underline">
                    {t('dashboard.expiring', { count: d.pending_expiring })}
                  </Link>
                </Alert>
              )}
              {(d.cashier_shortages ?? 0) > 0 && <Alert>{t('dashboard.shortages', { count: d.cashier_shortages })}</Alert>}
            </div>
          )}

          <div className="grid gap-4 lg:grid-cols-2">
            <Card className="flex flex-col gap-3">
              <h2 className="flex items-center gap-2 text-sm font-semibold">
                <HomeModernIcon aria-hidden className="size-4 text-icon" />
                {t('dashboard.rooms', { count: d.rooms.total })}
              </h2>
              <div className="flex h-3 overflow-hidden rounded-full bg-sunken">
                {Object.entries(d.rooms.by_status).map(([s, n]) => (
                  <span key={s} className={cn('h-full', ROOM_DOTS[s])} style={{ width: `${(n / Math.max(1, d.rooms.total)) * 100}%` }} />
                ))}
              </div>
              <div className="flex flex-wrap gap-x-4 gap-y-1 text-[13px] text-muted">
                {Object.entries(d.rooms.by_status).map(([s, n]) => (
                  <span key={s} className="flex items-center gap-1.5">
                    <span className={cn('size-2 rounded-full', ROOM_DOTS[s])} />
                    {t(`roomStatus.${s}`)} <span className="tabular-nums">{n}</span>
                  </span>
                ))}
              </div>
              {can('frontdesk.view') && (
                <div>
                  <Link to="/rooms" className={buttonClass('secondary')}>
                    {t('nav.roomBoard')}
                  </Link>
                </div>
              )}
            </Card>
            <Card className="flex flex-col gap-3">
              <h2 className="text-sm font-semibold">{t('dashboard.nextDays')}</h2>
              <div className="flex h-32 items-end gap-2">
                {d.next_7_days.map((x) => (
                  <div key={x.date} className="flex h-full flex-1 flex-col items-center justify-end gap-1">
                    <span className="text-[11px] tabular-nums text-muted">{Math.round(x.occupancy)}%</span>
                    <span className="w-full rounded-t-md bg-accent/60" style={{ height: `${Math.max(3, Math.min(100, x.occupancy))}%` }} />
                    <span className="text-[11px] text-muted">{t(`common.weekdays.${new Date(`${x.date}T00:00:00`).getDay() || 7}`)}</span>
                  </div>
                ))}
              </div>
            </Card>
          </div>

          {d.revenue_today !== undefined && (
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <MoneyTile label={t('dashboard.revenueToday')} value={d.revenue_today} />
              <MoneyTile label={t('dashboard.paymentsToday')} value={d.payments_today ?? '0'} />
              <MoneyTile label={t('dashboard.unpaid')} value={d.unpaid_balances ?? '0'} tone={Number(d.unpaid_balances) > 0 ? 'danger' : undefined} link="/billing" />
              {d.month && <MoneyTile label={t('dashboard.monthRevenue')} value={d.month.revenue} footer={t('dashboard.monthKpis', { occ: d.month.occupancy, adr: money(d.month.adr) })} />}
            </div>
          )}
        </>
      )}

      <ApiStatus />
    </div>
  )
}

function MoneyTile({ label, value, tone, footer, link }: { label: string; value: string; tone?: 'danger'; footer?: string; link?: string }) {
  const body = (
    <div className="panel flex h-full flex-col gap-1.5 p-5">
      <span className="flex items-center gap-2 text-[13px] font-medium text-muted">
        <BanknotesIcon aria-hidden className="size-4 text-icon" />
        {label}
      </span>
      <span className={cn('text-xl font-semibold tracking-tight tabular-nums', tone === 'danger' && 'text-danger-text')}>{money(value)}</span>
      {footer && <span className="text-xs text-muted">{footer}</span>}
    </div>
  )
  return link ? <Link to={link}>{body}</Link> : body
}

function ApiStatus() {
  const { t } = useTranslation()
  const health = useQuery({ queryKey: ['health'], queryFn: getHealth, retry: 1 })
  const status = health.isPending ? (
    <Badge tone="grey">{t('dashboard.checking')}</Badge>
  ) : health.isError ? (
    <Badge tone="danger">{t('dashboard.offline')}</Badge>
  ) : health.data.status === 'ok' ? (
    <Badge tone="ok">{t('dashboard.connected')}</Badge>
  ) : (
    <Badge tone="warn">{t('dashboard.degraded')}</Badge>
  )
  return (
    <div className="flex flex-wrap items-center justify-end gap-2 text-xs text-muted">
      {health.isError && <ExclamationTriangleIcon aria-hidden className="size-4 text-danger-text" />}
      <span>{t('dashboard.apiStatus')}</span>
      {status}
      {health.data && <span>{t('dashboard.version', { version: health.data.version })}</span>}
    </div>
  )
}

/** Shown to the Hotel Admin while setup steps are left or were skipped. */
function FinishSetupCard() {
  const { t } = useTranslation()
  const me = useMe()
  const setup = me.data?.role?.is_hotel_admin && !me.data.impersonation ? me.data.hotel?.setup : null
  if (!setup || (setup.completed && setup.skipped === 0)) return null
  return (
    <Card className="flex flex-wrap items-center justify-between gap-3 ring-accent/40">
      <div className="flex flex-col gap-1">
        <h2 className="text-sm font-semibold">{t('setup.card.title')}</h2>
        <p className="text-[13px] text-muted">
          {setup.completed ? t('setup.card.skipped', { count: setup.skipped }) : t('setup.card.unfinished', { count: setup.remaining })}
        </p>
      </div>
      <Link to="/setup" className={buttonClass()}>
        {t('setup.card.action')}
      </Link>
    </Card>
  )
}
