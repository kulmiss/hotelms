import { Link, useSearchParams } from 'react-router'
import { useIsFetching, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { RefreshCw, TriangleAlertIcon } from 'lucide-react'
import { SmartHints } from '@/components/ui/SmartHints'
import { Badge } from '@/components/ui/Badge'
import { Button, buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { CollapsibleToolbar } from '@/components/ui/CollapsibleToolbar'
import { Field } from '@/components/ui/Field'
import { Select } from '@/components/ui/Select'
import { TabPanel, Tabs } from '@/components/ui/Tabs'
import { getHealth } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { cn } from '@/lib/cn'
import { DASH_RANGES, useDashFilters, useRefreshDashboard, type DashFilters } from '@/lib/dashboard'
import { date } from '@/lib/format'
import { today } from '@/lib/front'
import { useCan } from '@/lib/hotel'
import { useSmart } from '@/lib/smart'
import { mainNav, NAV_MODULES } from '@/app/navigation'
import { useTasks } from '@/lib/modules'
import { OperationsTab } from './dashboard/OperationsTab'
import { Overview } from './dashboard/Overview'
import { RevenueTab } from './dashboard/RevenueTab'

/**
 * The hotel dashboard (P18): KPIs, charts and today's lists on one page, with a period and branch filter
 * that update every widget. Tabs: Overview, Revenue (money roles), Operations. Money only for dashboard.view_revenue.
 */
export function DashboardPage() {
  const { t } = useTranslation()
  const me = useMe()
  const can = useCan()
  const isHotelUser = !!me.data?.hotel
  const allowed = isHotelUser && can('dashboard.view')
  const [f, setF] = useDashFilters()
  const smart = useSmart('dashboard', { branch_id: f.branchId }, allowed)
  const [params, setParams] = useSearchParams()
  const tabs = [
    'overview',
    ...(can('dashboard.view_revenue') ? ['revenue'] : []),
    ...(can(['frontdesk.view', 'housekeeping.view', 'approvals.decide', 'cashier.view_all']) ? ['operations'] : []),
  ]
  const tab = tabs.includes(params.get('tab') ?? '') ? (params.get('tab') as string) : 'overview'
  const setTab = (k: string) =>
    setParams(
      (p) => {
        const out = new URLSearchParams(p)
        if (k === 'overview') out.delete('tab')
        else out.set('tab', k)
        return out
      },
      { replace: true },
    )

  return (
    <div className="mx-auto flex w-full max-w-[1440px] flex-col gap-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-bold tracking-tight">{t('dashboard.title')}</h1>
          {allowed && <p className="text-sm text-muted">{date(today())}</p>}
        </div>
        {allowed && <DashFiltersBar f={f} setF={setF} />}
      </div>
      <FinishSetupCard />
      <SmartHints hints={smart.data?.data} />

      {allowed && (
        <>
          {tabs.length > 1 && <Tabs label={t('dash.sections')} active={tab} onChange={setTab} items={tabs.map((k) => ({ key: k, label: t(`dash.tabs.${k}`) }))} />}
          <TabPanel id="overview" active={tab}>
            <Overview f={f} onTab={setTab} />
          </TabPanel>
          <TabPanel id="revenue" active={tab}>
            <RevenueTab f={f} />
          </TabPanel>
          <TabPanel id="operations" active={tab}>
            <OperationsTab f={f} />
          </TabPanel>
        </>
      )}

      {isHotelUser && !can('dashboard.view') && <QuickLinks />}

      {/* Technical: only for the HotelMS team (also while they support a hotel), not for hotel staff (owner, 2026-11-08). */}
      {(me.data?.user.is_super_admin || me.data?.impersonation) && <ApiStatus />}
    </div>
  )
}

/** Period, branch and refresh: one change updates every widget (below 1024px they fold behind Filters). */
function DashFiltersBar({ f, setF }: { f: DashFilters; setF: (n: Partial<DashFilters>) => void }) {
  const { t } = useTranslation()
  const me = useMe()
  const refresh = useRefreshDashboard()
  const fetching = useIsFetching({ queryKey: ['dash'] }) > 0
  const branches = me.data?.branches ?? []
  const chips = [
    ...(f.range !== '30' ? [{ key: 'range', label: f.range === 'custom' ? `${date(f.from)} – ${date(f.to)}` : t(`dash.ranges.${f.range}`), onRemove: () => setF({ range: '30' }) }] : []),
    ...(f.branchId ? [{ key: 'branch', label: branches.find((b) => b.id === f.branchId)?.name ?? '', onRemove: () => setF({ branchId: null }) }] : []),
  ]
  return (
    <div className="flex flex-wrap items-end gap-2">
      <CollapsibleToolbar
        id="dashboard"
        active={chips}
        onClearAll={() => setF({ range: '30', branchId: null })}
        actions={
          <Button variant="secondary" className="shrink-0" aria-label={t('dash.refresh')} title={t('dash.refresh')} onClick={() => void refresh()}>
            <RefreshCw aria-hidden className={cn(fetching && 'animate-spin motion-reduce:animate-none')} />
          </Button>
        }
      >
        {branches.length > 1 && (
          <div className="w-44">
            <Select label={t('dash.branch')} value={String(f.branchId ?? '')} onChange={(e) => setF({ branchId: e.target.value ? Number(e.target.value) : null })}>
              <option value="">{t('reservations.allBranches')}</option>
              {branches.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.name}
                </option>
              ))}
            </Select>
          </div>
        )}
        <div role="group" aria-label={t('dash.period')} className="flex h-9 overflow-hidden rounded-md border border-border pointer-coarse:h-auto">
          {DASH_RANGES.map((r) => (
            <button
              key={r}
              type="button"
              aria-pressed={f.range === r}
              onClick={() => setF({ range: r })}
              className={cn('px-3 text-sm font-medium transition-colors duration-150 pointer-coarse:min-h-11', f.range === r ? 'bg-primary text-primary-foreground' : 'hover:bg-sunken')}
            >
              {t(`dash.ranges.${r}`)}
            </button>
          ))}
        </div>
        {f.range === 'custom' && (
          <>
            <div className="w-40">
              <Field label={t('dates.from')} type="date" value={f.from} max={f.to} onChange={(e) => e.target.value && setF({ from: e.target.value })} />
            </div>
            <div className="w-40">
              <Field label={t('dates.to')} type="date" value={f.to} min={f.from} max={today()} onChange={(e) => e.target.value && setF({ to: e.target.value })} />
            </div>
          </>
        )}
      </CollapsibleToolbar>
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
      {health.data?.version && <span>{t('dashboard.version', { version: health.data.version })}</span>}
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
