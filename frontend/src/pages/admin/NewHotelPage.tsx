import { useState, type FormEvent } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Select } from '@/components/ui/Select'
import { useCreateHotel, usePlans, usePlatformSettings, type NewHotelResult } from '@/lib/admin'
import { ApiError } from '@/lib/api'

export function NewHotelPage() {
  const { t } = useTranslation()
  const plans = usePlans()
  const settings = usePlatformSettings()
  const create = useCreateHotel()
  const [result, setResult] = useState<NewHotelResult | null>(null)
  const [form, setForm] = useState({
    name: '',
    email: '',
    phone: '',
    city: '',
    address: '',
    default_currency: 'ETB',
    plan_id: '',
    billing_cycle: 'monthly' as 'monthly' | 'yearly',
    trial_days: '',
    admin_name: '',
    admin_email: '',
    admin_phone: '',
  })
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))
  const error = create.error instanceof ApiError ? create.error : null
  const planId = form.plan_id || String(plans.data?.data.find((p) => p.code === 'standard')?.id ?? plans.data?.data[0]?.id ?? '')

  function submit(e: FormEvent) {
    e.preventDefault()
    create.mutate(
      {
        name: form.name,
        email: form.email || undefined,
        phone: form.phone || undefined,
        city: form.city || undefined,
        address: form.address || undefined,
        default_currency: form.default_currency,
        plan_id: Number(planId),
        billing_cycle: form.billing_cycle,
        trial_days: form.trial_days === '' ? undefined : Number(form.trial_days),
        admin: { name: form.admin_name, email: form.admin_email, phone: form.admin_phone || undefined },
      },
      { onSuccess: setResult },
    )
  }

  if (result) {
    return (
      <div className="mx-auto flex max-w-xl flex-col gap-6">
        <PageHeader title={t('admin.newHotel.created', { name: result.data.name })} />
        <Card className="flex flex-col gap-4">
          <p>{t('admin.newHotel.credentialsIntro')}</p>
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 rounded-lg bg-sunken p-4">
            <dt className="text-muted">{t('login.email')}</dt>
            <dd className="font-semibold break-all">{result.admin.email}</dd>
            <dt className="text-muted">{t('admin.newHotel.tempPassword')}</dt>
            <dd className="font-mono font-semibold break-all">{result.admin.temporary_password}</dd>
          </dl>
          <Alert tone={result.admin.email_sent ? 'ok' : 'info'}>
            {result.admin.email_sent ? t('admin.newHotel.emailSent') : t('admin.newHotel.emailNotSent')}
          </Alert>
          <Link
            to={`/admin/hotels/${result.data.id}`}
            className="inline-flex min-h-touch items-center justify-center rounded-lg bg-accent px-4 font-semibold text-on-accent hover:bg-accent-hover"
          >
            {t('admin.newHotel.openHotel')}
          </Link>
        </Card>
      </div>
    )
  }

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-6">
      <PageHeader title={t('admin.hotels.new')} subtitle={t('admin.newHotel.subtitle')} />
      <form onSubmit={submit} noValidate className="flex flex-col gap-6">
        {create.error && !error && <Alert>{t('common.serverUnreachable')}</Alert>}

        <Card className="grid gap-4 sm:grid-cols-2">
          <h2 className="font-semibold sm:col-span-2">{t('admin.newHotel.hotelSection')}</h2>
          <Field label={t('admin.newHotel.name')} value={form.name} onChange={set('name')} error={error?.field('name')} required />
          <Field label={t('admin.newHotel.city')} value={form.city} onChange={set('city')} error={error?.field('city')} />
          <Field label={t('admin.newHotel.email')} type="email" value={form.email} onChange={set('email')} error={error?.field('email')} />
          <Field label={t('admin.newHotel.phone')} type="tel" inputMode="tel" value={form.phone} onChange={set('phone')} error={error?.field('phone')} />
          <Field label={t('admin.newHotel.address')} value={form.address} onChange={set('address')} error={error?.field('address')} />
          <Select label={t('admin.newHotel.currency')} value={form.default_currency} onChange={set('default_currency')}>
            <option value="ETB">ETB – Ethiopian birr</option>
            <option value="USD">USD – US dollar</option>
          </Select>
        </Card>

        <Card className="grid gap-4 sm:grid-cols-2">
          <h2 className="font-semibold sm:col-span-2">{t('admin.newHotel.planSection')}</h2>
          <Select label={t('admin.hotels.plan')} value={planId} onChange={set('plan_id')} error={error?.field('plan_id')}>
            {plans.data?.data.filter((p) => p.is_active).map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
          </Select>
          <Select label={t('admin.newHotel.billing')} value={form.billing_cycle} onChange={set('billing_cycle')}>
            <option value="monthly">{t('admin.billing.monthly')}</option>
            <option value="yearly">{t('admin.billing.yearly')}</option>
          </Select>
          <Field
            label={t('admin.newHotel.trialDays')}
            type="number"
            inputMode="numeric"
            min={0}
            max={365}
            value={form.trial_days}
            placeholder={String(settings.data?.data.default_trial_days ?? 30)}
            hint={t('admin.newHotel.trialHint', { days: settings.data?.data.default_trial_days ?? 30 })}
            onChange={set('trial_days')}
            error={error?.field('trial_days')}
          />
        </Card>

        <Card className="grid gap-4 sm:grid-cols-2">
          <h2 className="font-semibold sm:col-span-2">{t('admin.newHotel.adminSection')}</h2>
          <Field label={t('admin.newHotel.adminName')} value={form.admin_name} onChange={set('admin_name')} error={error?.field('admin.name')} required />
          <Field label={t('admin.newHotel.adminEmail')} type="email" value={form.admin_email} onChange={set('admin_email')} error={error?.field('admin.email')} required />
          <Field label={t('admin.newHotel.adminPhone')} type="tel" inputMode="tel" value={form.admin_phone} onChange={set('admin_phone')} error={error?.field('admin.phone')} />
        </Card>

        <div className="flex justify-end gap-3">
          <Link to="/admin/hotels" className="inline-flex min-h-touch items-center rounded-lg px-4 font-semibold text-muted hover:bg-sunken">
            {t('common.cancel')}
          </Link>
          <Button type="submit" size="lg" disabled={create.isPending || !form.name || !form.admin_name || !form.admin_email}>
            {create.isPending ? t('common.saving') : t('admin.newHotel.submit')}
          </Button>
        </div>
      </form>
    </div>
  )
}
