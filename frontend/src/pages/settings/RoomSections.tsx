import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { SmartHints } from '@/components/ui/SmartHints'
import { useSmart } from '@/lib/smart'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert } from '@/components/ui/Alert'
import { Badge, type Tone } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { ResourceList } from '@/components/ui/ResourceList'
import { Select, SelectBox } from '@/components/ui/Select'
import { api, ApiError, post } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { money } from '@/lib/format'
import { useSettingsList } from '@/lib/settings'
import { ImportRooms } from './ImportSections'

export { ImportSettings } from './ImportSections'

type RoomType = { id: number; branch_id: number; name: string; nightly_price: string; currency: string; rooms_count?: number }
type Limit = { used: number; max: number | null }

/** Branch picker shared by the room screens (only the branches the user may use). */
function useBranchPicker() {
  const me = useMe()
  const branches = me.data?.branches ?? []
  const [branchId, setBranchId] = useState<number | null>(null)
  const current = branchId ?? branches[0]?.id ?? null
  return { branches, branchId: current, setBranchId }
}

function BranchSelect({ branches, value, onChange }: { branches: { id: number; name: string }[]; value: number | null; onChange: (id: number) => void }) {
  const { t } = useTranslation()
  if (branches.length < 2) return null
  return (
    <div className="max-w-60">
      <Select label={t('settings.rooms.branch')} value={String(value ?? '')} onChange={(e) => onChange(Number(e.target.value))}>
        {branches.map((b) => (
          <option key={b.id} value={b.id}>
            {b.name}
          </option>
        ))}
      </Select>
    </div>
  )
}

/* ------------------------------------------------------------- room types */

export function RoomTypesSettings() {
  const { t } = useTranslation()
  const { branches, branchId, setBranchId } = useBranchPicker()
  if (!branchId) return null
  return (
    <ResourceList
      endpoint="/settings/room-types"
      query={`?branch_id=${branchId}`}
      title={t('settings.sections.room-types')}
      description={t('settings.roomTypes.help')}
      addLabel={t('settings.roomTypes.add')}
      header={<BranchSelect branches={branches} value={branchId} onChange={setBranchId} />}
      defaults={{ branch_id: branchId, name: '', description: '', base_capacity: 2, max_capacity: 2, nightly_price: '', day_use_price: '', hourly_price: '', currency: 'ETB', amenities: '' }}
      canDelete={(r) => !r.rooms_count}
      columns={[
        { label: t('settings.roomTypes.name'), render: (r) => <span className="font-medium">{String(r.name)}</span> },
        { label: t('settings.roomTypes.nightly'), render: (r) => money(String(r.nightly_price), String(r.currency)) },
        { label: t('settings.roomTypes.dayUse'), render: (r) => (r.day_use_price ? money(String(r.day_use_price), String(r.currency)) : '—') },
        { label: t('settings.roomTypes.guests'), render: (r) => (r.base_capacity === r.max_capacity ? String(r.max_capacity) : `${String(r.base_capacity)}–${String(r.max_capacity)}`) },
        { label: t('settings.roomTypes.rooms'), render: (r) => String(r.rooms_count ?? 0) },
      ]}
      fields={[
        { name: 'branch_id', label: t('settings.rooms.branch'), type: 'select', options: branches.map((b) => ({ value: String(b.id), label: b.name })) },
        { name: 'name', label: t('settings.roomTypes.name') },
        { name: 'description', label: t('settings.roomTypes.description'), nullable: true, wide: true },
        { name: 'base_capacity', label: t('settings.roomTypes.baseCapacity'), type: 'number' },
        { name: 'max_capacity', label: t('settings.roomTypes.maxCapacity'), type: 'number' },
        { name: 'nightly_price', label: t('settings.roomTypes.nightly'), type: 'decimal' },
        { name: 'currency', label: t('settings.roomTypes.currency') },
        { name: 'day_use_price', label: t('settings.roomTypes.dayUse'), type: 'decimal', nullable: true, hint: t('settings.roomTypes.optional') },
        { name: 'hourly_price', label: t('settings.roomTypes.hourly'), type: 'decimal', nullable: true, hint: t('settings.roomTypes.optional') },
        { name: 'amenities', label: t('settings.roomTypes.amenities'), type: 'tags', hint: t('settings.roomTypes.amenitiesHint'), wide: true },
      ]}
    />
  )
}

/* ------------------------------------------------------------- rooms */

export function RoomsSettings() {
  const { t } = useTranslation()
  const { branches, branchId, setBranchId } = useBranchPicker()
  const types = useSettingsList<RoomType>(`/settings/room-types?branch_id=${branchId ?? ''}`)
  const rooms = useSettingsList<Record<string, unknown>>(`/settings/rooms?branch_id=${branchId ?? ''}`)
  const limit = rooms.data?.limit as Limit | undefined
  const smart = useSmart('rooms_settings')
  const statuses = ['available', 'occupied', 'dirty', 'cleaning', 'maintenance', 'out_of_order']
  const typeOptions = (types.data?.data ?? []).map((x) => ({ value: String(x.id), label: x.name }))

  if (!branchId) return null
  if (types.data && !types.data.data.length) {
    return (
      <div className="flex flex-col gap-4">
        <h2 className="text-base font-semibold">{t('settings.sections.rooms')}</h2>
        <BranchSelect branches={branches} value={branchId} onChange={setBranchId} />
        <Alert tone="info">{t('settings.rooms.noTypes')}</Alert>
        <ImportToggle />
      </div>
    )
  }

  return (
    <ResourceList
      endpoint="/settings/rooms"
      query={`?branch_id=${branchId}`}
      title={t('settings.sections.rooms')}
      description={t('settings.rooms.help')}
      addLabel={t('settings.rooms.addOne')}
      header={
        <div className="flex flex-col gap-4">
          <div className="flex flex-wrap items-end gap-4">
            <BranchSelect branches={branches} value={branchId} onChange={setBranchId} />
            {limit && (
              <Badge tone={limit.max !== null && limit.used >= limit.max ? 'warn' : 'grey'}>
                {limit.max === null ? t('settings.rooms.usedUnlimited', { used: limit.used }) : t('settings.rooms.used', { used: limit.used, max: limit.max })}
              </Badge>
            )}
          </div>
          <SmartHints hints={smart.data?.data} />
          {types.data && <RoomGenerator key={branchId} types={types.data.data} />}
          <ImportToggle />
        </div>
      }
      defaults={{ room_type_id: typeOptions[0]?.value ?? '', number: '', floor: '', status: 'available', notes: '' }}
      columns={[
        { label: t('settings.rooms.number'), render: (r) => <span className="font-medium">{String(r.number)}</span> },
        { label: t('settings.rooms.floor'), render: (r) => String(r.floor ?? '—') },
        { label: t('settings.rooms.type'), render: (r) => String((r.room_type as { name?: string } | null)?.name ?? '—') },
        { label: t('settings.rooms.status'), render: (r) => <RoomStatus status={String(r.status)} /> },
      ]}
      fields={[
        { name: 'number', label: t('settings.rooms.number') },
        { name: 'floor', label: t('settings.rooms.floor'), nullable: true },
        { name: 'room_type_id', label: t('settings.rooms.type'), type: 'select', options: typeOptions },
        { name: 'status', label: t('settings.rooms.status'), type: 'select', options: statuses.map((s) => ({ value: s, label: t(`roomStatus.${s}`) })) },
        { name: 'notes', label: t('settings.rooms.notes'), nullable: true, wide: true },
      ]}
    />
  )
}

const roomTones: Record<string, Tone> = { available: 'ok', occupied: 'info', dirty: 'warn', cleaning: 'warn', maintenance: 'danger', out_of_order: 'danger' }

function RoomStatus({ status }: { status: string }) {
  const { t } = useTranslation()
  return <Badge tone={roomTones[status] ?? 'grey'}>{t(`roomStatus.${status}`)}</Badge>
}

/** "Import from Excel" opens the importer in place (also used in the setup walkthrough). */
function ImportToggle() {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  return open ? (
    <ImportRooms onDone={() => setOpen(false)} />
  ) : (
    <div>
      <Button variant="ghost" onClick={() => setOpen(true)}>
        {t('import.fromExcel')}
      </Button>
    </div>
  )
}

type Planned = { number: string; floor: string; room_type_id: number; room_type: string }

/** "Floors × rooms per floor" generator with a live preview before anything is saved. */
function RoomGenerator({ types }: { types: RoomType[] }) {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const [f, setF] = useState({ room_type_id: types[0]?.id ?? 0, first_floor: 1, floors: 1, rooms_per_floor: 10, start_number: 1 })
  const [byPosition, setByPosition] = useState<Record<number, number>>({})
  const body = { ...f, types: byPosition }

  const preview = useQuery({
    queryKey: ['room-generator', body],
    queryFn: () => post<{ data: Planned[]; existing: string[]; limit: Limit }>('/settings/rooms/generate', { ...body, preview: true }),
    enabled: open && f.floors > 0 && f.rooms_per_floor > 0,
    staleTime: 30_000,
  })
  const create = useMutation({
    mutationFn: () => post<{ created: number }>('/settings/rooms/generate', body),
    onSuccess: () => {
      setOpen(false)
      void qc.invalidateQueries({ queryKey: ['settings'] })
    },
  })
  const error = create.error instanceof ApiError ? create.error : null
  const num = (k: keyof typeof f) => (e: { target: { value: string } }) => setF({ ...f, [k]: Math.max(0, Number(e.target.value) || 0) })
  const plan = preview.data?.data ?? []
  const existing = new Set(preview.data?.existing ?? [])
  const lim = preview.data?.limit
  const over = lim && lim.max !== null && lim.used + plan.length > lim.max

  if (!open) {
    return (
      <div>
        <Button variant="secondary" onClick={() => setOpen(true)}>
          {t('settings.rooms.generator')}
        </Button>
      </div>
    )
  }

  return (
    <Card className="animate-page-in flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <h3 className="text-sm font-semibold">{t('settings.rooms.generator')}</h3>
        <p className="text-sm text-muted">{t('settings.rooms.generatorHelp')}</p>
      </div>
      <div className="grid gap-4 sm:grid-cols-3">
        <Select label={t('settings.rooms.type')} value={String(f.room_type_id)} onChange={(e) => setF({ ...f, room_type_id: Number(e.target.value) })}>
          {types.map((x) => (
            <option key={x.id} value={x.id}>
              {x.name}
            </option>
          ))}
        </Select>
        <Field label={t('settings.rooms.firstFloor')} type="number" value={String(f.first_floor)} onChange={num('first_floor')} hint={t('settings.rooms.groundHint')} />
        <Field label={t('settings.rooms.floors')} type="number" value={String(f.floors)} onChange={num('floors')} />
        <Field label={t('settings.rooms.perFloor')} type="number" value={String(f.rooms_per_floor)} onChange={num('rooms_per_floor')} />
        <Field label={t('settings.rooms.startNumber')} type="number" value={String(f.start_number)} onChange={num('start_number')} />
      </div>

      {types.length > 1 && f.rooms_per_floor > 0 && f.rooms_per_floor <= 30 && (
        <div className="flex flex-col gap-2">
          <span className="text-sm font-medium">{t('settings.rooms.typeByPosition')}</span>
          <div className="flex flex-wrap gap-2">
            {Array.from({ length: f.rooms_per_floor }, (_, i) => i + 1).map((pos) => (
              <label key={pos} className="flex items-center gap-1.5 text-xs text-muted">
                <span className="w-5 text-right">{pos}</span>
                <SelectBox
                  aria-label={t('settings.rooms.position', { n: pos })}
                  className="w-auto ps-2"
                  value={byPosition[pos] ?? f.room_type_id}
                  onChange={(e) => {
                    const id = Number(e.target.value)
                    setByPosition((cur) => {
                      const next = { ...cur }
                      if (id === f.room_type_id) delete next[pos]
                      else next[pos] = id
                      return next
                    })
                  }}
                >
                  {types.map((x) => (
                    <option key={x.id} value={x.id}>
                      {x.name}
                    </option>
                  ))}
                </SelectBox>
              </label>
            ))}
          </div>
        </div>
      )}

      {preview.error instanceof ApiError && <Alert>{Object.values(preview.error.errors)[0]?.[0] ?? preview.error.message}</Alert>}
      {plan.length > 0 && (
        <div className="flex flex-col gap-2">
          <span className="text-sm text-muted">{t('settings.rooms.previewCount', { count: plan.length })}</span>
          <div className="flex max-h-48 flex-wrap gap-1.5 overflow-y-auto rounded-lg bg-sunken p-3">
            {plan.map((p) => (
              <span
                key={p.number}
                title={p.room_type}
                className={`rounded-md border px-2 py-1 text-xs font-medium ${existing.has(p.number) ? 'border-danger bg-danger-bg text-danger-text' : 'bg-surface'}`}
              >
                {p.number}
              </span>
            ))}
          </div>
          {existing.size > 0 && <Alert>{t('settings.rooms.exists', { list: [...existing].slice(0, 10).join(', ') })}</Alert>}
          {over && lim && <Alert>{t('settings.rooms.overLimit', { max: lim.max, used: lim.used })}</Alert>}
        </div>
      )}
      {error && <Alert>{Object.values(error.errors)[0]?.[0] ?? error.message}</Alert>}

      <div className="flex justify-end gap-2">
        <Button variant="ghost" onClick={() => setOpen(false)}>
          {t('common.cancel')}
        </Button>
        <Button disabled={!plan.length || existing.size > 0 || !!over || create.isPending} onClick={() => create.mutate()}>
          {t('settings.rooms.create', { count: plan.length })}
        </Button>
      </div>
    </Card>
  )
}

/* ------------------------------------------------------------- pricing */

export function GuestTypesSettings() {
  const { t } = useTranslation()
  return (
    <ResourceList
      endpoint="/settings/guest-types"
      title={t('settings.sections.guest-types')}
      description={t('settings.guestTypes.help')}
      addLabel={t('settings.guestTypes.add')}
      defaults={{ name: '' }}
      columns={[{ label: t('settings.guestTypes.name'), render: (r) => <span className="font-medium">{String(r.name)}</span> }]}
      fields={[{ name: 'name', label: t('settings.guestTypes.name') }]}
    />
  )
}

export function RateRulesSettings() {
  const { t } = useTranslation()
  const guestTypes = useSettingsList<{ id: number; name: string }>('/settings/guest-types')
  const roomTypes = useSettingsList<RoomType>('/settings/room-types')
  const me = useMe().data
  const branches = me?.branches ?? []
  // "All room types" changes every branch's prices: only for the Hotel Admin (others pick their own branch's types).
  const allTypesOption = me?.role?.is_hotel_admin ? [{ value: '', label: t('settings.rates.allTypes') }] : []
  // With more than one branch, each branch has its own "Standard": say which one.
  const typeName = (x: RoomType) => (branches.length > 1 ? `${x.name} · ${branches.find((b) => b.id === x.branch_id)?.name ?? ''}` : x.name)
  const typeLabel = (id: unknown) => {
    const x = roomTypes.data?.data.find((y) => y.id === id)
    return x ? typeName(x) : t('settings.rates.allTypes')
  }
  const ruleTypes = ['weekend', 'season', 'guest_type'].map((v) => ({ value: v, label: t(`settings.rates.types.${v}`) }))
  const adjustments = ['percent', 'amount', 'fixed_price'].map((v) => ({ value: v, label: t(`settings.rates.adjustments.${v}`) }))
  const describe = (r: Record<string, unknown>) => {
    const v = Number(r.value)
    if (r.adjustment === 'fixed_price') return money(String(r.value))
    const sign = v > 0 ? '+' : ''
    return r.adjustment === 'percent' ? `${sign}${v}%` : `${sign}${money(String(r.value))}`
  }
  const when = (r: Record<string, unknown>) => {
    if (r.rule_type === 'weekend') return ((r.days_of_week as number[]) ?? []).map((d) => t(`common.weekdays.${d}`)).join(', ')
    if (r.rule_type === 'season') return `${String(r.start_date).slice(0, 10)} → ${String(r.end_date).slice(0, 10)}`
    return guestTypes.data?.data.find((g) => g.id === r.guest_type_id)?.name ?? '—'
  }

  return (
    <div className="flex flex-col gap-8">
      <ResourceList
        endpoint="/settings/rate-rules"
        title={t('settings.sections.rate-rules')}
        description={t('settings.rates.help')}
        addLabel={t('settings.rates.add')}
        defaults={{ name: '', rule_type: 'weekend', room_type_id: me?.role?.is_hotel_admin ? '' : String(roomTypes.data?.data[0]?.id ?? ''), days_of_week: [5, 6], start_date: '', end_date: '', guest_type_id: '', adjustment: 'percent', value: '', priority: 10, is_active: true }}
        columns={[
          { label: t('settings.rates.name'), render: (r) => <span className="font-medium">{String(r.name)}</span> },
          { label: t('settings.rates.when'), render: when },
          { label: t('settings.rates.roomType'), render: (r) => typeLabel(r.room_type_id) },
          { label: t('settings.rates.change'), render: describe },
          { label: t('settings.rates.priority'), render: (r) => String(r.priority) },
          { label: t('common.active'), render: (r) => (r.is_active ? <Badge tone="ok">{t('common.on')}</Badge> : <Badge tone="grey">{t('common.off')}</Badge>) },
        ]}
        fields={[
          { name: 'name', label: t('settings.rates.name') },
          { name: 'rule_type', label: t('settings.rates.type'), type: 'select', options: ruleTypes },
          { name: 'days_of_week', label: t('settings.rates.days'), type: 'days', wide: true, showIf: (v) => v.rule_type === 'weekend' },
          { name: 'start_date', label: t('settings.rates.from'), type: 'date', showIf: (v) => v.rule_type === 'season' },
          { name: 'end_date', label: t('settings.rates.to'), type: 'date', showIf: (v) => v.rule_type === 'season' },
          {
            name: 'guest_type_id',
            label: t('settings.rates.guestType'),
            type: 'select',
            showIf: (v) => v.rule_type === 'guest_type',
            options: [{ value: '', label: '—' }, ...(guestTypes.data?.data ?? []).map((g) => ({ value: String(g.id), label: g.name }))],
          },
          {
            name: 'room_type_id',
            label: t('settings.rates.roomType'),
            type: 'select',
            nullable: true,
            options: [...allTypesOption, ...(roomTypes.data?.data ?? []).map((x) => ({ value: String(x.id), label: typeName(x) }))],
          },
          { name: 'adjustment', label: t('settings.rates.adjustment'), type: 'select', options: adjustments },
          { name: 'value', label: t('settings.rates.value'), type: 'decimal', hint: t('settings.rates.valueHint') },
          { name: 'priority', label: t('settings.rates.priority'), type: 'number', hint: t('settings.rates.priorityHint') },
          { name: 'is_active', label: t('common.active'), type: 'checkbox' },
        ]}
      />
      <PricePreview types={roomTypes.data?.data ?? []} typeName={typeName} guestTypes={guestTypes.data?.data ?? []} />
    </div>
  )
}

type Stay = { total: string; currency: string; nights: { date: string; amount: string; rule: { name: string } | null }[] }

/** Try the rules: price of a stay night by night. */
function PricePreview({ types, typeName, guestTypes }: { types: RoomType[]; typeName: (x: RoomType) => string; guestTypes: { id: number; name: string }[] }) {
  const { t } = useTranslation()
  const today = new Date()
  const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  const [typeId, setTypeId] = useState<number | null>(null)
  const [guest, setGuest] = useState('')
  const [from, setFrom] = useState(iso(today))
  const [to, setTo] = useState(iso(new Date(today.getFullYear(), today.getMonth(), today.getDate() + 3)))
  const current = typeId ?? types[0]?.id
  const q = useQuery({
    queryKey: ['price-preview', current, from, to, guest],
    queryFn: () => api<{ data: Stay }>(`/settings/price-preview?room_type_id=${current}&from=${from}&to=${to}${guest ? `&guest_type_id=${guest}` : ''}`),
    enabled: !!current && from < to,
  })
  const stay = q.data?.data
  if (!types.length) return null

  return (
    <Card className="flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <h3 className="text-sm font-semibold">{t('settings.rates.preview')}</h3>
        <p className="text-sm text-muted">{t('settings.rates.previewHelp')}</p>
      </div>
      <div className="grid gap-4 sm:grid-cols-4">
        <Select label={t('settings.rates.roomType')} value={String(current)} onChange={(e) => setTypeId(Number(e.target.value))}>
          {types.map((x) => (
            <option key={x.id} value={x.id}>
              {typeName(x)}
            </option>
          ))}
        </Select>
        <Select label={t('settings.rates.guestType')} value={guest} onChange={(e) => setGuest(e.target.value)}>
          <option value="">—</option>
          {guestTypes.map((g) => (
            <option key={g.id} value={g.id}>
              {g.name}
            </option>
          ))}
        </Select>
        <Field label={t('settings.rates.checkIn')} type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        <Field label={t('settings.rates.checkOut')} type="date" value={to} onChange={(e) => setTo(e.target.value)} />
      </div>
      {q.error instanceof ApiError && <Alert>{Object.values(q.error.errors)[0]?.[0] ?? q.error.message}</Alert>}
      {stay && (
        <div className="flex flex-col gap-1 text-sm">
          {stay.nights.map((n) => (
            <div key={n.date} className="flex justify-between gap-4 border-b border-border py-1.5">
              <span>
                {n.date} {n.rule && <span className="text-muted">· {n.rule.name}</span>}
              </span>
              <span className="tabular-nums">{money(n.amount, stay.currency)}</span>
            </div>
          ))}
          <div className="flex justify-between gap-4 pt-1.5 font-semibold">
            <span>{t('settings.rates.total')}</span>
            <span className="tabular-nums">{money(stay.total, stay.currency)}</span>
          </div>
        </div>
      )}
    </Card>
  )
}
