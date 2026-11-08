import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { BookmarkIcon, Trash2Icon } from 'lucide-react'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Bars } from '@/components/ui/Delta'
import { Field } from '@/components/ui/Field'
import { Select } from '@/components/ui/Select'
import { ApiError } from '@/lib/api'
import {
  useAlertRules,
  useCustomReports,
  useDeleteCustomReport,
  useDeleteSchedule,
  useDeleteView,
  useRunCustom,
  useSaveAlertRule,
  useSaveCustomReport,
  useSaveSchedule,
  useSavedViews,
  useSaveView,
  useSchedules,
  useTestSchedule,
  type AlertRule,
  type Person,
  type Schedule,
} from '@/lib/automation'
import { cn } from '@/lib/cn'
import { date, money } from '@/lib/format'
import { localDate, localTime } from '@/lib/front'
import { useCan, useRoles } from '@/lib/hotel'
import type { ReportFilters } from '@/lib/reports'

const err = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : null)
const MONEY = ['room_revenue', 'booking_value', 'payments']

/* ------------------------------------------------------------- saved views */

/** Saved filter sets for a report: pick one to apply it, or save the current filters. */
export function SavedViews({ reportKey, f, apply }: { reportKey: string; f: ReportFilters; apply: (f: ReportFilters) => void }) {
  const { t } = useTranslation()
  const can = useCan()
  const views = useSavedViews(reportKey)
  const save = useSaveView()
  const remove = useDeleteView()
  const [naming, setNaming] = useState(false)
  const roles = useRoles(naming && can('roles.manage'))
  const [name, setName] = useState('')
  const [shareRole, setShareRole] = useState('')

  return (
    <div className="flex flex-wrap items-center gap-1.5 print:hidden">
      {views.data?.data.map((v) => (
        <span key={v.id} className="flex items-center rounded-full bg-surface border">
          <button type="button" onClick={() => apply({ from: String(v.filters.from ?? f.from), to: String(v.filters.to ?? f.to), compare: (v.filters.compare as ReportFilters['compare']) ?? 'prev', branch_id: v.filters.branch_id ? Number(v.filters.branch_id) : null })} className="flex min-h-touch items-center gap-1 px-3 text-sm font-medium">
            {v.is_pinned && <BookmarkIcon aria-hidden className="size-3.5 text-accent-text" />}
            {v.name}
          </button>
          {v.mine && (
            <button type="button" aria-label={t('common.delete')} onClick={() => remove.mutate(v.id)} className="flex size-8 items-center justify-center text-icon hover:text-danger-text">
              <Trash2Icon aria-hidden className="size-3.5" />
            </button>
          )}
        </span>
      ))}
      {naming ? (
        <span className="flex flex-wrap items-end gap-2">
          <span className="w-44">
            <Field label={t('automation.viewName')} value={name} onChange={(e) => setName(e.target.value)} />
          </span>
          {can('roles.manage') && (
            <span className="w-40">
              <Select label={t('automation.shareWith')} value={shareRole} onChange={(e) => setShareRole(e.target.value)}>
                <option value="">{t('automation.onlyMe')}</option>
                {roles.data?.data.map((r) => (
                  <option key={r.id} value={r.id}>
                    {r.name}
                  </option>
                ))}
              </Select>
            </span>
          )}
          <Button
            disabled={!name || save.isPending}
            onClick={() =>
              save.mutate(
                { report_key: reportKey, name, filters: { from: f.from, to: f.to, compare: f.compare ?? 'prev', branch_id: f.branch_id ?? null }, is_pinned: true, shared_with_roles: shareRole ? [Number(shareRole)] : null },
                { onSuccess: () => { setNaming(false); setName('') } },
              )
            }
          >
            {t('common.save')}
          </Button>
          <Button variant="ghost" onClick={() => setNaming(false)}>
            {t('common.cancel')}
          </Button>
        </span>
      ) : (
        <Button variant="ghost" onClick={() => setNaming(true)}>
          <BookmarkIcon aria-hidden className="size-4" />
          {t('automation.saveView')}
        </Button>
      )}
    </div>
  )
}

/* ------------------------------------------------------------- custom report builder */

export function CustomReportBuilder({ f }: { f: ReportFilters }) {
  const { t } = useTranslation()
  const list = useCustomReports(true)
  const run = useRunCustom()
  const save = useSaveCustomReport()
  const remove = useDeleteCustomReport()
  const [measures, setMeasures] = useState<string[]>(['room_nights', 'room_revenue'])
  const [group, setGroup] = useState('day')
  const [chart, setChart] = useState<'table' | 'bar'>('table')
  const [name, setName] = useState('')
  const [editing, setEditing] = useState<number | null>(null)
  const result = run.data?.data
  const fmt = (m: string, v: string | number | null) => (v === null ? '—' : MONEY.includes(m) ? money(String(v), result?.currency) : String(v))

  return (
    <div className="flex flex-col gap-4">
      {(list.data?.data.length ?? 0) > 0 && (
        <div className="flex flex-wrap gap-1.5">
          {list.data?.data.map((r) => (
            <span key={r.id} className="flex items-center rounded-full bg-surface border">
              <button
                type="button"
                className="min-h-touch px-3 text-sm font-medium"
                onClick={() => {
                  setMeasures(r.measures)
                  setGroup(r.group_by)
                  setChart(r.chart_type)
                  setName(r.name)
                  setEditing(r.mine ? r.id : null)
                  run.mutate({ measures: r.measures, group_by: r.group_by, from: f.from, to: f.to, branch_id: f.branch_id })
                }}
              >
                {r.name}
              </button>
              {r.mine && (
                <button type="button" aria-label={t('common.delete')} onClick={() => remove.mutate(r.id)} className="flex size-8 items-center justify-center text-icon hover:text-danger-text">
                  <Trash2Icon aria-hidden className="size-3.5" />
                </button>
              )}
            </span>
          ))}
        </div>
      )}
      <Card className="flex flex-col gap-4">
        <fieldset className="flex flex-col gap-2">
          <legend className="mb-1 text-sm font-medium">{t('automation.measures')}</legend>
          <div className="flex flex-wrap gap-2">
            {(list.data?.measures ?? []).map((m) => (
              <label key={m} className="flex min-h-touch items-center gap-2 rounded-lg px-2 text-sm border">
                <input type="checkbox" checked={measures.includes(m)} onChange={(e) => setMeasures(e.target.checked ? [...measures, m] : measures.filter((x) => x !== m))} />
                {t(`automation.m.${m}`)}
              </label>
            ))}
          </div>
        </fieldset>
        <div className="flex flex-wrap items-end gap-3">
          <div className="w-48">
            <Select label={t('automation.groupBy')} value={group} onChange={(e) => setGroup(e.target.value)}>
              {(list.data?.groups ?? []).map((g) => (
                <option key={g} value={g}>
                  {t(`automation.g.${g}`)}
                </option>
              ))}
            </Select>
          </div>
          <div className="w-36">
            <Select label={t('automation.show')} value={chart} onChange={(e) => setChart(e.target.value as 'table' | 'bar')}>
              <option value="table">{t('automation.table')}</option>
              <option value="bar">{t('automation.bar')}</option>
            </Select>
          </div>
          <Button disabled={!measures.length || run.isPending} onClick={() => run.mutate({ measures, group_by: group, from: f.from, to: f.to, branch_id: f.branch_id })}>
            {t('automation.run')}
          </Button>
          <div className="w-48">
            <Field label={t('automation.reportName')} value={name} onChange={(e) => setName(e.target.value)} />
          </div>
          <Button variant="secondary" disabled={!name || !measures.length || save.isPending} onClick={() => save.mutate({ id: editing ?? undefined, name, measures, group_by: group, chart_type: chart })}>
            {editing ? t('automation.update') : t('automation.saveReport')}
          </Button>
        </div>
        {(run.error || save.error) && <Alert>{err(run.error ?? save.error)}</Alert>}
        {save.isSuccess && <Alert tone="ok">{t('common.saved')}</Alert>}
      </Card>
      {result && (
        <Card className="flex flex-col gap-3 overflow-x-auto">
          {chart === 'bar' && result.columns[0] && (
            <Bars label={t(`automation.m.${result.columns[0]}`)} items={result.rows.map((r) => ({ key: r.key, label: r.label, value: Number(r[result.columns[0]] ?? 0) }))} format={(v) => fmt(result.columns[0], v)} />
          )}
          <table className="w-full text-left text-sm">
            <thead className="text-muted">
              <tr>
                <th className="py-1.5 pr-3 font-medium">{t(`automation.g.${group}`)}</th>
                {result.columns.map((c) => (
                  <th key={c} className="py-1.5 pr-3 text-right font-medium">
                    {t(`automation.m.${c}`)}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {result.rows.map((r) => (
                <tr key={r.key} className="border-t border-border">
                  <td className="py-1.5 pr-3 font-medium">{['day', 'week'].includes(group) ? date(r.label) : r.label}</td>
                  {result.columns.map((c) => (
                    <td key={c} className="py-1.5 pr-3 text-right tabular-nums">
                      {fmt(c, r[c])}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
          {result.rows.length === 0 && <p className="text-center text-muted">{t('reports.nothing')}</p>}
        </Card>
      )}
    </div>
  )
}

/* ------------------------------------------------------------- schedules */

const EMPTY: Partial<Schedule> = { name: '', report_key: 'daily', frequency: 'daily', day: 1, time: '07:00', channels: ['email'], recipients: [], extra_emails: [], is_active: true }

export function SchedulesReport() {
  const { t } = useTranslation()
  const q = useSchedules()
  const custom = useCustomReports(true)
  const save = useSaveSchedule()
  const remove = useDeleteSchedule()
  const test = useTestSchedule()
  const [edit, setEdit] = useState<Partial<Schedule> | null>(null)
  const people = q.data?.people ?? []

  return (
    <div className="flex flex-col gap-4">
      <p className="max-w-prose text-sm text-muted">{t('automation.schedulesHelp')}</p>
      {test.data && (test.data.sent ? <Alert tone="ok">{t('automation.sent')}</Alert> : <Alert>{test.data.problems.join(' ')}</Alert>)}
      {(save.error || test.error) && <Alert>{err(save.error ?? test.error)}</Alert>}
      {edit ? (
        <Card className="grid gap-3 sm:grid-cols-3">
          <Field label={t('automation.reportName')} value={edit.name ?? ''} onChange={(e) => setEdit({ ...edit, name: e.target.value })} />
          <Select label={t('automation.report')} value={edit.report_key} onChange={(e) => setEdit({ ...edit, report_key: e.target.value })}>
            {(q.data?.reports ?? []).map((k) => (
              <option key={k} value={k}>
                {k === 'custom' ? t('automation.customReport') : t(`reports.names.${k}`)}
              </option>
            ))}
          </Select>
          {edit.report_key === 'custom' && (
            <Select label={t('automation.customReport')} value={String(edit.custom_report_id ?? '')} onChange={(e) => setEdit({ ...edit, custom_report_id: Number(e.target.value) || null })}>
              <option value="">—</option>
              {custom.data?.data.map((r) => (
                <option key={r.id} value={r.id}>
                  {r.name}
                </option>
              ))}
            </Select>
          )}
          <Select label={t('automation.how')} value={edit.frequency} onChange={(e) => setEdit({ ...edit, frequency: e.target.value as Schedule['frequency'] })}>
            {(['daily', 'weekly', 'monthly'] as const).map((x) => (
              <option key={x} value={x}>
                {t(`automation.freq.${x}`)}
              </option>
            ))}
          </Select>
          {edit.frequency === 'weekly' && (
            <Select label={t('automation.weekday')} value={String(edit.day ?? 1)} onChange={(e) => setEdit({ ...edit, day: Number(e.target.value) })}>
              {[1, 2, 3, 4, 5, 6, 7].map((d) => (
                <option key={d} value={d}>
                  {t(`common.weekdays.${d}`)}
                </option>
              ))}
            </Select>
          )}
          {edit.frequency === 'monthly' && <Field label={t('automation.dayOfMonth')} type="number" min={1} max={28} value={String(edit.day ?? 1)} onChange={(e) => setEdit({ ...edit, day: Math.min(28, Math.max(1, Number(e.target.value) || 1)) })} />}
          <Field label={t('automation.time')} type="time" value={edit.time ?? '07:00'} onChange={(e) => setEdit({ ...edit, time: e.target.value })} />
          <Channels value={edit.channels ?? []} onChange={(channels) => setEdit({ ...edit, channels })} options={['email', 'telegram']} />
          <People people={people} value={edit.recipients ?? []} onChange={(recipients) => setEdit({ ...edit, recipients })} />
          <Field label={t('automation.extraEmails')} hint={t('automation.extraEmailsHint')} value={(edit.extra_emails ?? []).join(', ')} onChange={(e) => setEdit({ ...edit, extra_emails: e.target.value.split(',').map((x) => x.trim()).filter(Boolean) })} />
          <div className="flex justify-end gap-2 sm:col-span-3">
            <Button variant="ghost" onClick={() => setEdit(null)}>
              {t('common.cancel')}
            </Button>
            <Button disabled={!edit.name || save.isPending} onClick={() => save.mutate(edit, { onSuccess: () => setEdit(null) })}>
              {t('common.save')}
            </Button>
          </div>
        </Card>
      ) : (
        <div>
          <Button onClick={() => setEdit(EMPTY)}>{t('automation.addSchedule')}</Button>
        </div>
      )}
      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[720px] text-left text-sm">
          <tbody>
            {q.data?.data.map((s) => (
              <tr key={s.id} className="border-t border-border first:border-t-0">
                <td className="px-4 py-2">
                  <span className="font-medium">{s.name}</span>
                  <span className="block text-xs text-muted">
                    {t(`automation.freq.${s.frequency}`)} · {s.time} · {s.channels.map((c) => t(`automation.ch.${c}`)).join(', ')}
                  </span>
                </td>
                <td className="px-4 py-2 text-xs text-muted">
                  {s.next_run_local && t('automation.next', { time: `${date(localDate(s.next_run_local))} ${localTime(s.next_run_local)}` })}
                  {s.last_error && <span className="block text-danger-text">{s.last_error}</span>}
                </td>
                <td className="px-4 py-2">{s.is_active ? <Badge tone="ok">{t('common.on')}</Badge> : <Badge tone="grey">{t('common.off')}</Badge>}</td>
                <td className="px-2 py-1 text-right whitespace-nowrap">
                  <Button variant="ghost" disabled={test.isPending} onClick={() => test.mutate(s.id)}>
                    {t('automation.sendNow')}
                  </Button>
                  <Button variant="ghost" onClick={() => setEdit(s)}>
                    {t('common.edit')}
                  </Button>
                  <Button variant="ghost" aria-label={t('common.delete')} disabled={remove.isPending} onClick={() => remove.mutate(s.id)}>
                    <Trash2Icon aria-hidden className="size-4" />
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
    </div>
  )
}

function Channels({ value, onChange, options }: { value: string[]; onChange: (v: string[]) => void; options: string[] }) {
  const { t } = useTranslation()
  return (
    <fieldset className="flex flex-col gap-1.5">
      <legend className="mb-1 text-sm font-medium">{t('automation.channels')}</legend>
      <div className="flex flex-wrap gap-2">
        {options.map((c) => (
          <label key={c} className="flex min-h-touch items-center gap-2 text-sm">
            <input type="checkbox" checked={value.includes(c)} onChange={(e) => onChange(e.target.checked ? [...value, c] : value.filter((x) => x !== c))} />
            {t(`automation.ch.${c}`)}
          </label>
        ))}
      </div>
    </fieldset>
  )
}

function People({ people, value, onChange }: { people: Person[]; value: number[]; onChange: (v: number[]) => void }) {
  const { t } = useTranslation()
  return (
    <fieldset className="flex flex-col gap-1.5">
      <legend className="mb-1 text-sm font-medium">{t('automation.sendTo')}</legend>
      <div className="flex max-h-32 flex-col gap-1 overflow-y-auto rounded-lg p-2 border">
        {people.map((p) => (
          <label key={p.id} className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={value.includes(p.id)} onChange={(e) => onChange(e.target.checked ? [...value, p.id] : value.filter((x) => x !== p.id))} />
            {p.name}
          </label>
        ))}
      </div>
      {value.length === 0 && <span className="text-xs text-muted">{t('automation.nobodyMeansAdmins')}</span>}
    </fieldset>
  )
}

/* ------------------------------------------------------------- smart alerts */

export function AlertsReport() {
  const { t } = useTranslation()
  const q = useAlertRules(true)
  const save = useSaveAlertRule()
  return (
    <div className="flex flex-col gap-3">
      <p className="max-w-prose text-sm text-muted">{t('automation.alertsHelp')}</p>
      {save.error && <Alert>{err(save.error)}</Alert>}
      {q.data?.data.map((r) => (
        <AlertRuleCard key={r.id} rule={r} people={q.data.people} onSave={(v) => save.mutate(v)} saving={save.isPending} />
      ))}
    </div>
  )
}

function AlertRuleCard({ rule, people, onSave, saving }: { rule: AlertRule; people: Person[]; onSave: (r: AlertRule) => void; saving: boolean }) {
  const { t } = useTranslation()
  const [r, setR] = useState(rule)
  const [open, setOpen] = useState(false)
  return (
    <Card className={cn('flex flex-col gap-3', !r.is_active && 'opacity-70')}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-col">
          <span className="text-sm font-semibold">{t(`automation.alerts.${r.type}.title`)}</span>
          <span className="text-sm text-muted">{t(`automation.alerts.${r.type}.text`, r.threshold)}</span>
        </div>
        <div className="flex items-center gap-2">
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              role="switch"
              aria-label={t(`automation.alerts.${r.type}.title`)}
              className="size-4"
              checked={r.is_active}
              onChange={(e) => {
                const next = { ...r, is_active: e.target.checked }
                setR(next)
                onSave(next)
              }}
            />
            {r.is_active ? t('common.on') : t('common.off')}
          </label>
          <Button variant="ghost" onClick={() => setOpen(!open)}>
            {t('common.edit')}
          </Button>
        </div>
      </div>
      {open && (
        <div className="grid gap-3 sm:grid-cols-3">
          {Object.entries(r.threshold).map(([k, v]) => (
            <Field key={k} label={t(`automation.thresholds.${k}`)} inputMode="decimal" value={String(v)} onChange={(e) => setR({ ...r, threshold: { ...r.threshold, [k]: Number(e.target.value.replace(/[^0-9.]/g, '')) || 0 } })} />
          ))}
          <Channels value={r.channels} onChange={(channels) => setR({ ...r, channels })} options={['app', 'email', 'telegram']} />
          <People people={people} value={r.recipients ?? []} onChange={(recipients) => setR({ ...r, recipients })} />
          <div className="flex justify-end sm:col-span-3">
            <Button disabled={saving || !r.channels.length} onClick={() => onSave(r)}>
              {t('common.save')}
            </Button>
          </div>
        </div>
      )}
    </Card>
  )
}
