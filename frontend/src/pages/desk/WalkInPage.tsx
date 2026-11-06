import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { useTranslation } from 'react-i18next'
import { CheckIcon } from '@heroicons/react/20/solid'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Select } from '@/components/ui/Select'
import { ApiError } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { useCan } from '@/lib/hotel'
import { cn } from '@/lib/cn'
import { useFreeRooms, useWalkIn } from '@/lib/desk'
import { addDays, today, useLookups, useQuote, type BookingInput, type Guest, type StayType } from '@/lib/front'
import { useDebounced } from '@/lib/useDebounced'
import { GuestPicker, QuoteSummary } from '@/pages/reservations/ReservationFormPage'

/** Walk-in (W3): pick a clean room, the guest, take the money, check in. */
export function WalkInPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const me = useMe()
  const lookups = useLookups()
  const can = useCan()
  const branches = me.data?.branches ?? []
  const [branchId, setBranchId] = useState(branches[0]?.id ?? 0)
  const [stayType, setStayType] = useState<StayType>('nightly')
  const [checkOut, setCheckOut] = useState(addDays(today(), 1))
  const [hours, setHours] = useState(3)
  const [picked, setPicked] = useState<number[]>([])
  const [guest, setGuest] = useState<Guest | null>(null)
  const canQuick = can('frontdesk.quick_check_in') && can('guests.create_edit')
  const [quick, setQuick] = useState(false)
  const [quickName, setQuickName] = useState('')
  const [quickPhone, setQuickPhone] = useState('')
  const quickReady = quick && quickName.trim().length >= 2 && quickPhone.trim().length >= 6
  const hasGuest = quick ? quickReady : !!guest
  const [methodId, setMethodId] = useState(0)
  const [amount, setAmount] = useState<string | null>(null)
  const [reference, setReference] = useState('')
  const [payLater, setPayLater] = useState(false)
  const free = useFreeRooms(branchId || null, true, { stay_type: stayType, check_out: checkOut, hours })
  const walkIn = useWalkIn()

  const rooms = (free.data?.data ?? []).filter((r) => (stayType === 'nightly' ? true : stayType === 'hourly' ? r.hourly_price !== null : r.day_use_price !== null))
  const chosen = rooms.filter((r) => picked.includes(r.id))
  const now = free.data?.now ?? `${today()}T12:00:00`
  const input: BookingInput | null =
    branchId && chosen.length
      ? {
          branch_id: branchId,
          stay_type: stayType,
          check_in: stayType === 'nightly' ? today() : now.slice(0, 16),
          check_out: stayType === 'nightly' ? checkOut : stayType === 'day_use' ? `${today()}T22:00` : null,
          hours: stayType === 'hourly' ? hours : null,
          guest_id: quick ? null : (guest?.id ?? null),
          rooms: chosen.map((r) => ({ room_type_id: r.room_type_id, room_id: r.id, adults: 1, children: 0 })),
          walk_in: true,
        }
      : null
  const quote = useQuote(useDebounced(input, 300))
  const q = quote.data?.data
  const required = q ? (free.data?.walk_in_payment === 'deposit' ? q.deposit_required : q.total) : '0.00'
  const pay = amount ?? required
  const error = walkIn.error instanceof ApiError ? walkIn.error : null
  const quoteError = quote.error instanceof ApiError ? quote.error : null

  function submit() {
    if (!input || !hasGuest) return
    walkIn.mutate(
      {
        branch_id: branchId,
        stay_type: stayType,
        check_out: input.check_out,
        hours: input.hours,
        ...(quick ? { quick_guest: { name: quickName.trim(), phone: quickPhone.trim() } } : { guest_id: guest?.id }),
        rooms: chosen.map((r) => ({ room_type_id: r.room_type_id, room_id: r.id, adults: 1, children: 0 })),
        ...(payLater ? { pay_later: true } : { payment: { payment_method_id: methodId || lookups.data?.payment_methods[0]?.id || 0, amount: pay, reference: reference || undefined } }),
      },
      { onSuccess: (res) => navigate(`/reservations/${res.data.id}`, { replace: true }) },
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader title={t('desk.walkIn')} breadcrumbs={[{ label: t('nav.roomBoard'), to: '/rooms' }, { label: t('desk.walkIn') }]} />
      <div className="grid items-start gap-6 lg:grid-cols-[1fr_22rem]">
        <div className="flex flex-col gap-6">
          <Card className="flex flex-col gap-4">
            <div className="flex flex-wrap gap-1.5" role="radiogroup" aria-label={t('reservations.form.stayType')}>
              {(['nightly', 'day_use', 'hourly'] as const).filter((s) => !lookups.data?.stay_types || lookups.data.stay_types.includes(s)).map((s) => (
                <button
                  key={s}
                  type="button"
                  role="radio"
                  aria-checked={stayType === s}
                  onClick={() => {
                    setStayType(s)
                    setPicked([])
                  }}
                  className={cn('min-h-touch rounded-lg px-4 text-sm font-medium ring-1 transition duration-75', stayType === s ? 'bg-accent text-on-accent ring-accent' : 'bg-surface text-muted ring-ring hover:bg-sunken')}
                >
                  {t(`reservations.stayTypes.${s}`)}
                </button>
              ))}
            </div>
            <div className="grid gap-4 sm:grid-cols-3">
              {branches.length > 1 && (
                <Select
                  label={t('settings.rooms.branch')}
                  value={String(branchId)}
                  onChange={(e) => {
                    setBranchId(Number(e.target.value))
                    setPicked([])
                  }}
                >
                  {branches.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.name}
                    </option>
                  ))}
                </Select>
              )}
              {stayType === 'nightly' && <Field label={t('reservations.form.checkOut')} type="date" min={addDays(today(), 1)} value={checkOut} onChange={(e) => setCheckOut(e.target.value)} />}
              {stayType === 'hourly' && <Field label={t('reservations.form.hours')} type="number" inputMode="numeric" min={1} max={24} value={String(hours)} onChange={(e) => setHours(Math.max(1, Number(e.target.value) || 1))} />}
            </div>
            <p className="text-xs text-muted">
              {t('desk.walkInStartsNow')}{' '}
              <Link to="/reservations/new" className="font-medium text-accent-text underline">
                {t('desk.bookForLater')}
              </Link>
            </p>
          </Card>

          <Card className="flex flex-col gap-3">
            <h2 className="text-sm font-semibold">{t('desk.pickRoom')}</h2>
            <div className="grid grid-cols-[repeat(auto-fill,minmax(8rem,1fr))] gap-2">
              {rooms.map((r) => {
                const on = picked.includes(r.id)
                return (
                  <button
                    key={r.id}
                    type="button"
                    aria-pressed={on}
                    onClick={() => setPicked(on ? picked.filter((x) => x !== r.id) : [...picked, r.id])}
                    className={cn('relative flex min-h-20 flex-col items-start gap-0.5 rounded-xl p-3 text-left ring-1 transition duration-75', on ? 'bg-accent-soft ring-2 ring-accent' : 'bg-surface ring-ring hover:bg-sunken')}
                  >
                    <span className="text-base font-semibold">{r.number}</span>
                    <span className="text-[11px] text-muted">{r.room_type}</span>
                    {on && <CheckIcon aria-hidden className="absolute top-2 right-2 size-4 text-accent-text" />}
                  </button>
                )
              })}
            </div>
            {free.data && rooms.length === 0 && <Alert tone="info">{t('desk.noFreeRooms')}</Alert>}
          </Card>

          <Card className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h2 className="text-sm font-semibold">{t('reservations.form.guest')}</h2>
              {canQuick && (
                <label className="flex min-h-touch cursor-pointer items-center gap-2 text-[13px]">
                  <input type="checkbox" className="size-4 accent-accent" checked={quick} onChange={(e) => setQuick(e.target.checked)} />
                  {t('desk.quick.toggle')}
                </label>
              )}
            </div>
            {quick ? (
              <div className="flex flex-col gap-3">
                <div className="grid gap-3 sm:grid-cols-2">
                  <Field label={t('desk.quick.name')} autoComplete="off" value={quickName} onChange={(e) => setQuickName(e.target.value)} error={error?.field('quick_guest.name')} />
                  <Field label={t('guests.phone')} type="tel" inputMode="tel" value={quickPhone} onChange={(e) => setQuickPhone(e.target.value)} error={error?.field('quick_guest.phone')} />
                </div>
                <p className="text-xs text-muted">{t('desk.quick.help')}</p>
              </div>
            ) : (
              <GuestPicker value={guest} onChange={setGuest} />
            )}
          </Card>
        </div>

        <Card className="flex flex-col gap-3 lg:sticky lg:top-20">
          <h2 className="text-sm font-semibold">{t('reservations.form.summary')}</h2>
          {!chosen.length && <p className="text-[13px] text-muted">{t('desk.pickRoomFirst')}</p>}
          {quoteError && <Alert>{Object.values(quoteError.errors)[0]?.[0] ?? quoteError.message}</Alert>}
          {q && chosen.length > 0 && <QuoteSummary q={{ ...q, deposit_required: '0' }} />}
          {q && lookups.data?.pay_later && (
            <label className="flex min-h-touch items-start gap-2 border-t border-border pt-3 text-[13px]">
              <input type="checkbox" className="mt-0.5 size-4 accent-accent" checked={payLater} onChange={(e) => setPayLater(e.target.checked)} />
              <span className="flex flex-col">
                {t('payLater.option')}
                <span className="text-xs text-muted">{t('payLater.walkInHint')}</span>
              </span>
            </label>
          )}
          {q && !payLater && (
            <div className="flex flex-col gap-3 border-t border-border pt-3">
              <p className="text-xs text-muted">{free.data?.walk_in_payment === 'deposit' ? t('desk.depositRule') : t('desk.fullRule')}</p>
              <Select label={t('reservations.method')} value={String(methodId || lookups.data?.payment_methods[0]?.id || '')} onChange={(e) => setMethodId(Number(e.target.value))}>
                {lookups.data?.payment_methods.map((m) => (
                  <option key={m.id} value={m.id}>
                    {m.name}
                  </option>
                ))}
              </Select>
              <Field label={t('reservations.amount')} inputMode="decimal" value={pay} onChange={(e) => setAmount(e.target.value.replace(/[^0-9.]/g, ''))} error={error?.field('payment.amount')} />
              <Field label={t('reservations.reference')} value={reference} onChange={(e) => setReference(e.target.value)} />
            </div>
          )}
          {error && !error.field('payment.amount') && <Alert>{Object.values(error.errors)[0]?.[0] ?? error.message}</Alert>}
          {!hasGuest && chosen.length > 0 && <p className="text-xs text-warn-text">{quick ? t('desk.quick.needNamePhone') : t('reservations.form.needGuest')}</p>}
          <Button size="lg" disabled={!input || !hasGuest || !q || walkIn.isPending} onClick={submit}>
            {payLater ? t('payLater.checkInNow') : t('desk.takePaymentCheckIn')}
          </Button>
        </Card>
      </div>
    </div>
  )
}
