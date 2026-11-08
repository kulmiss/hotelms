import { Link } from 'react-router'
import { useQueries } from '@tanstack/react-query'
import type { TFunction } from 'i18next'
import { useTranslation } from 'react-i18next'
import { ChartCard, widgetState } from '@/components/charts/kit'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Tabs } from '@/components/ui/Tabs'
import { api } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { useOutstanding, type DashFilters } from '@/lib/dashboard'
import { isPositive } from '@/lib/decimal'
import type { TodayRow } from '@/lib/desk'
import { date, dateTime, money } from '@/lib/format'
import { useCan } from '@/lib/hotel'
import type { StayAction } from './Sheets'

export type DeskTab = 'arrivals' | 'departures' | 'in_house' | 'unpaid'
type Tab = DeskTab
type Today = { arrivals: TodayRow[]; departures: TodayRow[]; in_house: TodayRow[] }

/** Today's lists for one branch or all of the person's branches (one question per branch, in parallel). */
export function useTodayAll(branchId: number | null, enabled: boolean) {
  const me = useMe()
  const ids = branchId ? [branchId] : (me.data?.branches ?? []).map((b) => b.id)
  const results = useQueries({
    queries: ids.map((id) => ({ queryKey: ['dash', 'today', id], queryFn: () => api<Today>(`/front-desk/today?branch_id=${id}`), refetchInterval: 30_000, enabled })),
  })
  const merged: Today = { arrivals: [], departures: [], in_house: [] }
  for (const r of results) {
    if (!r.data) continue
    merged.arrivals.push(...r.data.arrivals)
    merged.departures.push(...r.data.departures)
    merged.in_house.push(...r.data.in_house)
  }
  return {
    data: results.every((r) => r.data) ? merged : undefined,
    isPending: results.some((r) => r.isPending),
    isError: results.some((r) => r.isError),
    refetch: () => results.forEach((r) => void r.refetch()),
    many: ids.length > 1,
  }
}

/** "Today at the desk": arriving, leaving, in house and unpaid bills, with the main actions right in the list. */
export function DeskLists({ f, tab: wanted, setTab, onStay, onInvoice }: { f: DashFilters; tab: DeskTab | null; setTab: (t: DeskTab) => void; onStay: (id: number, action: StayAction) => void; onInvoice: (id: number) => void }) {
  const { t } = useTranslation()
  const can = useCan()
  const desk = can('frontdesk.view')
  const unpaidAllowed = can('reports.outstanding')
  const today = useTodayAll(f.branchId, desk)
  const unpaid = useOutstanding(f, unpaidAllowed)
  const tabs: Tab[] = [...(desk ? (['arrivals', 'departures', 'in_house'] as const) : []), ...(unpaidAllowed ? (['unpaid'] as const) : [])]
  const tab: Tab = wanted && tabs.includes(wanted) ? wanted : (tabs[0] ?? 'arrivals')
  if (!tabs.length) return null
  const count = (k: Tab) => (k === 'unpaid' ? (unpaid.data?.data.rows.length ?? 0) : (today.data?.[k].length ?? 0))
  const rows = tab === 'unpaid' ? [] : (today.data?.[tab] ?? [])
  const q = tab === 'unpaid' ? unpaid : today
  const has = tab === 'unpaid' ? (unpaid.data?.data.rows.length ?? 0) > 0 : rows.length > 0

  return (
    <ChartCard
      id="dash-desk"
      title={t('dash.desk.title')}
      action={
        <Link to={tab === 'unpaid' ? '/billing' : '/rooms'} className="tap-link text-sm font-medium text-accent-text">
          {t('dash.viewAll')} →
        </Link>
      }
      state={widgetState(q, has)}
      onRetry={() => q.refetch()}
      emptyText={t(`dash.desk.empty.${tab}`)}
      bodyClassName="p-0"
    >
      <div className="px-4 pb-3">
        <Tabs label={t('dash.desk.title')} active={tab} onChange={(k) => setTab(k as Tab)} items={tabs.map((k) => ({ key: k, label: `${t(`dash.desk.tabs.${k}`)} ${count(k)}` }))} />
      </div>
      {tab === 'unpaid' ? (
        <UnpaidRows rows={unpaid.data?.data.rows ?? []} onInvoice={onInvoice} />
      ) : (
        <StayRows rows={rows} kind={tab} showBranch={today.many} onStay={onStay} />
      )}
    </ChartCard>
  )
}

function stayBadge(r: TodayRow, t: TFunction) {
  if (r.overdue) return <Badge tone="danger">{t('dash.desk.overdue')}</Badge>
  if (r.late) return <Badge tone="warn">{t('dash.desk.late')}</Badge>
  if (r.open_stay) return <Badge tone="info">{t('dash.desk.noEndDate')}</Badge>
  return <Badge tone="grey">{t(`reservations.statuses.${r.status}`)}</Badge>
}

function StayRows({ rows, kind, showBranch, onStay }: { rows: TodayRow[]; kind: Exclude<Tab, 'unpaid'>; showBranch: boolean; onStay: (id: number, a: StayAction) => void }) {
  const { t } = useTranslation()
  const can = useCan()
  const room = (r: TodayRow) => r.rooms.map((x) => [x.room_number ?? t('dash.desk.noRoom'), x.room_type].filter(Boolean).join(' · ')).join(', ')
  const actions = (r: TodayRow) => (
    <>
      {isPositive(r.balance) && can('payments.take') && (
        <Button variant="secondary" onClick={() => onStay(r.id, 'pay')}>
          {t('dash.actions.takePayment')}
        </Button>
      )}
      {kind === 'arrivals' && can('frontdesk.check_in') && <Button onClick={() => onStay(r.id, 'check_in')}>{t('dash.actions.checkIn')}</Button>}
      {kind !== 'arrivals' && can('frontdesk.check_out') && (kind === 'departures' || r.overdue) && <Button onClick={() => onStay(r.id, 'check_out')}>{t('dash.actions.checkOut')}</Button>}
    </>
  )
  return (
    <>
      {/* Phones: one card per guest. */}
      <ul className="flex flex-col sm:hidden">
        {rows.slice(0, 30).map((r) => (
          <li key={r.id} className="flex flex-col gap-2 border-t border-border px-4 py-3">
            <button type="button" onClick={() => onStay(r.id, 'view')} className="flex min-h-11 items-center gap-2 text-left">
              <span className="min-w-0 flex-1 font-semibold">{r.guest?.name ?? r.code}</span>
              {stayBadge(r, t)}
            </button>
            <span className="text-sm text-muted">
              {room(r)} · {r.code}
              {isPositive(r.balance) && <span className="text-warn-text"> · {t('dash.desk.owes', { amount: money(r.balance, r.currency) })}</span>}
            </span>
            <div className="flex gap-2 [&>*]:flex-1">{actions(r)}</div>
          </li>
        ))}
      </ul>
      {/* Bigger screens: a table; a row opens the booking in the side sheet. */}
      <div className="hidden overflow-x-auto sm:block">
        <table className="w-full min-w-[720px] text-sm">
          <thead>
            <tr className="text-left text-xs text-muted">
              <th className="px-4 py-2 font-medium">{t('dash.desk.guest')}</th>
              <th className="px-2 py-2 font-medium">{t('dash.desk.room')}</th>
              {showBranch && <th className="px-2 py-2 font-medium">{t('dash.desk.branch')}</th>}
              <th className="px-2 py-2 font-medium">{kind === 'arrivals' ? t('dash.desk.arrives') : t('dash.desk.leaves')}</th>
              <th className="px-2 py-2 font-medium">{t('dash.desk.status')}</th>
              <th className="px-2 py-2 text-right font-medium">{t('dash.desk.balance')}</th>
              <th className="px-4 py-2">
                <span className="sr-only">{t('dash.desk.actions')}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {rows.slice(0, 50).map((r) => (
              <tr key={r.id} className="border-t border-border hover:bg-sunken/40">
                <td className="px-4 py-2">
                  <button type="button" onClick={() => onStay(r.id, 'view')} className="tap-link text-left font-medium hover:underline">
                    {r.guest?.name ?? '—'}
                  </button>
                  <span className="block text-xs text-muted">{r.code}</span>
                </td>
                <td className="px-2 py-2">{room(r)}</td>
                {showBranch && <td className="px-2 py-2 text-muted">{r.branch?.name}</td>}
                <td className="px-2 py-2 tabular-nums">{kind === 'arrivals' ? dateTime(r.check_in_at) : date(r.check_out_at)}</td>
                <td className="px-2 py-2">{stayBadge(r, t)}</td>
                <td className={isPositive(r.balance) ? 'px-2 py-2 text-right font-medium text-warn-text tabular-nums' : 'px-2 py-2 text-right text-muted tabular-nums'}>
                  {isPositive(r.balance) ? money(r.balance, r.currency) : t('dash.desk.paid')}
                </td>
                <td className="px-4 py-1.5">
                  <div className="flex justify-end gap-2">{actions(r)}</div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  )
}

export function UnpaidRows({ rows, onInvoice }: { rows: { id: number; number: string; guest: string; reservation: string | null; balance: string; currency: string; age_days: number }[]; onInvoice: (id: number) => void }) {
  const { t } = useTranslation()
  const can = useCan()
  const sorted = [...rows].sort((a, b) => b.age_days - a.age_days)
  return (
    <ul className="flex flex-col">
      {sorted.slice(0, 30).map((r) => (
        <li key={r.id} className="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-border px-4 py-2.5">
          <button type="button" onClick={() => onInvoice(r.id)} className="flex min-h-11 min-w-0 flex-1 basis-56 flex-col justify-center text-left">
            <span className="font-medium">{r.guest}</span>
            <span className="text-xs text-muted">
              {r.number}
              {r.reservation && ` · ${r.reservation}`}
            </span>
          </button>
          <Badge tone={r.age_days >= 6 ? 'danger' : 'warn'}>{t('dash.desk.days', { count: r.age_days })}</Badge>
          <span className="w-32 text-right font-semibold tabular-nums">{money(r.balance, r.currency)}</span>
          {can('payments.take') && (
            <Button variant="secondary" onClick={() => onInvoice(r.id)}>
              {t('dash.actions.takePayment')}
            </Button>
          )}
        </li>
      ))}
    </ul>
  )
}
