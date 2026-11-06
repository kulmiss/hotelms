import { useState } from 'react'
import { useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/react/24/outline'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { PageHeader } from '@/components/ui/PageHeader'
import { ResourceList } from '@/components/ui/ResourceList'
import { Tabs } from '@/components/ui/Tabs'
import { ApiError } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { date } from '@/lib/format'
import { addDays, today } from '@/lib/front'
import { useCan } from '@/lib/hotel'
import { useAssignShift, useMyShifts, useRoster, useUnassignShift } from '@/lib/modules'

/** Monday of the week of a date. */
function monday(d: string): string {
  const wd = new Date(`${d}T00:00:00`).getDay()
  return addDays(d, wd === 0 ? -6 : 1 - wd)
}

/** Staff shifts (optional module): my shifts, weekly roster, shift templates (screen-list.md 9.4, 9.5). */
export function ShiftsPage() {
  const { t } = useTranslation()
  const can = useCan()
  const [params, setParams] = useSearchParams()
  const tabs = ['mine', ...(can('shifts.manage') ? ['roster', 'templates'] : [])]
  const tab = tabs.includes(params.get('tab') ?? '') ? (params.get('tab') as string) : tabs[0]

  return (
    <div className="flex flex-col gap-6">
      <PageHeader title={t('nav.shifts')} subtitle={t('shifts.subtitle')} />
      {tabs.length > 1 && (
        <div className="max-w-md">
          <Tabs label={t('nav.shifts')} active={tab} onChange={(k) => setParams({ tab: k })} items={tabs.map((k) => ({ key: k, label: t(`shifts.tabs.${k}`) }))} />
        </div>
      )}
      {tab === 'mine' && <MyShifts />}
      {tab === 'roster' && <RosterGrid />}
      {tab === 'templates' && <Templates />}
    </div>
  )
}

function MyShifts() {
  const { t } = useTranslation()
  const mine = useMyShifts()
  return (
    <Card className="flex flex-col gap-2">
      {mine.data?.data.length === 0 && <p className="text-[13px] text-muted">{t('shifts.noneMine')}</p>}
      <ul className="flex flex-col divide-y divide-border text-[13px]">
        {mine.data?.data.map((a) => (
          <li key={a.id} className="flex min-h-touch items-center justify-between gap-3 py-1.5">
            <span className="font-medium">{date(a.date)}</span>
            <span className="text-muted">
              {a.shift?.name} · {a.shift?.start_time.slice(0, 5)}–{a.shift?.end_time.slice(0, 5)} · {a.branch}
            </span>
          </li>
        ))}
      </ul>
    </Card>
  )
}

function RosterGrid() {
  const { t } = useTranslation()
  const [from, setFrom] = useState(() => monday(today()))
  const roster = useRoster(from, true)
  const assign = useAssignShift()
  const unassign = useUnassignShift()
  const days = Array.from({ length: 7 }, (_, i) => addDays(from, i))
  const data = roster.data
  const error = assign.error ?? unassign.error

  return (
    <div className="flex flex-col gap-3">
      <div className="flex items-center gap-2">
        <Button variant="secondary" aria-label={t('reservations.earlier')} onClick={() => setFrom(addDays(from, -7))}>
          <ChevronLeftIcon aria-hidden className="size-4" />
        </Button>
        <Button variant="secondary" aria-label={t('reservations.later')} onClick={() => setFrom(addDays(from, 7))}>
          <ChevronRightIcon aria-hidden className="size-4" />
        </Button>
        <span className="text-[13px] text-muted">
          {date(from)} – {date(addDays(from, 6))}
        </span>
      </div>
      {error instanceof ApiError && <Alert>{Object.values(error.errors)[0]?.[0] ?? error.message}</Alert>}
      {data && data.shifts.length === 0 && <Alert tone="info">{t('shifts.addTemplatesFirst')}</Alert>}
      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[900px] text-left text-[13px]">
          <thead className="bg-sunken">
            <tr>
              <th className="px-3 py-2 font-semibold">{t('shifts.person')}</th>
              {days.map((d) => (
                <th key={d} className="px-2 py-2 font-semibold">
                  {date(d)}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {data?.staff.map((s) => (
              <tr key={s.id} className="border-t border-border">
                <td className="px-3 py-1.5 font-medium whitespace-nowrap">{s.name}</td>
                {days.map((d) => {
                  const a = data.assignments.find((x) => x.user_id === s.id && x.date === d)
                  return (
                    <td key={d} className="px-1 py-1">
                      <select
                        aria-label={`${s.name} ${d}`}
                        className="min-h-touch w-full rounded-lg bg-surface px-1.5 text-xs ring-1 ring-ring"
                        value={String(a?.shift_id ?? '')}
                        onChange={(e) => {
                          const v = Number(e.target.value)
                          if (a) unassign.mutate(a.id, { onSuccess: () => v && assign.mutate({ user_id: s.id, shift_id: v, date: d }) })
                          else if (v) assign.mutate({ user_id: s.id, shift_id: v, date: d })
                        }}
                      >
                        <option value="">{t('shifts.off')}</option>
                        {data.shifts.map((sh) => (
                          <option key={sh.id} value={sh.id}>
                            {sh.name}
                          </option>
                        ))}
                      </select>
                    </td>
                  )
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
    </div>
  )
}

function Templates() {
  const { t } = useTranslation()
  const me = useMe()
  const branches = me.data?.branches ?? []
  return (
    <ResourceList
      endpoint="/shifts"
      title={t('shifts.tabs.templates')}
      description={t('shifts.templatesHelp')}
      addLabel={t('shifts.addTemplate')}
      defaults={{ branch_id: String(branches[0]?.id ?? ''), name: '', start_time: '08:00', end_time: '16:00' }}
      columns={[
        { label: t('shifts.name'), render: (r) => <span className="font-medium">{String(r.name)}</span> },
        { label: t('shifts.hours'), render: (r) => `${String(r.start_time).slice(0, 5)}–${String(r.end_time).slice(0, 5)}` },
        { label: t('settings.rooms.branch'), render: (r) => (r.branch as { name?: string } | null)?.name ?? '' },
      ]}
      fields={[
        { name: 'branch_id', label: t('settings.rooms.branch'), type: 'select', options: branches.map((b) => ({ value: String(b.id), label: b.name })) },
        { name: 'name', label: t('shifts.name') },
        { name: 'start_time', label: t('shifts.start'), type: 'time' },
        { name: 'end_time', label: t('shifts.end'), type: 'time' },
      ]}
    />
  )
}
