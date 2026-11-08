import { useState } from 'react'
import { useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { SmartHints } from '@/components/ui/SmartHints'
import { useSmart } from '@/lib/smart'
import { useMe } from '@/lib/auth'
import { useCurrentBranch } from '@/lib/branch'
import { CheckIcon, PlayIcon } from 'lucide-react'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Select, SelectBox } from '@/components/ui/Select'
import { Tabs } from '@/components/ui/Tabs'
import { ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { ROOM_DOTS, ROOM_TONES } from '@/lib/desk'
import { localTime } from '@/lib/front'
import { useCan } from '@/lib/hotel'
import { useDoneTask, useHkBoard, useHkStaff, useSaveTask, useStartTask, useTasks, type Task } from '@/lib/modules'

const err = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : null)
const PRIORITY = { high: 'danger', normal: 'grey', low: 'grey' } as const

/** Housekeeping: my tasks (tablet friendly), rooms board, assign tasks (screen-list.md 8.1–8.3). */
export function HousekeepingPage() {
  const { t } = useTranslation()
  const can = useCan()
  const [params, setParams] = useSearchParams()
  const tabs = [
    ...(can('housekeeping.own_tasks') || can('housekeeping.assign') ? ['tasks'] : []),
    'board',
    ...(can('housekeeping.assign') ? ['assign'] : []),
  ]
  const tab = tabs.includes(params.get('tab') ?? '') ? (params.get('tab') as string) : tabs[0]
  const me = useMe()
  const { current } = useCurrentBranch(me.data)
  const smart = useSmart('housekeeping', { branch_id: current?.id }, Boolean(current))

  return (
    <div className="flex flex-col gap-6">
      <PageHeader title={t('nav.housekeeping')} subtitle={t('housekeeping.subtitle')} />
      <SmartHints hints={smart.data?.data} />
      <div className="max-w-md">
        <Tabs label={t('nav.housekeeping')} active={tab} onChange={(k) => setParams({ tab: k })} items={tabs.map((k) => ({ key: k, label: t(`housekeeping.tabs.${k}`) }))} />
      </div>
      {tab === 'tasks' && <MyTasks branchId={current?.id ?? null} />}
      {tab === 'board' && <HkBoard branchId={current?.id ?? null} />}
      {tab === 'assign' && <AssignTasks branchId={current?.id ?? null} />}
    </div>
  )
}

function MyTasks({ branchId }: { branchId: number | null }) {
  const { t } = useTranslation()
  const can = useCan()
  const tasks = useTasks(!can('housekeeping.assign'), branchId)
  const start = useStartTask()
  const done = useDoneTask()
  const list = tasks.data?.data ?? []

  return (
    <div className="flex flex-col gap-3">
      {(start.error || done.error) && <Alert>{err(start.error ?? done.error)}</Alert>}
      {tasks.data && list.length === 0 && <Alert tone="ok">{t('housekeeping.allDone')}</Alert>}
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {list.map((task) => (
          <Card key={task.id} className="flex flex-col gap-3">
            <div className="flex items-start justify-between gap-2">
              <div className="flex flex-col">
                <span className="text-2xl font-semibold">{task.room?.number}</span>
                <span className="text-sm text-muted">
                  {t(`housekeeping.types.${task.type}`)}
                  {task.due_at && ` · ${t('housekeeping.due', { time: localTime(task.due_at) })}`}
                </span>
              </div>
              <span className="flex flex-col items-end gap-1">
                {task.priority === 'high' && <Badge tone="danger">{t('housekeeping.priorities.high')}</Badge>}
                <Badge tone={task.status === 'in_progress' ? 'info' : 'grey'}>{t(`housekeeping.statuses.${task.status}`)}</Badge>
              </span>
            </div>
            {task.notes && <p className="text-sm text-muted">{task.notes}</p>}
            {task.assigned_to && <p className="text-xs text-muted">{t('housekeeping.assignedTo', { name: task.assigned_to.name })}</p>}
            {task.status === 'pending' ? (
              <Button size="lg" disabled={start.isPending} onClick={() => start.mutate(task.id)}>
                <PlayIcon aria-hidden className="size-5" />
                {t('housekeeping.start')}
              </Button>
            ) : (
              <Button size="lg" disabled={done.isPending} onClick={() => done.mutate({ id: task.id })}>
                <CheckIcon aria-hidden className="size-5" />
                {t('housekeeping.done')}
              </Button>
            )}
          </Card>
        ))}
      </div>
    </div>
  )
}

function HkBoard({ branchId }: { branchId: number | null }) {
  const { t } = useTranslation()
  const board = useHkBoard(branchId)
  return (
    <div className="grid grid-cols-[repeat(auto-fill,minmax(8rem,1fr))] gap-2">
      {board.data?.data.map((r) => (
        <div key={r.id} className={cn('flex min-h-20 flex-col gap-1 rounded-xl border p-3 shadow-xs', ROOM_TONES[r.status])}>
          <span className="flex items-center justify-between">
            <span className="text-base font-semibold">{r.number}</span>
            <span className={cn('size-2.5 rounded-full', ROOM_DOTS[r.status])} />
          </span>
          <span className="text-xs text-muted">{t(`roomStatus.${r.status}`)}</span>
          {r.tasks.map((task) => (
            <span key={task.id} className="text-xs">
              {t(`housekeeping.types.${task.type}`)} · {task.assigned_to?.name ?? t('housekeeping.nobody')}
            </span>
          ))}
        </div>
      ))}
    </div>
  )
}

function AssignTasks({ branchId }: { branchId: number | null }) {
  const { t } = useTranslation()
  const board = useHkBoard(branchId)
  const tasks = useTasks(false, branchId)
  const staff = useHkStaff(true)
  const save = useSaveTask()
  const [f, setF] = useState({ room_id: 0, type: 'cleaning', priority: 'normal', assigned_to: 0, notes: '' })
  const rooms = board.data?.data ?? []

  return (
    <div className="flex flex-col gap-4">
      <Card className="grid gap-3 sm:grid-cols-5">
        <Select label={t('reservations.room')} value={String(f.room_id || '')} onChange={(e) => setF({ ...f, room_id: Number(e.target.value) })}>
          <option value="">—</option>
          {rooms.map((r) => (
            <option key={r.id} value={r.id}>
              {r.number} · {t(`roomStatus.${r.status}`)}
            </option>
          ))}
        </Select>
        <Select label={t('housekeeping.type')} value={f.type} onChange={(e) => setF({ ...f, type: e.target.value })}>
          {(['cleaning', 'inspection', 'maintenance'] as const).map((x) => (
            <option key={x} value={x}>
              {t(`housekeeping.types.${x}`)}
            </option>
          ))}
        </Select>
        <Select label={t('housekeeping.priority')} value={f.priority} onChange={(e) => setF({ ...f, priority: e.target.value })}>
          {(['high', 'normal', 'low'] as const).map((x) => (
            <option key={x} value={x}>
              {t(`housekeeping.priorities.${x}`)}
            </option>
          ))}
        </Select>
        <Select label={t('housekeeping.person')} value={String(f.assigned_to || '')} onChange={(e) => setF({ ...f, assigned_to: Number(e.target.value) })}>
          <option value="">{t('housekeeping.nobody')}</option>
          {staff.data?.data.map((s) => (
            <option key={s.id} value={s.id}>
              {s.name}
            </option>
          ))}
        </Select>
        <Field label={t('guests.notes')} value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} />
        {save.error && <div className="sm:col-span-5"><Alert>{err(save.error)}</Alert></div>}
        <div className="flex justify-end sm:col-span-5">
          <Button disabled={!f.room_id || save.isPending} onClick={() => save.mutate({ ...f, assigned_to: f.assigned_to || null, notes: f.notes || null }, { onSuccess: () => setF({ ...f, room_id: 0, notes: '' }) })}>
            {t('housekeeping.addTask')}
          </Button>
        </div>
      </Card>
      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[640px] text-left text-sm">
          <thead>
            <tr>
              <th className="px-4 h-10 font-medium">{t('reservations.room')}</th>
              <th className="px-4 h-10 font-medium">{t('housekeeping.type')}</th>
              <th className="px-4 h-10 font-medium">{t('housekeeping.priority')}</th>
              <th className="px-4 h-10 font-medium">{t('housekeeping.person')}</th>
              <th className="px-4 h-10 font-medium">{t('reservations.status')}</th>
            </tr>
          </thead>
          <tbody>
            {tasks.data?.data.map((task: Task) => (
              <tr key={task.id} className="border-t border-border">
                <td className="px-4 py-2 font-medium">{task.room?.number}</td>
                <td className="px-4 py-2">{t(`housekeeping.types.${task.type}`)}</td>
                <td className="px-4 py-2">
                  <Badge tone={PRIORITY[task.priority]}>{t(`housekeeping.priorities.${task.priority}`)}</Badge>
                </td>
                <td className="px-2 py-1">
                  <SelectBox
                    aria-label={t('housekeeping.person')}
                    className="w-auto"
                    value={String(task.assigned_to?.id ?? '')}
                    onChange={(e) => task.room && save.mutate({ id: task.id, room_id: task.room.id, type: task.type, priority: task.priority, assigned_to: e.target.value ? Number(e.target.value) : null, notes: task.notes })}
                  >
                    <option value="">{t('housekeeping.nobody')}</option>
                    {staff.data?.data.map((s) => (
                      <option key={s.id} value={s.id}>
                        {s.name}
                      </option>
                    ))}
                  </SelectBox>
                </td>
                <td className="px-4 py-2">{t(`housekeeping.statuses.${task.status}`)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {tasks.data?.data.length === 0 && <p className="p-6 text-center text-muted">{t('housekeeping.allDone')}</p>}
      </Card>
    </div>
  )
}
