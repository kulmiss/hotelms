import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { Select } from '@/components/ui/Select'
import { ApiError } from '@/lib/api'
import { useAddCharge, useChargeTypes } from '@/lib/desk'

const err = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : null)

/** Add an extra charge (laundry, food…) to a bill. Also used on the check-out screen. */
export function ChargePanel({ invoiceId, onClose }: { invoiceId: number; onClose: () => void }) {
  const { t } = useTranslation()
  const types = useChargeTypes()
  const add = useAddCharge(invoiceId)
  const [typeId, setTypeId] = useState<number | null>(null)
  const [description, setDescription] = useState('')
  const [qty, setQty] = useState('1')
  const [price, setPrice] = useState('')
  const chosen = types.data?.data.find((x) => x.id === typeId)

  return (
    <Card className="grid gap-3 animate-page-in sm:grid-cols-4">
      <Select
        label={t('billing.chargeType')}
        value={String(typeId ?? '')}
        onChange={(e) => {
          const id = e.target.value ? Number(e.target.value) : null
          setTypeId(id)
          const ct = types.data?.data.find((x) => x.id === id)
          if (ct?.default_price) setPrice(String(Number(ct.default_price)))
        }}
      >
        <option value="">{t('billing.other')}</option>
        {types.data?.data.map((x) => (
          <option key={x.id} value={x.id}>
            {x.name}
          </option>
        ))}
      </Select>
      <Field label={t('billing.description')} value={description} placeholder={chosen?.name} onChange={(e) => setDescription(e.target.value)} />
      <Field label={t('billing.qty')} inputMode="decimal" value={qty} onChange={(e) => setQty(e.target.value.replace(/[^0-9.]/g, ''))} />
      <Field label={t('billing.unitPrice')} inputMode="decimal" value={price} onChange={(e) => setPrice(e.target.value.replace(/[^0-9.]/g, ''))} />
      {add.error && <div className="sm:col-span-4"><Alert>{err(add.error)}</Alert></div>}
      <div className="flex justify-end gap-2 sm:col-span-4">
        <Button variant="ghost" onClick={onClose}>
          {t('common.cancel')}
        </Button>
        <Button disabled={!price || (!typeId && !description) || add.isPending} onClick={() => add.mutate({ type: 'extra', charge_type_id: typeId, description: description || undefined, quantity: qty || '1', unit_price: price }, { onSuccess: onClose })}>
          {t('billing.addCharge')}
        </Button>
      </div>
    </Card>
  )
}
