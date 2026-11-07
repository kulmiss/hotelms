import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ArrowUturnLeftIcon, CheckCircleIcon, PlusIcon } from '@heroicons/react/20/solid'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { ApiError } from '@/lib/api'
import { money } from '@/lib/format'
import { useChargeRoom, useUndoCharge, type QuickButton, type QuickChargeAdded } from '@/lib/desk'

const err = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : null)

/**
 * Quick charge buttons for one room (owner, 2026-11-07): a button with a price adds it in one tap;
 * a button without a price (taxi) or "Other" asks for the amount. The last charge can be undone.
 */
export function QuickCharge({ roomId, roomNumber, buttons }: { roomId: number; roomNumber: string; buttons: QuickButton[] }) {
  const { t } = useTranslation()
  const charge = useChargeRoom()
  const undo = useUndoCharge()
  const [asking, setAsking] = useState<QuickButton | 'other' | null>(null)
  const [name, setName] = useState('')
  const [amount, setAmount] = useState('')
  const [added, setAdded] = useState<QuickChargeAdded[]>([])
  const last = added[added.length - 1]
  const count = (b: QuickButton) => added.filter((a) => a.description === b.name).length

  const send = (v: { charge_type_id?: number; description?: string; unit_price?: string }) =>
    charge.mutate(
      { roomId, ...v },
      {
        onSuccess: (res) => {
          setAdded((list) => [...list, res.data])
          setAsking(null)
          setName('')
          setAmount('')
        },
      },
    )

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap gap-2">
        {buttons.map((b) => (
          <Button
            key={b.id}
            size="lg"
            variant="secondary"
            disabled={charge.isPending}
            onClick={() => (b.price ? send({ charge_type_id: b.id }) : setAsking(b))}
            aria-label={b.price ? t('quickCharge.add', { name: b.name, price: money(b.price, b.currency) }) : b.name}
          >
            <span className="flex flex-col items-start leading-tight">
              <span>{b.name}</span>
              <span className="text-xs font-normal text-muted">{b.price ? money(b.price, b.currency) : t('quickCharge.typeAmount')}</span>
            </span>
            {count(b) > 0 && <span className="rounded-full bg-accent-soft px-1.5 text-xs font-semibold text-accent-text">×{count(b)}</span>}
          </Button>
        ))}
        <Button size="lg" variant="ghost" disabled={charge.isPending} onClick={() => setAsking('other')}>
          <PlusIcon aria-hidden className="size-4" />
          {t('quickCharge.other')}
        </Button>
      </div>

      {asking && (
        <form
          className="flex flex-wrap items-end gap-2 rounded-lg bg-sunken p-3"
          onSubmit={(e) => {
            e.preventDefault()
            send(asking === 'other' ? { description: name.trim(), unit_price: amount } : { charge_type_id: asking.id, unit_price: amount })
          }}
        >
          {asking === 'other' ? (
            <Field label={t('quickCharge.what')} placeholder={t('quickCharge.whatHint')} value={name} onChange={(e) => setName(e.target.value)} autoFocus />
          ) : (
            <span className="min-h-touch self-center text-[13px] font-medium">{asking.name}</span>
          )}
          <Field label={t('reservations.amount')} inputMode="decimal" value={amount} autoFocus={asking !== 'other'} onChange={(e) => setAmount(e.target.value.replace(/[^0-9.]/g, ''))} />
          <Button type="submit" disabled={!amount || Number(amount) <= 0 || (asking === 'other' && !name.trim()) || charge.isPending}>
            {t('quickCharge.addToRoom', { room: roomNumber })}
          </Button>
          <Button type="button" variant="ghost" onClick={() => setAsking(null)}>
            {t('common.cancel')}
          </Button>
        </form>
      )}

      {charge.error && <Alert>{err(charge.error)}</Alert>}
      {last && (
        <div role="status" className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-ok-bg px-3 py-2 text-[13px] text-ok-text">
          <span className="flex items-center gap-1.5">
            <CheckCircleIcon aria-hidden className="size-4" />
            {t('quickCharge.added', { name: last.description, amount: money(last.amount, last.currency), room: last.room })}
          </span>
          <Button
            variant="ghost"
            disabled={undo.isPending}
            onClick={() => undo.mutate(last.id, { onSuccess: () => setAdded((list) => list.slice(0, -1)) })}
          >
            <ArrowUturnLeftIcon aria-hidden className="size-4" />
            {t('quickCharge.undo')}
          </Button>
        </div>
      )}
      {undo.error && <Alert>{err(undo.error)}</Alert>}
    </div>
  )
}
