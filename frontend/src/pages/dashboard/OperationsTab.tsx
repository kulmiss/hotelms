import { useState } from 'react'
import { Link } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ChartCard, KpiGrid, ROOM_COLORS, SERIES, WidgetBoundary, widgetState } from '@/components/charts/kit'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { api } from '@/lib/api'
import { useApprove, useReject } from '@/lib/automation'
import { useDashToday, usePendingApprovals, type DashFilters } from '@/lib/dashboard'
import { dateTime } from '@/lib/format'
import type { Page } from '@/lib/front'
import { useCan } from '@/lib/hotel'
import type { CashierSession, HkRoom } from '@/lib/modules'
import { useTodayAll } from './DeskLists'
import { RoomsSheet } from './Overview'
import { ReservationSheet, type StayAction } from './Sheets'

/** The Operations tab: rooms, cleaning, arrivals and departures with their actions, open cashier shifts, approvals. */
export function OperationsTab({ f }: { f: DashFilters }) {
  const { t } = useTranslation()
  const can = useCan()
  const dash = useDashToday(f.branchId)
  const today = useTodayAll(f.branchId, can('frontdesk.view'))
  const hk = useQuery({
    queryKey: ['dash', 'hk-board', f.branchId],
    queryFn: () => api<{ data: HkRoom[] }>(`/housekeeping/board${f.branchId ? `?branch_id=${f.branchId}` : ''}`),
    refetchInterval: 30_000,
    enabled: can('housekeeping.view'),
  })
  const shifts = useQuery({ queryKey: ['dash', 'open-shifts'], queryFn: () => api<Page<CashierSession>>('/cashier/sessions?status=open&per_page=20'), enabled: can('cashier.view_all') })
  const [rooms, setRooms] = useState<string | null>(null)
  const [stay, setStay] = useState<{ id: number; action: StayAction } | null>(null)
  const d = dash.data?.data
  const toClean = (hk.data?.data ?? []).filter((r) => r.status === 'dirty' || r.status === 'cleaning' || r.tasks.some((x) => x.status !== 'done'))
  const statuses = d ? Object.entries(d.rooms.by_status) : []

  return (
    <div className="flex flex-col gap-4">
      <KpiGrid>
        {statuses.map(([s, n]) => (
          <button key={s} type="button" onClick={() => setRooms(s)} className="panel flex flex-col gap-1.5 p-4 text-left transition-colors hover:bg-sunken/40">
            <span className="flex items-center gap-2 text-[13px] font-medium text-muted">
              <span aria-hidden className="size-2.5 rounded-[3px]" style={{ background: ROOM_COLORS[s] ?? SERIES[4] }} />
              {t(`roomStatus.${s}`)}
            </span>
            <span className="text-2xl font-bold tabular-nums">{n}</span>
            <span className="block h-1.5 rounded-full bg-sunken">
              <span className="block h-1.5 rounded-full" style={{ width: `${(n / Math.max(1, d?.rooms.total ?? 1)) * 100}%`, background: ROOM_COLORS[s] ?? SERIES[4] }} />
            </span>
          </button>
        ))}
      </KpiGrid>

      <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,360px),1fr))] gap-4">
        {can('housekeeping.view') && (
          <WidgetBoundary title={t('dash.ops.toClean')}>
            <ChartCard
              title={t('dash.ops.toClean')}
              description={hk.data ? t('dash.roomsCount', { count: toClean.length }) : undefined}
              action={
                <Link to="/housekeeping" className="tap-link text-sm font-medium text-accent-text">
                  {t('dash.viewAll')} →
                </Link>
              }
              state={widgetState(hk, toClean.length > 0)}
              onRetry={() => hk.refetch()}
              emptyText={t('dash.ops.allClean')}
              bodyClassName="p-0"
            >
              <ul className="flex flex-col">
                {toClean.slice(0, 12).map((r) => {
                  const task = r.tasks.find((x) => x.status !== 'done')
                  return (
                    <li key={r.id} className="flex items-center gap-3 border-t border-border px-4 py-2.5 text-sm">
                      <span className="w-12 text-lg font-bold">{r.number}</span>
                      <span className="flex min-w-0 flex-1 flex-col">
                        <span>{task ? t(`housekeeping.types.${task.type}`) : t(`roomStatus.${r.status}`)}</span>
                        <span className="truncate text-xs text-muted">{task?.assigned_to?.name ?? t('dash.ops.anyone')}</span>
                      </span>
                      {task && <Badge tone={task.status === 'in_progress' ? 'info' : task.priority === 'high' ? 'danger' : 'grey'}>{t(`housekeeping.statuses.${task.status}`)}</Badge>}
                    </li>
                  )
                })}
              </ul>
            </ChartCard>
          </WidgetBoundary>
        )}

        {can('frontdesk.view') && (
          <WidgetBoundary title={t('dash.ops.arriving')}>
            <ChartCard
              title={t('dash.ops.arriving')}
              state={widgetState(today, (today.data?.arrivals.length ?? 0) > 0)}
              onRetry={() => today.refetch()}
              emptyText={t('dash.desk.empty.arrivals')}
              bodyClassName="p-0"
            >
              <ul className="flex flex-col">
                {(today.data?.arrivals ?? []).slice(0, 10).map((r) => (
                  <li key={r.id} className="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-border px-4 py-2.5 text-sm">
                    <button type="button" onClick={() => setStay({ id: r.id, action: 'view' })} className="flex min-h-11 min-w-0 flex-1 basis-40 flex-col justify-center text-left">
                      <span className="font-medium">{r.guest?.name ?? r.code}</span>
                      <span className="text-xs text-muted">
                        {r.code} · {r.rooms.map((x) => x.room_number ?? x.room_type).join(', ')}
                      </span>
                    </button>
                    {r.late && <Badge tone="warn">{t('dash.desk.late')}</Badge>}
                    {can('frontdesk.check_in') && <Button onClick={() => setStay({ id: r.id, action: 'check_in' })}>{t('dash.actions.checkIn')}</Button>}
                  </li>
                ))}
              </ul>
            </ChartCard>
          </WidgetBoundary>
        )}

        {can('frontdesk.view') && (
          <WidgetBoundary title={t('dash.ops.leaving')}>
            <ChartCard
              title={t('dash.ops.leaving')}
              state={widgetState(today, (today.data?.departures.length ?? 0) > 0)}
              onRetry={() => today.refetch()}
              emptyText={t('dash.desk.empty.departures')}
              bodyClassName="p-0"
            >
              <ul className="flex flex-col">
                {(today.data?.departures ?? []).slice(0, 10).map((r) => (
                  <li key={r.id} className="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-border px-4 py-2.5 text-sm">
                    <button type="button" onClick={() => setStay({ id: r.id, action: 'view' })} className="flex min-h-11 min-w-0 flex-1 basis-40 flex-col justify-center text-left">
                      <span className="font-medium">{r.guest?.name ?? r.code}</span>
                      <span className="text-xs text-muted">
                        {r.code} · {r.rooms.map((x) => x.room_number ?? x.room_type).join(', ')}
                      </span>
                    </button>
                    {r.overdue && <Badge tone="danger">{t('dash.desk.overdue')}</Badge>}
                    {can('frontdesk.check_out') && <Button onClick={() => setStay({ id: r.id, action: 'check_out' })}>{t('dash.actions.checkOut')}</Button>}
                  </li>
                ))}
              </ul>
            </ChartCard>
          </WidgetBoundary>
        )}

        {can('cashier.view_all') && (
          <WidgetBoundary title={t('dash.ops.shifts')}>
            <ChartCard
              title={t('dash.ops.shifts')}
              action={
                <Link to="/cashier" className="tap-link text-sm font-medium text-accent-text">
                  {t('dash.viewAll')} →
                </Link>
              }
              state={widgetState(shifts, (shifts.data?.data.length ?? 0) > 0)}
              onRetry={() => shifts.refetch()}
              emptyText={t('dash.ops.noShifts')}
              bodyClassName="p-0"
            >
              <ul className="flex flex-col">
                {(shifts.data?.data ?? []).map((s) => (
                  <li key={s.id} className="flex items-center gap-3 border-t border-border px-4 py-2.5 text-sm">
                    <span className="min-w-0 flex-1">
                      <span className="font-medium">{s.user?.name}</span> <span className="text-xs text-muted">· {s.branch?.name}</span>
                      <span className="block text-xs text-muted">{t('dash.ops.since', { time: dateTime(s.opened_at) })}</span>
                    </span>
                    <Badge tone="ok">{t('dash.ops.open')}</Badge>
                  </li>
                ))}
              </ul>
            </ChartCard>
          </WidgetBoundary>
        )}

        {can('approvals.decide') && (
          <WidgetBoundary title={t('dash.ops.approvals')}>
            <Approvals />
          </WidgetBoundary>
        )}
      </div>

      <RoomsSheet status={rooms} branchId={f.branchId} onClose={() => setRooms(null)} />
      <ReservationSheet key={stay ? `${stay.id}-${stay.action}` : 'none'} id={stay?.id ?? null} action={stay?.action ?? 'view'} onClose={() => setStay(null)} />
    </div>
  )
}

/** Approvals waiting, decided right here with the same buttons as the Approvals page (refunds open that page: they need a payment method). */
function Approvals() {
  const { t } = useTranslation()
  const q = usePendingApprovals()
  const approve = useApprove()
  const reject = useReject()
  const list = q.data?.data ?? []
  return (
    <ChartCard
      title={t('dash.ops.approvals')}
      action={
        <Link to="/approvals" className="tap-link text-sm font-medium text-accent-text">
          {t('dash.viewAll')} →
        </Link>
      }
      state={widgetState(q, list.length > 0)}
      onRetry={() => q.refetch()}
      emptyText={t('dash.ops.noApprovals')}
      bodyClassName="p-0"
    >
      <ul className="flex flex-col">
        {list.slice(0, 6).map((a) => (
          <li key={a.id} className="flex flex-col gap-2 border-t border-border px-4 py-3 text-sm">
            <span className="font-medium">{a.title}</span>
            <span className="text-xs text-muted">
              {a.branch} · {dateTime(a.created_at)}
            </span>
            {a.kind === 'refund' ? (
              <Link to="/approvals" className="tap-link w-fit font-medium text-accent-text">
                {t('dash.ops.openApproval')} →
              </Link>
            ) : (
              <div className="flex gap-2">
                <Button disabled={approve.isPending || reject.isPending} onClick={() => approve.mutate({ id: a.id }, { onSuccess: () => void q.refetch() })}>
                  {t('approvals.approve')}
                </Button>
                <Button variant="secondary" disabled={approve.isPending || reject.isPending} onClick={() => reject.mutate({ id: a.id }, { onSuccess: () => void q.refetch() })}>
                  {t('approvals.reject')}
                </Button>
              </div>
            )}
          </li>
        ))}
      </ul>
    </ChartCard>
  )
}
