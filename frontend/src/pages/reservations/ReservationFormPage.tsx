import { useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { CheckIcon, MinusIcon, PlusIcon, XMarkIcon } from '@heroicons/react/20/solid'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Select } from '@/components/ui/Select'
import { Tabs } from '@/components/ui/Tabs'
import { ApiError } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { cn } from '@/lib/cn'
import { money } from '@/lib/format'
import {
  addDays,
  localDate,
  localTime,
  today,
  useAvailability,
  useGuest,
  useGuests,
  useLookups,
  useQuote,
  useReservation,
  useSaveReservation,
  type BookingInput,
  type Guest,
  type ReservationDetail,
  type RoomInput,
  type StayInput,
  type StayType,
} from '@/lib/front'
import { useCan } from '@/lib/hotel'
import { useDebounced } from '@/lib/useDebounced'
import { GuestForm } from '@/pages/guests/GuestForm'

const SOURCES = ['phone', 'walk_in', 'email', 'agent', 'other'] as const

/** New reservation, or change one (workflows.md W1). */
export function ReservationFormPage() {
  const { id } = useParams()
  const existing = useReservation(Number(id ?? 0))
  if (id && !existing.data) return null
  return <BookingForm key={id ?? 'new'} existing={existing.data?.data} />
}

function BookingForm({ existing }: { existing?: ReservationDetail }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const me = useMe()
  const can = useCan()
  const lookups = useLookups()
  const [payLater, setPayLater] = useState(false)
  const branches = me.data?.branches ?? []

  const [branchId, setBranchId] = useState<number>(existing?.branch?.id ?? branches[0]?.id ?? 0)
  const [stayType, setStayType] = useState<StayType>(existing?.stay_type ?? 'nightly')
  const [checkIn, setCheckIn] = useState(() => (existing ? (existing.stay_type === 'nightly' ? localDate(existing.check_in_at) : existing.check_in_at.slice(0, 16)) : today()))
  const [checkOut, setCheckOut] = useState(() => (existing ? (existing.stay_type === 'nightly' ? localDate(existing.check_out_at) : existing.check_out_at.slice(0, 16)) : addDays(today(), 1)))
  const [hours, setHours] = useState(() => (existing?.stay_type === 'hourly' ? Math.round((Date.parse(existing.check_out_at) - Date.parse(existing.check_in_at)) / 3_600_000) : 3))
  const [rooms, setRooms] = useState<RoomInput[]>(() => existing?.rooms.map((r) => ({ room_type_id: r.room_type_id, room_id: r.room_id, adults: r.adults, children: r.children })) ?? [])
  const [guest, setGuest] = useState<Guest | null>(existing?.guest ?? null)
  const [discount, setDiscount] = useState(existing && Number(existing.discount_percent) > 0 ? String(Number(existing.discount_percent)) : '')
  const [policyId, setPolicyId] = useState<number | null>(existing?.cancellation_policy_id ?? null)
  const [source, setSource] = useState(existing?.source ?? 'phone')
  const [notes, setNotes] = useState(existing?.notes ?? '')
  const [pickBy, setPickBy] = useState<'types' | 'rooms'>('types') // choose room types, or the actual rooms
  const preset = useGuest(Number(params.get('guest') ?? 0))
  const chosenGuest = guest ?? (preset.data && !preset.data.data.is_blacklisted ? preset.data.data : null)

  const changeStayType = (s: StayType) => {
    setStayType(s)
    setRooms([])
    const day = checkIn.slice(0, 10)
    if (s === 'nightly') {
      setCheckIn(day)
      setCheckOut(addDays(day, 1))
    } else {
      setCheckIn(`${day}T${s === 'day_use' ? '09:00' : '14:00'}`)
      setCheckOut(`${day}T17:00`)
    }
  }

  const stay: StayInput | null = branchId
    ? { branch_id: branchId, stay_type: stayType, check_in: checkIn, check_out: stayType === 'hourly' ? null : checkOut, hours: stayType === 'hourly' ? hours : null }
    : null
  const debouncedStay = useDebounced(stay, 300)
  const availability = useAvailability(debouncedStay, existing?.id)
  const types = availability.data?.data ?? []

  const input: BookingInput | null =
    stay && rooms.length ? { ...stay, guest_id: chosenGuest?.id ?? null, rooms, discount_percent: discount || '0', cancellation_policy_id: policyId, source, notes: notes || null } : null
  const debouncedInput = useDebounced(input, 300)
  const quote = useQuote(debouncedInput, existing?.id)
  const save = useSaveReservation(existing?.id)
  const saveError = save.error instanceof ApiError ? save.error : null
  const quoteError = quote.error instanceof ApiError ? quote.error : null
  const availabilityError = availability.error instanceof ApiError ? availability.error : null

  const chosenOf = (typeId: number) => rooms.filter((r) => r.room_type_id === typeId).length
  const addRoom = (typeId: number, base: number) => setRooms([...rooms, { room_type_id: typeId, room_id: null, adults: Math.min(2, base), children: 0 }])
  const setRoom = (i: number, patch: Partial<RoomInput>) => setRooms(rooms.map((r, j) => (j === i ? { ...r, ...patch } : r)))

  function submit() {
    if (!input || !chosenGuest) return
    save.mutate({ ...input, guest_id: chosenGuest.id, ...(!existing && payLater ? { pay_later: true } : {}) }, { onSuccess: (res) => navigate(`/reservations/${res.data.id}`, { replace: true }) })
  }

  const title = existing ? t('reservations.editTitle', { code: existing.code }) : t('reservations.new')
  const firstError = saveError ? (Object.values(saveError.errors)[0]?.[0] ?? saveError.message) : null

  return (
    <div className="flex flex-col gap-6">
      <PageHeader title={title} breadcrumbs={[{ label: t('nav.reservations'), to: '/reservations' }, { label: existing?.code ?? t('reservations.new') }]} />
      <div className="grid items-start gap-6 lg:grid-cols-[1fr_22rem]">
        <div className="flex flex-col gap-6">
          {/* 1. Stay */}
          <Card className="flex flex-col gap-4">
            <h2 className="text-sm font-semibold">{t('reservations.form.stay')}</h2>
            <div className="flex flex-wrap gap-1.5" role="radiogroup" aria-label={t('reservations.form.stayType')}>
              {(['nightly', 'day_use', 'hourly'] as const).filter((s) => !lookups.data?.stay_types || lookups.data.stay_types.includes(s)).map((s) => (
                <button
                  key={s}
                  type="button"
                  role="radio"
                  aria-checked={stayType === s}
                  onClick={() => changeStayType(s)}
                  className={cn(
                    'min-h-touch rounded-lg px-3 text-[13px] font-medium ring-1 transition duration-75',
                    stayType === s ? 'bg-accent text-on-accent ring-accent' : 'bg-surface text-muted ring-ring hover:bg-sunken',
                  )}
                >
                  {t(`reservations.stayTypes.${s}`)}
                </button>
              ))}
            </div>
            <div className="grid gap-4 sm:grid-cols-3">
              {branches.length > 1 && !existing && (
                <Select
                  label={t('settings.rooms.branch')}
                  value={String(branchId)}
                  onChange={(e) => {
                    setBranchId(Number(e.target.value))
                    setRooms([])
                  }}
                >
                  {branches.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.name}
                    </option>
                  ))}
                </Select>
              )}
              {stayType === 'nightly' ? (
                <>
                  <Field
                    label={t('reservations.form.checkIn')}
                    type="date"
                    value={checkIn}
                    min={existing ? undefined : today()}
                    onChange={(e) => {
                      setCheckIn(e.target.value)
                      if (e.target.value >= checkOut) setCheckOut(addDays(e.target.value, 1))
                    }}
                  />
                  <Field label={t('reservations.form.checkOut')} type="date" value={checkOut} min={addDays(checkIn, 1)} onChange={(e) => setCheckOut(e.target.value)} />
                </>
              ) : (
                <>
                  <Field label={t('reservations.form.start')} type="datetime-local" value={checkIn} onChange={(e) => setCheckIn(e.target.value)} />
                  {stayType === 'hourly' ? (
                    <Field label={t('reservations.form.hours')} type="number" inputMode="numeric" min={1} max={24} value={String(hours)} onChange={(e) => setHours(Math.max(1, Number(e.target.value) || 1))} />
                  ) : (
                    <Field label={t('reservations.form.end')} type="time" value={checkOut.slice(11, 16)} onChange={(e) => setCheckOut(`${checkIn.slice(0, 10)}T${e.target.value}`)} />
                  )}
                </>
              )}
            </div>
            {availabilityError && <Alert>{Object.values(availabilityError.errors)[0]?.[0] ?? availabilityError.message}</Alert>}
            {availability.data && (
              <p className="text-xs text-muted">
                {t('reservations.form.times', {
                  in: `${localDate(availability.data.check_in_at)} ${localTime(availability.data.check_in_at)}`,
                  out: `${localDate(availability.data.check_out_at)} ${localTime(availability.data.check_out_at)}`,
                })}
              </p>
            )}
          </Card>

          {/* 2. Rooms */}
          <Card className="flex flex-col gap-4 p-0">
            <div className="flex flex-wrap items-center justify-between gap-2 px-5 pt-5">
              <h2 className="text-sm font-semibold">{t('reservations.form.rooms')}</h2>
              <div className="w-64">
                <Tabs label={t('reservations.form.rooms')} active={pickBy} onChange={(k) => setPickBy(k as 'types' | 'rooms')} items={[{ key: 'types', label: t('pickBy.types') }, { key: 'rooms', label: t('pickBy.rooms') }]} />
              </div>
            </div>
            {pickBy === 'rooms' && (
              <div className="flex flex-col gap-2 px-5">
                <div className="grid grid-cols-[repeat(auto-fill,minmax(8rem,1fr))] gap-2">
                  {types
                    .filter((type) => type.offered)
                    .flatMap((type) => type.free_rooms.map((f) => ({ ...f, type })))
                    .sort((a, b) => a.number.localeCompare(b.number, undefined, { numeric: true }))
                    .map((f) => {
                      const on = rooms.some((r) => r.room_id === f.id)
                      return (
                        <button
                          key={f.id}
                          type="button"
                          aria-pressed={on}
                          onClick={() => (on ? setRooms(rooms.filter((r) => r.room_id !== f.id)) : setRooms([...rooms, { room_type_id: f.type.room_type_id, room_id: f.id, adults: Math.min(2, f.type.base_capacity), children: 0 }]))}
                          className={cn('relative flex min-h-20 flex-col items-start gap-0.5 rounded-xl p-3 text-left ring-1 transition duration-75', on ? 'bg-accent-soft ring-2 ring-accent' : 'bg-surface ring-ring hover:bg-sunken')}
                        >
                          <span className="text-base font-semibold">{f.number}</span>
                          <span className="text-[11px] text-muted">{f.type.name}</span>
                          {f.type.price && <span className="text-[11px] font-medium tabular-nums">{money(f.type.price, f.type.currency)}</span>}
                          {on && <CheckIcon aria-hidden className="absolute top-2 right-2 size-4 text-accent-text" />}
                        </button>
                      )
                    })}
                </div>
                {availability.data && !types.some((type) => type.offered && type.free_rooms.length > 0) && <p className="py-2 text-[13px] text-muted">{t('reservations.noRooms')}</p>}
              </div>
            )}
            <ul className={cn('flex flex-col divide-y divide-border', pickBy === 'rooms' && 'hidden')}>
              {types.map((type) => {
                const left = type.available - chosenOf(type.room_type_id)
                return (
                  <li key={type.room_type_id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-2.5">
                    <div className="flex flex-col">
                      <span className="text-[13px] font-medium">{type.name}</span>
                      <span className="text-xs text-muted">
                        {type.offered ? t('reservations.form.left', { count: Math.max(0, left) }) : t('reservations.form.notOffered')} · {t('reservations.form.upTo', { count: type.max_capacity })}
                      </span>
                    </div>
                    <div className="flex items-center gap-3">
                      {type.price && <span className="text-[13px] font-semibold tabular-nums">{money(type.price, type.currency)}</span>}
                      <Button variant="secondary" disabled={!type.offered || left <= 0} onClick={() => addRoom(type.room_type_id, type.base_capacity)}>
                        <PlusIcon aria-hidden className="size-4" />
                        {t('reservations.form.add')}
                      </Button>
                    </div>
                  </li>
                )
              })}
              {availability.data && types.length === 0 && <li className="px-5 py-4 text-[13px] text-muted">{t('reservations.noRooms')}</li>}
            </ul>
            {rooms.length > 0 && (
              <div className="flex flex-col gap-2 border-t border-border bg-sunken/50 px-5 py-4">
                <span className="text-xs font-semibold text-muted">{t('reservations.form.chosen')}</span>
                {rooms.map((r, i) => {
                  const type = types.find((x) => x.room_type_id === r.room_type_id)
                  const taken = new Set(rooms.filter((_, j) => j !== i).map((x) => x.room_id))
                  const existingRoom = existing?.rooms.find((x) => x.room_id === r.room_id)
                  const options = [...(type?.free_rooms ?? []), ...(existingRoom?.room_id && !type?.free_rooms.some((f) => f.id === existingRoom.room_id) ? [{ id: existingRoom.room_id, number: existingRoom.room_number ?? '' }] : [])]
                  return (
                    <div key={i} className="flex flex-wrap items-end gap-3 rounded-lg bg-surface p-3 ring-1 ring-ring">
                      <span className="min-w-28 self-center text-[13px] font-medium">{type?.name ?? existing?.rooms.find((x) => x.room_type_id === r.room_type_id)?.room_type}</span>
                      <div className="w-36">
                        <Select label={t('reservations.form.roomNumber')} value={String(r.room_id ?? '')} onChange={(e) => setRoom(i, { room_id: e.target.value ? Number(e.target.value) : null })}>
                          <option value="">{t('reservations.form.anyRoom')}</option>
                          {options
                            .filter((f) => !taken.has(f.id))
                            .map((f) => (
                              <option key={f.id} value={f.id}>
                                {f.number}
                              </option>
                            ))}
                        </Select>
                      </div>
                      <Stepper label={t('reservations.form.adults')} value={r.adults} min={1} max={type?.max_capacity ?? 20} onChange={(v) => setRoom(i, { adults: v })} />
                      <Stepper label={t('reservations.form.children')} value={r.children} min={0} max={Math.max(0, (type?.max_capacity ?? 20) - r.adults)} onChange={(v) => setRoom(i, { children: v })} />
                      <Button variant="ghost" aria-label={t('reservations.form.remove')} onClick={() => setRooms(rooms.filter((_, j) => j !== i))}>
                        <XMarkIcon aria-hidden className="size-4" />
                      </Button>
                    </div>
                  )
                })}
              </div>
            )}
          </Card>

          {/* 3. Guest */}
          <Card className="flex flex-col gap-4">
            <h2 className="text-sm font-semibold">{t('reservations.form.guest')}</h2>
            <GuestPicker value={chosenGuest} onChange={setGuest} />
          </Card>

          {/* 4. Details */}
          <Card className="grid gap-4 sm:grid-cols-2">
            <h2 className="text-sm font-semibold sm:col-span-2">{t('reservations.form.details')}</h2>
            <Select label={t('reservations.form.source')} value={source} onChange={(e) => setSource(e.target.value)}>
              {SOURCES.map((s) => (
                <option key={s} value={s}>
                  {t(`reservations.sources.${s}`)}
                </option>
              ))}
            </Select>
            <Field
              label={t('reservations.form.discount')}
              inputMode="decimal"
              value={discount}
              hint={t('reservations.form.discountHint', { max: Number(me.data?.role?.max_discount_percent ?? 0) })}
              onChange={(e) => setDiscount(e.target.value.replace(/[^0-9.]/g, ''))}
              error={quoteError?.field('discount_percent')}
            />
            {can('reservations.change_policy') && (lookups.data?.policies.length ?? 0) > 0 && (
              <Select label={t('reservations.form.policy')} value={String(policyId ?? lookups.data?.policies.find((p) => p.is_default)?.id ?? '')} onChange={(e) => setPolicyId(Number(e.target.value))}>
                {lookups.data?.policies.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
              </Select>
            )}
            <div className="sm:col-span-2">
              <Field label={t('reservations.form.notes')} value={notes} onChange={(e) => setNotes(e.target.value)} />
            </div>
          </Card>
        </div>

        {/* Summary */}
        <Card className="flex flex-col gap-3 lg:sticky lg:top-20">
          <h2 className="text-sm font-semibold">{t('reservations.form.summary')}</h2>
          {!rooms.length && <p className="text-[13px] text-muted">{t('reservations.form.pickRooms')}</p>}
          {quoteError && <Alert>{Object.values(quoteError.errors)[0]?.[0] ?? quoteError.message}</Alert>}
          {quote.data && rooms.length > 0 && <QuoteSummary q={payLater ? { ...quote.data.data, deposit_required: '0' } : quote.data.data} />}
          {!existing && lookups.data?.pay_later && (
            <label className="flex min-h-touch items-start gap-2 text-[13px]">
              <input type="checkbox" className="mt-0.5 size-4 accent-accent" checked={payLater} onChange={(e) => setPayLater(e.target.checked)} />
              <span className="flex flex-col">
                {t('payLater.option')}
                <span className="text-xs text-muted">{t('payLater.bookingHint')}</span>
              </span>
            </label>
          )}
          {firstError && <Alert>{firstError}</Alert>}
          {!chosenGuest && rooms.length > 0 && <p className="text-xs text-warn-text">{t('reservations.form.needGuest')}</p>}
          <Button size="lg" disabled={!input || !chosenGuest || !quote.data || !!quoteError || save.isPending} onClick={submit}>
            {existing ? t('common.save') : t('reservations.form.book')}
          </Button>
        </Card>
      </div>
    </div>
  )
}

function Stepper({ label, value, min, max, onChange }: { label: string; value: number; min: number; max: number; onChange: (v: number) => void }) {
  return (
    <div className="flex flex-col gap-1.5">
      <span className="text-[13px] font-medium">{label}</span>
      <div className="flex items-center rounded-lg ring-1 ring-ring">
        <button type="button" aria-label={`${label} −`} disabled={value <= min} onClick={() => onChange(value - 1)} className="flex size-touch items-center justify-center text-icon hover:text-text disabled:opacity-40">
          <MinusIcon aria-hidden className="size-4" />
        </button>
        <span className="w-6 text-center text-[13px] tabular-nums" aria-live="polite">
          {value}
        </span>
        <button type="button" aria-label={`${label} +`} disabled={value >= max} onClick={() => onChange(value + 1)} className="flex size-touch items-center justify-center text-icon hover:text-text disabled:opacity-40">
          <PlusIcon aria-hidden className="size-4" />
        </button>
      </div>
    </div>
  )
}

export function QuoteSummary({ q }: { q: { currency: string; rooms: { room_type: string; room_number: string | null; total: string; lines: { date: string; label: string; amount: string; rule_name: string | null }[] }[]; subtotal: string; discount_percent: string; discount_amount: string; tax_total: string; total: string; deposit_required: string; policy_name?: string | null } }) {
  const { t } = useTranslation()
  const c = q.currency
  return (
    <div className="flex flex-col gap-2 text-[13px]">
      {q.rooms.map((r, i) => (
        <details key={i} className="group rounded-lg bg-sunken px-3 py-2">
          <summary className="flex cursor-pointer list-none justify-between gap-2">
            <span className="font-medium">
              {r.room_type}
              {r.room_number && <span className="text-muted"> · {r.room_number}</span>}
            </span>
            <span className="tabular-nums">{money(r.total, c)}</span>
          </summary>
          <ul className="mt-2 flex flex-col gap-0.5 text-xs text-muted">
            {r.lines.map((l, j) => (
              <li key={j} className="flex justify-between gap-2">
                <span>
                  {l.date} · {l.label}
                  {l.rule_name && <Badge tone="accent">{l.rule_name}</Badge>}
                </span>
                <span className="tabular-nums">{money(l.amount, c)}</span>
              </li>
            ))}
          </ul>
        </details>
      ))}
      <Line label={t('reservations.subtotal')} value={money(q.subtotal, c)} />
      {Number(q.discount_amount) > 0 && <Line label={t('reservations.discountN', { n: Number(q.discount_percent) })} value={`− ${money(q.discount_amount, c)}`} />}
      {Number(q.tax_total) > 0 && <Line label={t('reservations.taxes')} value={money(q.tax_total, c)} />}
      <Line label={t('reservations.total')} value={money(q.total, c)} strong />
      {Number(q.deposit_required) > 0 && <Line label={t('reservations.depositToConfirm')} value={money(q.deposit_required, c)} />}
      {q.policy_name && <p className="text-xs text-muted">{t('reservations.policyName', { name: q.policy_name })}</p>}
    </div>
  )
}

export function Line({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className={cn('flex justify-between gap-3', strong && 'border-t border-border pt-2 text-sm font-semibold')}>
      <span className={strong ? '' : 'text-muted'}>{label}</span>
      <span className="tabular-nums">{value}</span>
    </div>
  )
}

/** Find a guest by name, phone or ID — or add a new one without leaving the booking. */
export function GuestPicker({ value, onChange }: { value: Guest | null; onChange: (g: Guest | null) => void }) {
  const { t } = useTranslation()
  const can = useCan()
  const [q, setQ] = useState('')
  const [adding, setAdding] = useState(false)
  const search = useDebounced(q, 250)
  const results = useGuests(search, 1, search.length >= 2)

  if (value) {
    return (
      <div className="flex items-center justify-between gap-3 rounded-lg bg-sunken px-3 py-2">
        <span className="flex flex-col text-[13px]">
          <span className="font-medium">{value.full_name}</span>
          <span className="text-xs text-muted">{[value.phone, value.id_number].filter(Boolean).join(' · ')}</span>
        </span>
        <Button variant="ghost" onClick={() => onChange(null)}>
          {t('reservations.form.changeGuest')}
        </Button>
      </div>
    )
  }
  if (adding) {
    return (
      <GuestForm
        compact
        onSaved={(g) => {
          onChange(g)
          setAdding(false)
        }}
        onCancel={() => setAdding(false)}
      />
    )
  }
  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap gap-2">
        <input
          type="search"
          aria-label={t('guests.search')}
          placeholder={t('guests.search')}
          value={q}
          onChange={(e) => setQ(e.target.value)}
          className="min-h-touch flex-1 rounded-lg bg-surface px-3 text-[13px] shadow-sm ring-1 ring-ring outline-none focus:ring-2 focus:ring-accent"
        />
        {can('guests.create_edit') && (
          <Button variant="secondary" onClick={() => setAdding(true)}>
            <PlusIcon aria-hidden className="size-4" />
            {t('guests.new')}
          </Button>
        )}
      </div>
      {search.length >= 2 && (
        <ul className="flex flex-col divide-y divide-border rounded-lg ring-1 ring-ring">
          {results.data?.data.map((g) => (
            <li key={g.id}>
              <button
                type="button"
                disabled={g.is_blacklisted}
                onClick={() => onChange(g)}
                className="flex min-h-touch w-full items-center justify-between gap-2 px-3 py-1.5 text-left text-[13px] hover:bg-sunken disabled:cursor-not-allowed disabled:opacity-60"
              >
                <span className="flex flex-col">
                  <span className="font-medium">{g.full_name}</span>
                  <span className="text-xs text-muted">{[g.phone, g.id_number].filter(Boolean).join(' · ')}</span>
                </span>
                {g.is_blacklisted && <Badge tone="danger">{t('guests.blacklisted')}</Badge>}
              </button>
            </li>
          ))}
          {results.data?.data.length === 0 && <li className="px-3 py-2 text-[13px] text-muted">{t('guests.noMatch')}</li>}
        </ul>
      )}
    </div>
  )
}
