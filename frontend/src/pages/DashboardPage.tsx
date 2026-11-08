import { useState } from 'react'
import { Link } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { SmartHints } from '@/components/ui/SmartHints'
import { useSmart } from '@/lib/smart'
import { BanknoteIcon, BedDoubleIcon, ChartPieIcon, LogInIcon, LogOutIcon, TriangleAlertIcon, UsersIcon } from 'lucide-react'
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
import { mainNav, NAV_MODULES } from '@/app/navigation'
import { useTasks } from '@/lib/modules'

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
  const smart = useSmart('dashboard', { branch_id: branchId }, isHotelUser && can('dashboard.view'))

  return (
    <div className="mx-auto flex max-w-6xl flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-bold tracking-tight">{t('dashboard.title')}</h1>
          {d && <p className="text-muted">{date(d.date)}</p>}
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
      <SmartHints hints={smart.data?.data} />

      {d && (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile label={t('dashboard.arrivals')} value={d.arrivals} icon={LogInIcon} tone="info" footer={t('dashboard.alreadyIn', { count: d.arrived })} />
            <StatTile label={t('dashboard.departures')} value={d.departures} icon={LogOutIcon} tone="warn" footer={t('dashboard.alreadyOut', { count: d.departed })} />
            <StatTile label={t('dashboard.inHouse')} value={d.in_house} icon={UsersIcon} tone="accent" />
            <StatTile label={t('dashboard.occupancy')} value={Math.round(d.occupancy)} icon={ChartPieIcon} tone="ok" suffix="%" />
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
            <Card className="flex flex-col gap-4">
              <h2 className="flex items-center justify-between gap-2 leading-none font-semibold">
                {t('dashboard.rooms', { count: d.rooms.total })}
                <BedDoubleIcon aria-hidden className="size-4 text-muted" />
              </h2>
              <div className="flex h-3 overflow-hidden rounded-full bg-sunken">
                {Object.entries(d.rooms.by_status).map(([s, n]) => (
                  <span key={s} className={cn('h-full', ROOM_DOTS[s])} style={{ width: `${(n / Math.max(1, d.rooms.total)) * 100}%` }} />
                ))}
              </div>
              <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted">
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
            <Card className="flex flex-col gap-4">
              <h2 className="leading-none font-semibold">{t('dashboard.nextDays')}</h2>
              <div className="flex h-32 items-end gap-2">
                {d.next_7_days.map((x) => (
                  <div key={x.date} className="flex h-full flex-1 flex-col items-center justify-end gap-1">
                    <span className="text-xs tabular-nums text-muted">{Math.round(x.occupancy)}%</span>
                    <span className="w-full rounded-t-[4px] bg-primary" style={{ height: `${Math.max(3, Math.min(100, x.occupancy))}%` }} />
                    <span className="text-xs text-muted">{t(`common.weekdays.${new Date(`${x.date}T00:00:00`).getDay() || 7}`)}</span>
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

      {isHotelUser && !can('dashboard.view') && <QuickLinks />}
      {isHotelUser && can('dashboard.view') && dash.isPending && <DashboardSkeleton />}

      {/* Technical: only for the HotelMS team (also while they support a hotel), not for hotel staff (owner, 2026-11-08). */}
      {(me.data?.user.is_super_admin || me.data?.impersonation) && <ApiStatus />}
    </div>
  )
}

/** For roles without the hotel numbers (e.g. Housekeeping): big shortcuts to their own screens. */
function QuickLinks() {
  const { t } = useTranslation()
  const me = useMe()
  const can = useCan()
  const modules = me.data?.hotel?.modules ?? []
  const items = mainNav.filter((item) => item.path !== '/' && can(item.permission) && (!item.module || modules.includes(NAV_MODULES[item.module])))
  if (!items.length) return null
  return (
    <div className="flex flex-col gap-3">
      {can('housekeeping.own_tasks') && modules.includes('housekeeping') && <RoomsToClean />}
      <h2 className="text-lg font-semibold tracking-tight">{t('dashboard.yourWork')}</h2>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {items.map((item) => (
          <Link key={item.path} to={item.path} className="panel flex min-h-touch items-center gap-3 p-6 transition-colors hover:bg-sunken/50">
            <item.icon aria-hidden className="size-5 text-muted" />
            <span className="text-sm font-medium">{t(`nav.${item.key}`)}</span>
          </Link>
        ))}
      </div>
    </div>
  )
}

/** For housekeepers: how many rooms are waiting, the first few, and a big button to the list. */
function RoomsToClean() {
  const { t } = useTranslation()
  const tasks = useTasks(true)
  const list = tasks.data?.data ?? []
  if (!tasks.data) return null
  return (
    <Link to="/housekeeping" className="panel flex flex-col gap-2 p-6 transition-colors hover:bg-sunken/50">
      <span className="text-sm font-medium">{t('dashboard.roomsToClean')}</span>
      <span className="text-3xl font-bold tracking-tight tabular-nums">{list.length}</span>
      <span className="text-xs text-muted">{list.length ? list.slice(0, 6).map((x) => x.room?.number).filter(Boolean).join(', ') : t('housekeeping.allDone')}</span>
    </Link>
  )
}

/** Grey shapes while today's numbers load, so the page never looks empty. */
function DashboardSkeleton() {
  return (
    <div aria-hidden className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {[0, 1, 2, 3].map((i) => (
          <div key={i} className="panel h-28 animate-pulse bg-sunken" />
        ))}
      </div>
      <div className="grid gap-4 lg:grid-cols-2">
        <div className="panel h-44 animate-pulse bg-sunken" />
        <div className="panel h-44 animate-pulse bg-sunken" />
      </div>
    </div>
  )
}

function MoneyTile({ label, value, tone, footer, link }: { label: string; value: string; tone?: 'danger'; footer?: string; link?: string }) {
  const body = (
    <div data-slot="card" className={cn('panel flex h-full flex-col gap-2 p-6', link && 'transition-colors hover:bg-sunken/50')}>
      <span className="flex items-center justify-between gap-2 text-sm font-medium">
        {label}
        <BanknoteIcon aria-hidden className="size-4 shrink-0 text-muted" />
      </span>
      <span className={cn('text-2xl font-bold tracking-tight tabular-nums', tone === 'danger' && 'text-danger-text')}>{money(value)}</span>
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
      {health.isError && <TriangleAlertIcon aria-hidden className="size-4 text-danger-text" />}
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
    <Card className="flex flex-wrap items-center justify-between gap-3 border-primary/40">
      <div className="flex flex-col gap-1">
        <h2 className="leading-none font-semibold">{t('setup.card.title')}</h2>
        <p className="text-sm text-muted">
          {setup.completed ? t('setup.card.skipped', { count: setup.skipped }) : t('setup.card.unfinished', { count: setup.remaining })}
        </p>
      </div>
      <Link to="/setup" className={buttonClass()}>
        {t('setup.card.action')}
      </Link>
    </Card>
  )
}
