import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { IdPhoto } from '@/components/guests/IdPhoto'
import { SmartHints } from '@/components/ui/SmartHints'
import { useSmart } from '@/lib/smart'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { Select } from '@/components/ui/Select'
import { ApiError } from '@/lib/api'
import { addMoney, isNegative, isPositive } from '@/lib/decimal'
import { money } from '@/lib/format'
import { useCheckIn, useCheckInInfo, useCheckOut, useCheckOutInfo, useExtend, useFreeRooms, useMoveRoom } from '@/lib/desk'
import { addDays, localDate, useLookups, type ReservationDetail } from '@/lib/front'
import { useCan } from '@/lib/hotel'

function firstError(e: unknown): string | null {
  if (!(e instanceof ApiError)) return e ? 'error' : null
  return Object.values(e.errors)[0]?.[0] ?? e.message
}

/** Method + amount for money taken at the desk. */
function PayFields({ amount, setAmount, methodId, setMethodId }: { amount: string; setAmount: (v: string) => void; methodId: number; setMethodId: (v: number) => void }) {
  const { t } = useTranslation()
  const lookups = useLookups()
  const current = methodId || lookups.data?.payment_methods[0]?.id || 0
  return (
    <div className="grid gap-3 sm:grid-cols-2">
      <Select label={t('reservations.method')} value={String(current)} onChange={(e) => setMethodId(Number(e.target.value))}>
        {lookups.data?.payment_methods.map((m) => (
          <option key={m.id} value={m.id}>
            {m.name}
          </option>
        ))}
      </Select>
      <Field label={t('reservations.amount')} inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value.replace(/[^0-9.]/g, ''))} />
    </div>
  )
}

function usePay() {
  const lookups = useLookups()
  const [amount, setAmount] = useState<string | null>(null)
  const [methodId, setMethodId] = useState(0)
  return {
    amount,
    setAmount,
    methodId,
    setMethodId,
    payment: (fallback: string) => {
      const a = amount ?? fallback
      return isPositive(a) ? { payment_method_id: methodId || lookups.data?.payment_methods[0]?.id || 0, amount: a } : null
    },
  }
}

/** Check-in (W4): give clean rooms, early fee (waivable), deposit if still missing. */
export function CheckInPanel({ r, onClose }: { r: ReservationDetail; onClose: () => void }) {
  const { t } = useTranslation()
  const can = useCan()
  const info = useCheckInInfo(r.id, true)
  const checkIn = useCheckIn(r.id)
  const pay = usePay()
  const [picks, setPicks] = useState<Record<number, number>>({})
  const [waive, setWaive] = useState(false)
  const d = info.data?.data
  const early = addMoney(...(d?.rooms ?? []).map((x) => x.early_fee ?? '0'))
  const due = d?.deposit_due ?? '0.00'
  // ✨ Suggested rooms (guest's usual room, else the one used least) fill the empty choices.
  const smart = useSmart('check_in', { id: r.id })
  const suggested = (smart.data?.data.find((h) => h.key === 'best_room')?.data?.rooms ?? {}) as Record<string, number>
  const roomFor = (stayId: number, fallback: number | null) => {
    const hint = suggested[String(stayId)]
    const free = d?.rooms.find((x) => x.id === stayId)?.free_rooms.some((f) => f.id === hint)
    return picks[stayId] ?? fallback ?? (hint && free ? hint : 0)
  }

  return (
    <Card className="flex flex-col gap-4 ring-accent/40 animate-page-in">
      <h2 className="text-sm font-semibold">{t('desk.checkInTitle')}</h2>
      {info.error && <Alert>{firstError(info.error)}</Alert>}
      <SmartHints hints={smart.data?.data} />
      {d?.rooms.map((x) => {
        const taken = new Set(Object.entries(picks).filter(([k]) => Number(k) !== x.id).map(([, v]) => v))
        const options = [...(x.room_id && !x.free_rooms.some((f) => f.id === x.room_id) ? [{ id: x.room_id, number: `${x.room_number} (${t(`roomStatus.${x.room_status ?? 'dirty'}`)})`, floor: null }] : []), ...x.free_rooms]
        return (
          <div key={x.id} className="flex flex-wrap items-end gap-3">
            <span className="min-w-28 self-center text-[13px] font-medium">{x.room_type}</span>
            <div className="w-44">
              <Select label={t('reservations.form.roomNumber')} value={String(roomFor(x.id, x.room_id) || '')} onChange={(e) => setPicks({ ...picks, [x.id]: Number(e.target.value) })}>
                <option value="">—</option>
                {options
                  .filter((f) => !taken.has(f.id))
                  .map((f) => (
                    <option key={f.id} value={f.id}>
                      {f.number}
                    </option>
                  ))}
              </Select>
            </div>
            {x.free_rooms.length === 0 && <span className="text-xs text-warn-text">{t('desk.noCleanRoom')}</span>}
          </div>
        )
      })}
      {isPositive(early) && (
        <label className="flex items-center gap-3 rounded-lg bg-warn-bg/50 px-3 py-2 text-[13px]">
          <span className="flex-1">{t('desk.earlyFee', { amount: money(early, r.currency) })}</span>
          {can('fees.waive') && (
            <span className="flex items-center gap-2">
              <input type="checkbox" className="size-4 accent-accent" checked={waive} onChange={(e) => setWaive(e.target.checked)} />
              {t('desk.waive')}
            </span>
          )}
        </label>
      )}
      {isPositive(due) && (
        <div className="flex flex-col gap-2">
          <p className="text-[13px] text-muted">{t('desk.depositDue', { amount: money(due, r.currency) })}</p>
          <PayFields amount={pay.amount ?? due} setAmount={pay.setAmount} methodId={pay.methodId} setMethodId={pay.setMethodId} />
        </div>
      )}
      {d?.id_photo_needed && (
        <div className="flex flex-col gap-2 rounded-lg bg-warn-bg/50 px-3 py-2.5">
          <p className="text-[13px]">{t('idPhoto.needed')}</p>
          <IdPhoto compact guestId={r.guest.id} photo={null} canEdit={can('guests.create_edit')} />
        </div>
      )}
      {checkIn.error && <Alert>{firstError(checkIn.error)}</Alert>}
      <div className="flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose}>
          {t('common.cancel')}
        </Button>
        <Button
          size="lg"
          disabled={!d || checkIn.isPending || !!d.id_photo_needed}
          onClick={() =>
            checkIn.mutate(
              { rooms: (d?.rooms ?? []).map((x) => ({ id: x.id, room_id: roomFor(x.id, x.room_id) || null })), waive_early_fee: waive, payment: isPositive(due) ? pay.payment(due) : null },
              { onSuccess: onClose },
            )
          }
        >
          {t('desk.checkIn')}
        </Button>
      </div>
    </Card>
  )
}

/** Check-out (W6): late fee (waivable), take the rest, or a manager leaves it outstanding. */
export function CheckOutPanel({ r, onClose }: { r: ReservationDetail; onClose: () => void }) {
  const { t } = useTranslation()
  const can = useCan()
  const info = useCheckOutInfo(r.id, true)
  const checkOut = useCheckOut(r.id)
  const pay = usePay()
  const [waive, setWaive] = useState(false)
  const [waiveEarly, setWaiveEarly] = useState(false)
  const [leaveOwing, setLeaveOwing] = useState(false)
  const d = info.data?.data
  const late = d?.late_fee ?? '0.00'
  const early = d?.early ?? null
  const balance = early ? (waiveEarly ? early.balance_without_fee : early.balance) : addMoney(d?.balance ?? '0', waive ? '0' : late)

  return (
    <Card className="flex flex-col gap-4 ring-accent/40 animate-page-in">
      <h2 className="text-sm font-semibold">{t('desk.checkOutTitle')}</h2>
      {isPositive(late) && (
        <label className="flex items-center gap-3 rounded-lg bg-warn-bg/50 px-3 py-2 text-[13px]">
          <span className="flex-1">{t('desk.lateFee', { amount: money(late, r.currency) })}</span>
          {can('fees.waive') && (
            <span className="flex items-center gap-2">
              <input type="checkbox" className="size-4 accent-accent" checked={waive} onChange={(e) => setWaive(e.target.checked)} />
              {t('desk.waive')}
            </span>
          )}
        </label>
      )}
      {early && (
        <div className="flex flex-col gap-2 rounded-lg bg-info-bg/50 px-3 py-2 text-[13px]">
          <span>{t('desk.earlyLeave', { count: early.nights })}</span>
          {isPositive(early.fee) && (
            <label className="flex items-center gap-3">
              <span className="flex-1">{t('desk.earlyLeaveFee', { amount: money(early.fee, r.currency) })}</span>
              {can('fees.waive') && (
                <span className="flex items-center gap-2">
                  <input type="checkbox" className="size-4 accent-accent" checked={waiveEarly} onChange={(e) => setWaiveEarly(e.target.checked)} />
                  {t('desk.waive')}
                </span>
              )}
            </label>
          )}
        </div>
      )}
      <p className="text-sm font-semibold">{isPositive(balance) ? t('desk.toPay', { amount: money(balance, r.currency) }) : t('desk.allPaid')}</p>
      {isNegative(balance) && <p className="text-[13px] text-warn-text">{t('desk.giveBack', { amount: money(balance.replace('-', ''), r.currency) })}</p>}
      {isPositive(balance) && !leaveOwing && <PayFields amount={pay.amount ?? balance} setAmount={pay.setAmount} methodId={pay.methodId} setMethodId={pay.setMethodId} />}
      {isPositive(balance) && can('checkout.approve_balance') && (
        <label className="flex items-center gap-2 text-[13px]">
          <input type="checkbox" className="size-4 accent-accent" checked={leaveOwing} onChange={(e) => setLeaveOwing(e.target.checked)} />
          {t('desk.leaveOwing')}
        </label>
      )}
      {isPositive(balance) && !can('checkout.approve_balance') && <p className="text-xs text-muted">{t('desk.managerApproves')}</p>}
      {checkOut.error && <Alert>{firstError(checkOut.error)}</Alert>}
      <div className="flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose}>
          {t('common.cancel')}
        </Button>
        <Button
          size="lg"
          disabled={!d || checkOut.isPending}
          onClick={() => checkOut.mutate({ waive_late_fee: waive, waive_early_fee: waiveEarly, approve_balance: leaveOwing, payment: leaveOwing ? null : pay.payment(balance) }, { onSuccess: onClose })}
        >
          {t('desk.checkOut')}
        </Button>
      </div>
    </Card>
  )
}

/** Move to another room, or stay longer (W5). */
export function ChangeStayPanel({ r, onClose }: { r: ReservationDetail; onClose: () => void }) {
  const { t } = useTranslation()
  const free = useFreeRooms(r.branch?.id ?? null)
  const move = useMoveRoom(r.id)
  const extend = useExtend(r.id)
  const [stay, setStay] = useState(r.rooms[0]?.id ?? 0)
  const [roomId, setRoomId] = useState(0)
  const [out, setOut] = useState(addDays(localDate(r.check_out_at), 1))
  const [hours, setHours] = useState(1)

  return (
    <Card className="grid gap-6 animate-page-in sm:grid-cols-2">
      <div className="flex flex-col gap-3">
        <h2 className="text-sm font-semibold">{t('desk.moveRoom')}</h2>
        {r.rooms.length > 1 && (
          <Select label={t('desk.whichRoom')} value={String(stay)} onChange={(e) => setStay(Number(e.target.value))}>
            {r.rooms.map((x) => (
              <option key={x.id} value={x.id}>
                {x.room_number ?? x.room_type}
              </option>
            ))}
          </Select>
        )}
        <Select label={t('desk.newRoom')} value={String(roomId || '')} onChange={(e) => setRoomId(Number(e.target.value))}>
          <option value="">—</option>
          {free.data?.data.map((x) => (
            <option key={x.id} value={x.id}>
              {x.number} · {x.room_type}
            </option>
          ))}
        </Select>
        <p className="text-xs text-muted">{t('desk.moveHelp')}</p>
        {move.error && <Alert>{firstError(move.error)}</Alert>}
        <div>
          <Button variant="secondary" disabled={!roomId || move.isPending} onClick={() => move.mutate({ stay, room_id: roomId }, { onSuccess: onClose })}>
            {t('desk.move')}
          </Button>
        </div>
      </div>
      <div className="flex flex-col gap-3">
        <h2 className="text-sm font-semibold">{t('desk.extend')}</h2>
        {r.stay_type === 'nightly' ? (
          <Field label={t('desk.newCheckOut')} type="date" min={addDays(localDate(r.check_out_at), 1)} value={out} onChange={(e) => setOut(e.target.value)} />
        ) : (
          <Field label={t('desk.moreHours')} type="number" min={1} max={24} value={String(hours)} onChange={(e) => setHours(Math.max(1, Number(e.target.value) || 1))} />
        )}
        {extend.error && <Alert>{firstError(extend.error)}</Alert>}
        <div className="flex gap-2">
          <Button variant="secondary" disabled={extend.isPending} onClick={() => extend.mutate(r.stay_type === 'nightly' ? { check_out: out } : { hours }, { onSuccess: onClose })}>
            {t('desk.extendStay')}
          </Button>
          <Button variant="ghost" onClick={onClose}>
            {t('common.close')}
          </Button>
        </div>
      </div>
    </Card>
  )
}
