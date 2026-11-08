import { useEffect, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { PlusIcon, Trash2Icon } from 'lucide-react'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { Select } from '@/components/ui/Select'
import { ApiError } from '@/lib/api'
import {
  useAutomations,
  useDeleteRule,
  useSaveQuiet,
  useSaveRule,
  useSetAutomation,
  useTestRule,
  type AutomationItem,
  type AutomationRule,
  type Builder,
  type Quiet,
  type RuleAction,
  type RuleCondition,
} from '@/lib/automation'
import { cn } from '@/lib/cn'

const GROUPS = ['bookings', 'money', 'rooms', 'staff', 'revenue'] as const
const errorOf = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : e ? String(e) : null)

/** Settings → Automations (automation.md): ready-made automations, quiet hours and your own rules. */
export function AutomationsSettings() {
  const { t } = useTranslation()
  const q = useAutomations()
  if (q.isPending) return <p className="text-sm text-muted">{t('common.loading')}</p>
  if (q.isError) return <Alert>{errorOf(q.error)}</Alert>
  const { data, quiet, rules, builder } = q.data

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-base font-semibold">{t('settings.sections.automations')}</h2>
        <p className="text-sm text-muted">{t('automations.help')}</p>
      </div>
      <QuietHours initial={quiet} />
      {GROUPS.map((g) => (
        <Card key={g} className="p-0">
          <h3 className="border-b border-border px-5 py-3 text-sm font-semibold">{t(`automations.groups.${g}`)}</h3>
          <ul className="divide-y divide-border">
            {data.filter((a) => a.group === g).map((a) => (
              <AutomationRow key={a.key} a={a} />
            ))}
          </ul>
        </Card>
      ))}
      <Rules rules={rules} builder={builder} />
    </div>
  )
}

function Toggle({ on, label, disabled, onChange }: { on: boolean; label: string; disabled?: boolean; onChange: (v: boolean) => void }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={on}
      aria-label={label}
      disabled={disabled}
      onClick={() => onChange(!on)}
      className="flex min-h-touch shrink-0 items-center px-1 disabled:opacity-50"
    >
      {/* The template's switch: small track, brand colour when on. */}
      <span className={cn('inline-flex h-[1.15rem] w-8 items-center rounded-full border border-transparent shadow-xs transition-all', on ? 'bg-primary' : 'bg-input dark:bg-input/80')}>
        <span className={cn('size-4 rounded-full transition-transform', on ? 'translate-x-[calc(100%-2px)] bg-background dark:bg-primary-foreground' : 'translate-x-0 bg-background dark:bg-text')} />
      </span>
    </button>
  )
}

function AutomationRow({ a }: { a: AutomationItem }) {
  const { t } = useTranslation()
  const set = useSetAutomation()
  const [options, setOptions] = useState(a.options)
  useEffect(() => setOptions(a.options), [a.options])
  const names = Object.keys(a.options)
  const changed = JSON.stringify(options) !== JSON.stringify(a.options)
  const name = t(`automations.items.${a.key}.name`)

  return (
    <li className="flex flex-col gap-2 px-5 py-3">
      <div className="flex items-start gap-3">
        <div className="min-w-0 flex-1">
          <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
            {name}
            {a.urgent && <Badge tone="danger">{t('inbox.urgent')}</Badge>}
            {a.blocked && <Badge tone="grey">{t(`automations.blocked.${a.blocked}`, { module: a.module ? t(`modules.${a.module}`) : '' })}</Badge>}
          </p>
          <p className="text-xs text-muted">{t(`automations.items.${a.key}.what`)}</p>
        </div>
        <Toggle on={a.enabled} label={name} disabled={set.isPending} onChange={(enabled) => set.mutate({ key: a.key, enabled, options })} />
      </div>
      {names.length > 0 && a.enabled && (
        <div className="flex flex-wrap items-end gap-3">
          {names.map((n) => (
            <div key={n} className="w-32">
              <Field
                label={t(`automations.options.${n}`)}
                type={n === 'time' ? 'time' : 'number'}
                inputMode={n === 'time' ? undefined : 'decimal'}
                min={n === 'time' ? undefined : 0}
                value={String(options[n] ?? '')}
                onChange={(e) => setOptions({ ...options, [n]: n === 'time' || n === 'amount' ? e.target.value : Number(e.target.value) })}
              />
            </div>
          ))}
          {changed && (
            <Button variant="secondary" disabled={set.isPending} onClick={() => set.mutate({ key: a.key, enabled: a.enabled, options })}>
              {t('common.save')}
            </Button>
          )}
        </div>
      )}
      {set.error && <Alert>{errorOf(set.error)}</Alert>}
    </li>
  )
}

function QuietHours({ initial }: { initial: Quiet }) {
  const { t } = useTranslation()
  const save = useSaveQuiet()
  const [f, setF] = useState(initial)
  useEffect(() => setF(initial), [initial])
  return (
    <Card className="flex flex-col gap-3">
      <div className="flex items-start gap-3">
        <div className="min-w-0 flex-1">
          <h3 className="text-sm font-semibold">{t('automations.quiet.title')}</h3>
          <p className="text-xs text-muted">{t('automations.quiet.help')}</p>
        </div>
        <Toggle on={f.quiet_hours} label={t('automations.quiet.title')} onChange={(quiet_hours) => save.mutate({ ...f, quiet_hours })} />
      </div>
      {f.quiet_hours && (
        <div className="flex flex-wrap items-end gap-3">
          <div className="w-32">
            <Field label={t('automations.quiet.from')} type="time" value={f.quiet_from} onChange={(e) => setF({ ...f, quiet_from: e.target.value })} />
          </div>
          <div className="w-32">
            <Field label={t('automations.quiet.to')} type="time" value={f.quiet_to} onChange={(e) => setF({ ...f, quiet_to: e.target.value })} />
          </div>
          {(f.quiet_from !== initial.quiet_from || f.quiet_to !== initial.quiet_to) && (
            <Button variant="secondary" disabled={save.isPending} onClick={() => save.mutate(f)}>
              {t('common.save')}
            </Button>
          )}
        </div>
      )}
      {save.error && <Alert>{errorOf(save.error)}</Alert>}
    </Card>
  )
}

// ------------------------------------------------------------------ rule builder

function Rules({ rules, builder }: { rules: AutomationRule[]; builder: Builder }) {
  const { t } = useTranslation()
  const [editing, setEditing] = useState<AutomationRule | 'new' | null>(null)
  const remove = useDeleteRule()
  const save = useSaveRule()
  return (
    <Card className="flex flex-col gap-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h3 className="text-sm font-semibold">{t('automations.rules.title')}</h3>
          <p className="text-xs text-muted">{t('automations.rules.help')}</p>
        </div>
        {!editing && (
          <Button onClick={() => setEditing('new')}>
            <PlusIcon aria-hidden className="size-4" />
            {t('automations.rules.new')}
          </Button>
        )}
      </div>
      {editing && <RuleForm rule={editing === 'new' ? null : editing} builder={builder} onDone={() => setEditing(null)} />}
      {rules.length === 0 && !editing && <p className="text-sm text-muted">{t('automations.rules.none')}</p>}
      <ul className="flex flex-col gap-2">
        {rules.map((r) => (
          <li key={r.id} className="flex flex-wrap items-center gap-3 rounded-lg bg-sunken px-3 py-2 text-sm">
            <Toggle on={r.enabled} label={r.name} disabled={save.isPending} onChange={(enabled) => save.mutate({ ...r, enabled, conditions: r.conditions ?? [] })} />
            <div className="min-w-0 flex-1">
              <p className="font-medium">{r.name}</p>
              <p className="text-xs text-muted">
                {t(`automations.triggers.${r.trigger}`)} · {t('automations.rules.fired', { count: r.fired_count })}
              </p>
            </div>
            <Button variant="ghost" onClick={() => setEditing(r)}>
              {t('common.edit')}
            </Button>
            <button type="button" aria-label={t('common.delete')} disabled={remove.isPending} onClick={() => window.confirm(t('automations.rules.deleteConfirm', { name: r.name })) && remove.mutate(r.id)} className="flex size-touch items-center justify-center rounded-md text-muted hover:bg-danger-bg hover:text-danger-text">
              <Trash2Icon aria-hidden className="size-4" />
            </button>
          </li>
        ))}
      </ul>
    </Card>
  )
}

const FIELD_FOR: Record<string, string[]> = {
  booking: ['booking_created', 'booking_confirmed', 'booking_cancelled', 'checked_in', 'checked_out'],
  money: ['booking_created', 'booking_confirmed', 'booking_cancelled', 'checked_in', 'checked_out', 'payment_received'],
  payment: ['payment_received'],
  room: ['room_status_changed'],
}

function fieldsFor(trigger: string, builder: Builder): string[] {
  return Object.entries(builder.fields)
    .filter(([, scope]) => scope === 'all' || FIELD_FOR[scope]?.includes(trigger))
    .map(([f]) => f)
    .filter((f) => trigger !== 'daily' || f === 'weekday')
}

function ValueInput({ field, value, builder, onChange, label }: { field: string; value: string | number; builder: Builder; onChange: (v: string) => void; label: string }) {
  const { t } = useTranslation()
  const options: { id: string | number; name: string }[] | null =
    field === 'branch_id' ? builder.branches
    : field === 'room_type_id' ? builder.room_types
    : field === 'guest_type_id' ? builder.guest_types
    : field === 'weekday' ? [1, 2, 3, 4, 5, 6, 7].map((d) => ({ id: d, name: t(`common.weekdays.${d}`) }))
    : field === 'source' ? ['walk_in', 'phone', 'email', 'agent', 'other'].map((s) => ({ id: s, name: t(`reservations.sources.${s}`) }))
    : field === 'stay_type' ? ['nightly', 'day_use', 'hourly'].map((s) => ({ id: s, name: t(`reservations.stayTypes.${s}`) }))
    : field === 'room_status' ? ['available', 'occupied', 'dirty', 'cleaning', 'maintenance', 'out_of_order'].map((s) => ({ id: s, name: t(`roomStatus.${s}`) }))
    : field === 'payment_method_type' ? ['cash', 'mobile_money', 'bank_transfer'].map((s) => ({ id: s, name: t(`automations.methodTypes.${s}`) }))
    : null
  if (options)
    return (
      <Select label={label} value={String(value)} onChange={(e) => onChange(e.target.value)}>
        <option value="">—</option>
        {options.map((o) => (
          <option key={o.id} value={o.id}>
            {o.name}
          </option>
        ))}
      </Select>
    )
  return <Field label={label} type={field === 'time' ? 'time' : field === 'nationality' ? 'text' : 'number'} value={String(value)} onChange={(e) => onChange(e.target.value)} />
}

function RuleForm({ rule, builder, onDone }: { rule: AutomationRule | null; builder: Builder; onDone: () => void }) {
  const { t } = useTranslation()
  const save = useSaveRule()
  const test = useTestRule()
  const [name, setName] = useState(rule?.name ?? '')
  const [trigger, setTrigger] = useState(rule?.trigger ?? 'booking_created')
  const [conditions, setConditions] = useState<RuleCondition[]>(rule?.conditions ?? [])
  const [actions, setActions] = useState<RuleAction[]>(rule?.actions ?? [{ type: 'notify', message: '', permission: 'frontdesk.check_in' }])
  const fields = fieldsFor(trigger, builder)
  const actionTypes = builder.actions.filter((a) => (['housekeeping_task', 'room_status'].includes(a) ? trigger === 'room_status_changed' : a === 'booking_note' ? !['room_status_changed', 'daily'].includes(trigger) : true))
  const time = conditions.find((c) => c.field === 'time')?.value ?? '08:00'
  const shown = conditions.filter((c) => c.field !== 'time')
  const setShown = (list: RuleCondition[]) => setConditions(trigger === 'daily' ? [...list, { field: 'time', op: 'is', value: time }] : list)

  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate({ id: rule?.id, name: name.trim(), trigger, conditions: trigger === 'daily' ? [...shown, { field: 'time', op: 'is', value: time }] : shown, actions, enabled: rule?.enabled ?? true }, { onSuccess: onDone })
  }

  return (
    <form onSubmit={submit} className="flex flex-col gap-4 rounded-lg border p-4">
      {save.error && <Alert>{errorOf(save.error)}</Alert>}
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t('automations.rules.name')} value={name} required maxLength={120} onChange={(e) => setName(e.target.value)} />
        <Select
          label={t('automations.rules.when')}
          value={trigger}
          onChange={(e) => {
            setTrigger(e.target.value)
            setConditions([])
            setActions((list) => list.filter((a) => a.type === 'notify'))
            test.reset()
          }}
        >
          {builder.triggers.map((x) => (
            <option key={x} value={x}>
              {t(`automations.triggers.${x}`)}
            </option>
          ))}
        </Select>
        {trigger === 'daily' && <Field label={t('automations.options.time')} type="time" value={String(time)} onChange={(e) => setConditions([...shown, { field: 'time', op: 'is', value: e.target.value }])} />}
      </div>

      <fieldset className="flex flex-col gap-2">
        <legend className="mb-1 text-sm font-semibold">{t('automations.rules.if')}</legend>
        {shown.length === 0 && <p className="text-xs text-muted">{t('automations.rules.always')}</p>}
        {shown.map((c, i) => (
          <div key={i} className="grid items-end gap-2 sm:grid-cols-[1fr_1fr_1fr_auto]">
            <Select label={t('automations.rules.field')} value={c.field} onChange={(e) => setShown(shown.map((x, j) => (j === i ? { ...x, field: e.target.value, value: '' } : x)))}>
              {fields.map((f) => (
                <option key={f} value={f}>
                  {t(`automations.fields.${f}`)}
                </option>
              ))}
            </Select>
            <Select label={t('automations.rules.op')} value={c.op} onChange={(e) => setShown(shown.map((x, j) => (j === i ? { ...x, op: e.target.value } : x)))}>
              {builder.ops.filter((o) => (['nights', 'amount'].includes(c.field) ? true : ['is', 'is_not'].includes(o))).map((o) => (
                <option key={o} value={o}>
                  {t(`automations.ops.${o}`)}
                </option>
              ))}
            </Select>
            <ValueInput field={c.field} value={c.value} builder={builder} label={t('automations.rules.value')} onChange={(v) => setShown(shown.map((x, j) => (j === i ? { ...x, value: v } : x)))} />
            <button type="button" aria-label={t('common.delete')} onClick={() => setShown(shown.filter((_, j) => j !== i))} className="flex size-touch items-center justify-center rounded-md text-muted hover:bg-danger-bg hover:text-danger-text">
              <Trash2Icon aria-hidden className="size-4" />
            </button>
          </div>
        ))}
        {fields.length > 0 && shown.length < 10 && (
          <div>
            <Button variant="ghost" onClick={() => setShown([...shown, { field: fields[0], op: 'is', value: '' }])}>
              <PlusIcon aria-hidden className="size-4" />
              {t('automations.rules.addCondition')}
            </Button>
          </div>
        )}
      </fieldset>

      <fieldset className="flex flex-col gap-2">
        <legend className="mb-1 text-sm font-semibold">{t('automations.rules.then')}</legend>
        {actions.map((a, i) => (
          <div key={i} className="grid items-end gap-2 rounded-lg bg-sunken p-3 sm:grid-cols-[1fr_2fr_auto]">
            <Select label={t('automations.rules.action')} value={a.type} onChange={(e) => setActions(actions.map((x, j) => (j === i ? { type: e.target.value, message: x.message } : x)))}>
              {actionTypes.map((x) => (
                <option key={x} value={x}>
                  {t(`automations.actions.${x}`)}
                </option>
              ))}
            </Select>
            <div className="grid gap-2 sm:grid-cols-2">
              {a.type !== 'room_status' && <Field label={t('automations.rules.message')} value={a.message ?? ''} maxLength={200} onChange={(e) => setActions(actions.map((x, j) => (j === i ? { ...x, message: e.target.value } : x)))} />}
              {a.type === 'notify' && (
                <Select label={t('automations.rules.who')} value={a.permission ?? ''} onChange={(e) => setActions(actions.map((x, j) => (j === i ? { ...x, permission: e.target.value || null } : x)))}>
                  <option value="">{t('automations.rules.admins')}</option>
                  {['frontdesk.check_in', 'housekeeping.own_tasks', 'housekeeping.assign', 'checkout.approve_balance', 'payments.view', 'staff.manage'].map((p) => (
                    <option key={p} value={p}>
                      {t(`automations.who.${p.replace('.', '_')}`)}
                    </option>
                  ))}
                </Select>
              )}
              {a.type === 'housekeeping_task' && (
                <Select label={t('automations.rules.task')} value={a.task ?? 'cleaning'} onChange={(e) => setActions(actions.map((x, j) => (j === i ? { ...x, task: e.target.value } : x)))}>
                  {['cleaning', 'inspection', 'maintenance'].map((x) => (
                    <option key={x} value={x}>
                      {t(`housekeeping.types.${x}`)}
                    </option>
                  ))}
                </Select>
              )}
              {a.type === 'room_status' && (
                <Select label={t('automations.rules.status')} value={a.status ?? 'dirty'} onChange={(e) => setActions(actions.map((x, j) => (j === i ? { ...x, status: e.target.value } : x)))}>
                  {['dirty', 'maintenance'].map((x) => (
                    <option key={x} value={x}>
                      {t(`roomStatus.${x}`)}
                    </option>
                  ))}
                </Select>
              )}
            </div>
            <button type="button" aria-label={t('common.delete')} disabled={actions.length === 1} onClick={() => setActions(actions.filter((_, j) => j !== i))} className="flex size-touch items-center justify-center rounded-md text-muted hover:bg-danger-bg hover:text-danger-text disabled:opacity-40">
              <Trash2Icon aria-hidden className="size-4" />
            </button>
          </div>
        ))}
        {actions.length < 5 && (
          <div>
            <Button variant="ghost" onClick={() => setActions([...actions, { type: 'notify', message: '' }])}>
              <PlusIcon aria-hidden className="size-4" />
              {t('automations.rules.addAction')}
            </Button>
          </div>
        )}
        <p className="text-xs text-muted">{t('automations.rules.limits')}</p>
      </fieldset>

      <div className="flex flex-wrap items-center gap-2">
        <Button type="submit" disabled={save.isPending || !name.trim()}>
          {save.isPending ? t('common.saving') : t('common.save')}
        </Button>
        <Button variant="secondary" disabled={test.isPending} onClick={() => test.mutate({ trigger, conditions: shown })}>
          {t('automations.rules.test')}
        </Button>
        <Button variant="ghost" onClick={onDone}>
          {t('common.cancel')}
        </Button>
        {test.data && <span className="text-sm font-medium">{t('automations.rules.testResult', { count: test.data.count })}</span>}
        {test.error && <span className="text-sm text-danger-text">{errorOf(test.error)}</span>}
      </div>
    </form>
  )
}
