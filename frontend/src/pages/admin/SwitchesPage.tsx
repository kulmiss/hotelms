import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Select } from '@/components/ui/Select'
import { useHotels } from '@/lib/admin'
import { ApiError } from '@/lib/api'
import { useAddBanner, useRemoveBanner, useRemoveSwitch, useSetSwitch, useSwitches, type BannerRow, type SwitchDef, type SwitchRow } from '@/lib/switches'

const GROUPS: SwitchDef['group'][] = ['emergency', 'modules', 'features', 'limits']

/** "2026-11-20T09:00" from the date box → an exact time with the browser's offset. */
const toIso = (v: string) => (v ? new Date(v).toISOString() : null)
const when = (v: string | null) => (v ? new Date(v).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '')
const errorOf = (e: unknown) => (e instanceof ApiError ? e.message : e ? String(e) : null)

/** Super Admin → Switches (W4): turn features on/off for everyone, a plan or one hotel; gifts with an end date; banners. */
export function SwitchesPage() {
  const { t } = useTranslation()
  const data = useSwitches()
  const [editing, setEditing] = useState<string | null>(null)

  if (data.isPending) return <p className="text-sm text-muted">{t('common.loading')}</p>
  if (data.isError) return <Alert>{errorOf(data.error)}</Alert>
  const { registry, plans, switches, banners } = data.data

  return (
    <div className="mx-auto flex max-w-6xl flex-col gap-6">
      <PageHeader title={t('nav.switches')} subtitle={t('switches.subtitle')} />

      {GROUPS.map((group) => (
        <Card key={group} className="flex flex-col gap-1 p-0">
          <div className="border-b border-border px-5 py-3">
            <h2 className="text-sm font-semibold">{t(`switches.groups.${group}`)}</h2>
            <p className="text-xs text-muted">{t(`switches.groupHints.${group}`)}</p>
          </div>
          <ul className="divide-y divide-border">
            {registry
              .filter((d) => d.group === group)
              .map((def) => {
                const rows = switches.filter((s) => s.key === def.key)
                return (
                  <li key={def.key} className="flex flex-col gap-3 px-5 py-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <div className="min-w-0">
                        <p className="text-[13px] font-medium">{t(`switches.keys.${def.key}`)}</p>
                        <p className="text-xs text-muted">
                          {t('switches.default')}: {show(def, def.default, t)}
                        </p>
                      </div>
                      <Button variant="secondary" onClick={() => setEditing(editing === def.key ? null : def.key)} aria-expanded={editing === def.key}>
                        {editing === def.key ? t('common.cancel') : t('switches.change')}
                      </Button>
                    </div>
                    {rows.length > 0 && <SwitchRows def={def} rows={rows} />}
                    {editing === def.key && <SwitchForm def={def} plans={plans} onDone={() => setEditing(null)} />}
                  </li>
                )
              })}
          </ul>
        </Card>
      ))}

      <Banners banners={banners} plans={plans} />
    </div>
  )
}

function show(def: SwitchDef, v: boolean | number | null, t: (k: string) => string) {
  if (def.type === 'int') return v === null ? t('switches.planLimit') : String(v)
  if (v === null) return t('switches.planDecides')
  return v ? t('common.on') : t('common.off')
}

function SwitchRows({ def, rows }: { def: SwitchDef; rows: SwitchRow[] }) {
  const { t } = useTranslation()
  const remove = useRemoveSwitch()
  return (
    <ul className="flex flex-col gap-1.5">
      {rows.map((r) => (
        <li key={r.id} className="flex flex-wrap items-center gap-2 rounded-lg bg-sunken px-3 py-2 text-[13px]">
          <Badge tone={r.level === 'everyone' ? 'info' : r.level === 'plan' ? 'accent' : 'grey'}>
            {r.level === 'everyone' ? t('switches.levels.everyone') : `${t(`switches.levels.${r.level}`)}: ${r.target_name ?? `#${r.target_id}`}`}
          </Badge>
          <span className="font-medium">{show(def, r.value, t)}</span>
          {r.scheduled && <Badge tone="warn">{t('switches.startsAt', { when: when(r.starts_at) })}</Badge>}
          {r.ends_at && <Badge tone={r.expired ? 'grey' : 'ok'}>{r.expired ? t('switches.ended') : t('switches.endsAt', { when: when(r.ends_at) })}</Badge>}
          {r.reason && <span className="min-w-0 flex-1 truncate text-muted">{r.reason}</span>}
          <Button variant="ghost" className="ml-auto" disabled={remove.isPending} onClick={() => window.confirm(t('switches.removeConfirm')) && remove.mutate(r.id)}>
            {t('switches.remove')}
          </Button>
        </li>
      ))}
    </ul>
  )
}

function TargetPicker({ level, plans, value, onChange }: { level: SwitchRow['level']; plans: { id: number; name: string }[]; value: string; onChange: (v: string) => void }) {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const hotels = useHotels({ search: search || undefined })
  if (level === 'plan')
    return (
      <Select label={t('switches.plan')} value={value} onChange={(e) => onChange(e.target.value)} required>
        <option value="">—</option>
        {plans.map((p) => (
          <option key={p.id} value={p.id}>
            {p.name}
          </option>
        ))}
      </Select>
    )
  if (level !== 'hotel') return null
  return (
    <>
      <Field label={t('switches.findHotel')} type="search" value={search} onChange={(e) => setSearch(e.target.value)} />
      <Select label={t('switches.hotel')} value={value} onChange={(e) => onChange(e.target.value)} required>
        <option value="">—</option>
        {hotels.data?.data.map((h) => (
          <option key={h.id} value={h.id}>
            {h.name}
          </option>
        ))}
      </Select>
    </>
  )
}

function SwitchForm({ def, plans, onDone }: { def: SwitchDef; plans: { id: number; name: string }[]; onDone: () => void }) {
  const { t } = useTranslation()
  const save = useSetSwitch()
  const emergency = def.group === 'emergency'
  const [f, setF] = useState({ level: 'everyone' as SwitchRow['level'], target: '', value: def.type === 'int' ? '' : 'on', starts: '', ends: '', reason: '' })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (emergency && f.value === 'on' && !window.confirm(t('switches.emergencyConfirm'))) return
    save.mutate(
      {
        key: def.key,
        level: f.level,
        target_id: f.level === 'everyone' ? null : Number(f.target),
        value: def.type === 'int' ? (f.value === '' ? null : Number(f.value)) : f.value === 'on',
        starts_at: toIso(f.starts),
        ends_at: toIso(f.ends),
        reason: f.reason || undefined,
      },
      { onSuccess: onDone },
    )
  }

  return (
    <form onSubmit={submit} className="grid gap-3 rounded-lg p-3 ring-1 ring-border sm:grid-cols-2 lg:grid-cols-3">
      {save.error && (
        <div className="sm:col-span-2 lg:col-span-3">
          <Alert>{errorOf(save.error)}</Alert>
        </div>
      )}
      <Select label={t('switches.who')} value={f.level} onChange={(e) => setF({ ...f, level: e.target.value as SwitchRow['level'], target: '' })}>
        {(['everyone', 'plan', 'hotel'] as const).map((l) => (
          <option key={l} value={l}>
            {t(`switches.levels.${l}`)}
          </option>
        ))}
      </Select>
      <TargetPicker level={f.level} plans={plans} value={f.target} onChange={(target) => setF({ ...f, target })} />
      {def.type === 'int' ? (
        <Field label={t('switches.limit')} type="number" min={0} max={100000} inputMode="numeric" value={f.value} hint={t('switches.limitHint')} onChange={(e) => setF({ ...f, value: e.target.value })} />
      ) : (
        <Select label={t('switches.value')} value={f.value} onChange={(e) => setF({ ...f, value: e.target.value })}>
          <option value="on">{t('common.on')}</option>
          <option value="off">{t('common.off')}</option>
        </Select>
      )}
      <Field label={t('switches.starts')} type="datetime-local" value={f.starts} hint={t('switches.startsHint')} onChange={(e) => setF({ ...f, starts: e.target.value })} />
      <Field label={t('switches.ends')} type="datetime-local" value={f.ends} hint={t('switches.endsHint')} onChange={(e) => setF({ ...f, ends: e.target.value })} />
      <Field label={t('switches.reason')} value={f.reason} maxLength={200} onChange={(e) => setF({ ...f, reason: e.target.value })} />
      <div className="flex items-end sm:col-span-2 lg:col-span-3">
        <Button type="submit" variant={emergency && f.value === 'on' ? 'danger' : 'primary'} disabled={save.isPending}>
          {save.isPending ? t('common.saving') : t('common.save')}
        </Button>
      </div>
    </form>
  )
}

function Banners({ banners, plans }: { banners: BannerRow[]; plans: { id: number; name: string }[] }) {
  const { t } = useTranslation()
  const add = useAddBanner()
  const remove = useRemoveBanner()
  const empty = { message: '', tone: 'info' as BannerRow['tone'], plan: '', starts: '', ends: '' }
  const [f, setF] = useState(empty)

  const submit = (e: FormEvent) => {
    e.preventDefault()
    add.mutate(
      { message: f.message.trim(), tone: f.tone, plan_ids: f.plan ? [Number(f.plan)] : null, starts_at: toIso(f.starts), ends_at: toIso(f.ends) },
      { onSuccess: () => setF(empty) },
    )
  }

  return (
    <Card className="flex flex-col gap-4">
      <div>
        <h2 className="text-sm font-semibold">{t('switches.banners.title')}</h2>
        <p className="text-xs text-muted">{t('switches.banners.hint')}</p>
      </div>
      {banners.length === 0 ? (
        <p className="text-[13px] text-muted">{t('switches.banners.none')}</p>
      ) : (
        <ul className="flex flex-col gap-1.5">
          {banners.map((b) => (
            <li key={b.id} className="flex flex-wrap items-center gap-2 rounded-lg bg-sunken px-3 py-2 text-[13px]">
              <Badge tone={b.tone === 'danger' ? 'danger' : b.tone === 'warn' ? 'warn' : 'info'}>{t(`switches.banners.tones.${b.tone}`)}</Badge>
              <span className="min-w-0 flex-1">{b.message}</span>
              <span className="text-xs text-muted">
                {b.plan_ids?.length ? plans.filter((p) => b.plan_ids?.includes(p.id)).map((p) => p.name).join(', ') : b.hotel_ids?.length ? t('switches.banners.someHotels', { count: b.hotel_ids.length }) : t('switches.levels.everyone')}
                {b.ends_at && ` · ${t('switches.endsAt', { when: when(b.ends_at) })}`}
              </span>
              <Button variant="ghost" disabled={remove.isPending} onClick={() => remove.mutate(b.id)}>
                {t('switches.remove')}
              </Button>
            </li>
          ))}
        </ul>
      )}
      <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {add.error && (
          <div className="sm:col-span-2 lg:col-span-3">
            <Alert>{errorOf(add.error)}</Alert>
          </div>
        )}
        <div className="sm:col-span-2 lg:col-span-3">
          <Field label={t('switches.banners.message')} value={f.message} maxLength={300} required onChange={(e) => setF({ ...f, message: e.target.value })} />
        </div>
        <Select label={t('switches.banners.tone')} value={f.tone} onChange={(e) => setF({ ...f, tone: e.target.value as BannerRow['tone'] })}>
          {(['info', 'warn', 'danger'] as const).map((x) => (
            <option key={x} value={x}>
              {t(`switches.banners.tones.${x}`)}
            </option>
          ))}
        </Select>
        <Select label={t('switches.who')} value={f.plan} onChange={(e) => setF({ ...f, plan: e.target.value })}>
          <option value="">{t('switches.levels.everyone')}</option>
          {plans.map((p) => (
            <option key={p.id} value={p.id}>
              {t('switches.banners.planOnly', { plan: p.name })}
            </option>
          ))}
        </Select>
        <Field label={t('switches.starts')} type="datetime-local" value={f.starts} onChange={(e) => setF({ ...f, starts: e.target.value })} />
        <Field label={t('switches.ends')} type="datetime-local" value={f.ends} onChange={(e) => setF({ ...f, ends: e.target.value })} />
        <div className="flex items-end">
          <Button type="submit" disabled={add.isPending || !f.message.trim()}>
            {t('switches.banners.add')}
          </Button>
        </div>
      </form>
    </Card>
  )
}
