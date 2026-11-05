import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { usePlans, useSavePlan, type Plan } from '@/lib/admin'
import { ApiError } from '@/lib/api'
import { money } from '@/lib/format'

const MODULES = ['housekeeping', 'cashier_close', 'shifts', 'attendance']

export function PlansPage() {
  const { t } = useTranslation()
  const plans = usePlans()
  const [editing, setEditing] = useState<number | null>(null)

  return (
    <div className="mx-auto flex max-w-6xl flex-col gap-6">
      <PageHeader title={t('nav.plans')} subtitle={t('admin.plans.subtitle')} />
      <div className="grid gap-4 md:grid-cols-2">
        {plans.data?.data.map((plan) =>
          editing === plan.id ? (
            <PlanForm key={plan.id} plan={plan} onDone={() => setEditing(null)} />
          ) : (
            <Card key={plan.id} className="flex flex-col gap-3">
              <div className="flex items-start justify-between gap-2">
                <div>
                  <h2 className="text-lg font-semibold">{plan.name}</h2>
                  <p className="text-sm text-muted">{plan.description}</p>
                </div>
                {!plan.is_active && <Badge tone="grey">{t('admin.plans.hidden')}</Badge>}
              </div>
              <p className="text-sm">
                {plan.is_custom
                  ? t('admin.plans.customPrice')
                  : t('admin.plans.priceLine', {
                      perRoom: money(plan.price_per_room, plan.currency),
                      minimum: money(plan.minimum_monthly, plan.currency),
                    })}
              </p>
              <p className="text-sm text-muted">
                {t('admin.plans.limits', {
                  rooms: plan.max_rooms ?? t('admin.plans.unlimited'),
                  branches: plan.max_branches ?? t('admin.plans.unlimited'),
                  staff: plan.max_staff ?? t('admin.plans.unlimited'),
                })}
              </p>
              <div className="flex flex-wrap gap-1">
                {plan.modules.map((m) => (
                  <Badge key={m} tone="accent">
                    {t(`modules.${m}`)}
                  </Badge>
                ))}
              </div>
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted">{t('admin.plans.hotelsOnPlan', { count: plan.hotels_count ?? 0 })}</span>
                <Button variant="secondary" onClick={() => setEditing(plan.id)}>
                  {t('common.edit')}
                </Button>
              </div>
            </Card>
          ),
        )}
      </div>
    </div>
  )
}

const numOrNull = (v: string) => (v === '' ? null : Number(v))

function PlanForm({ plan, onDone }: { plan: Plan; onDone: () => void }) {
  const { t } = useTranslation()
  const save = useSavePlan()
  const [f, setF] = useState({
    name: plan.name,
    description: plan.description ?? '',
    price_per_room: plan.price_per_room,
    minimum_monthly: plan.minimum_monthly,
    yearly_free_months: String(plan.yearly_free_months),
    max_rooms: plan.max_rooms === null ? '' : String(plan.max_rooms),
    max_branches: plan.max_branches === null ? '' : String(plan.max_branches),
    max_staff: plan.max_staff === null ? '' : String(plan.max_staff),
    modules: plan.modules,
    is_active: plan.is_active,
  })
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))
  const error = save.error instanceof ApiError ? save.error : null

  function submit(e: FormEvent) {
    e.preventDefault()
    save.mutate(
      {
        id: plan.id,
        name: f.name,
        code: plan.code,
        description: f.description || null,
        price_per_room: f.price_per_room,
        minimum_monthly: f.minimum_monthly,
        yearly_free_months: Number(f.yearly_free_months),
        currency: plan.currency,
        is_custom: plan.is_custom,
        max_rooms: numOrNull(f.max_rooms),
        max_branches: numOrNull(f.max_branches),
        max_staff: numOrNull(f.max_staff),
        modules: f.modules,
        is_active: f.is_active,
      },
      { onSuccess: onDone },
    )
  }

  return (
    <Card>
      <form onSubmit={submit} noValidate className="grid gap-4 sm:grid-cols-2">
        {save.error && !error && <Alert>{t('common.serverUnreachable')}</Alert>}
        <Field label={t('admin.plans.name')} value={f.name} onChange={set('name')} error={error?.field('name')} />
        <Field label={t('admin.plans.description')} value={f.description} onChange={set('description')} />
        <Field label={t('admin.plans.pricePerRoom', { currency: plan.currency })} inputMode="decimal" value={f.price_per_room} onChange={set('price_per_room')} error={error?.field('price_per_room')} />
        <Field label={t('admin.plans.minimum', { currency: plan.currency })} inputMode="decimal" value={f.minimum_monthly} onChange={set('minimum_monthly')} error={error?.field('minimum_monthly')} />
        <Field label={t('admin.plans.freeMonths')} type="number" inputMode="numeric" min={0} max={6} value={f.yearly_free_months} onChange={set('yearly_free_months')} error={error?.field('yearly_free_months')} />
        <Field label={t('admin.plans.maxRooms')} type="number" inputMode="numeric" min={1} value={f.max_rooms} hint={t('admin.plans.emptyUnlimited')} onChange={set('max_rooms')} />
        <Field label={t('admin.plans.maxBranches')} type="number" inputMode="numeric" min={1} value={f.max_branches} hint={t('admin.plans.emptyUnlimited')} onChange={set('max_branches')} />
        <Field label={t('admin.plans.maxStaff')} type="number" inputMode="numeric" min={1} value={f.max_staff} hint={t('admin.plans.emptyUnlimited')} onChange={set('max_staff')} />
        <fieldset className="flex flex-col gap-2 sm:col-span-2">
          <legend className="mb-1 text-sm font-medium text-muted">{t('admin.plans.modules')}</legend>
          <div className="flex flex-wrap gap-x-6">
            {MODULES.map((m) => (
              <label key={m} className="flex min-h-touch items-center gap-3 text-sm">
                <input
                  type="checkbox"
                  className="size-5 accent-accent"
                  checked={f.modules.includes(m)}
                  onChange={(e) =>
                    setF((s) => ({ ...s, modules: e.target.checked ? [...s.modules, m] : s.modules.filter((x) => x !== m) }))
                  }
                />
                {t(`modules.${m}`)}
              </label>
            ))}
          </div>
        </fieldset>
        <label className="flex min-h-touch items-center gap-3 text-sm sm:col-span-2">
          <input type="checkbox" className="size-5 accent-accent" checked={f.is_active} onChange={(e) => setF((s) => ({ ...s, is_active: e.target.checked }))} />
          {t('admin.plans.active')}
        </label>
        <div className="flex justify-end gap-2 sm:col-span-2">
          <Button variant="ghost" onClick={onDone}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={save.isPending}>
            {t('common.save')}
          </Button>
        </div>
      </form>
    </Card>
  )
}
