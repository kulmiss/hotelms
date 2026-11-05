import { useState, type FormEvent } from 'react'
import { Link } from 'react-router'
import { ArrowLeftIcon, ArrowRightIcon } from '@heroicons/react/20/solid'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/ui/Alert'
import { Button, buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Select } from '@/components/ui/Select'
import { TabPanel, Tabs } from '@/components/ui/Tabs'
import { useCreateHotel, usePlans, usePlatformSettings, type NewHotelResult } from '@/lib/admin'
import { ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'

export function NewHotelPage() {
  const { t } = useTranslation()
  const plans = usePlans()
  const settings = usePlatformSettings()
  const create = useCreateHotel()
  const [result, setResult] = useState<NewHotelResult | null>(null)
  const [tab, setTab] = useState<'hotel' | 'plan' | 'admin'>('hotel')
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
      {
        onSuccess: setResult,
        onError: (e) => {
          // Jump to the first tab that has a mistake.
          if (e instanceof ApiError) {
            const first = (['hotel', 'plan', 'admin'] as const).find((k) =>
              ({ hotel: ['name', 'email', 'phone', 'city', 'address', 'default_currency'], plan: ['plan_id', 'billing_cycle', 'trial_days'], admin: ['admin.name', 'admin.email', 'admin.phone'] })[k].some((f) => e.field(f)),
            )
            if (first) setTab(first)
          }
        },
      },
    )
  }

  if (result) {
    return (
      <div className="mx-auto flex max-w-xl flex-col gap-6">
        <PageHeader title={t('admin.newHotel.created', { name: result.data.name })} />
        <Card className="flex flex-col gap-4">
          <p>{t('admin.newHotel.credentialsIntro')}</p>
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 rounded-xl bg-sunken p-4">
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
            className={buttonClass('primary')}
          >
            {t('admin.newHotel.openHotel')}
          </Link>
        </Card>
      </div>
    )
  }

  const tabs = ['hotel', 'plan', 'admin'] as const
  const fieldsOf: Record<(typeof tabs)[number], string[]> = {
    hotel: ['name', 'email', 'phone', 'city', 'address', 'default_currency'],
    plan: ['plan_id', 'billing_cycle', 'trial_days'],
    admin: ['admin.name', 'admin.email', 'admin.phone'],
  }
  const hasError = (tab: (typeof tabs)[number]) => fieldsOf[tab].some((f) => error?.field(f))
  const complete = { hotel: !!form.name, plan: !!planId, admin: !!form.admin_name && !!form.admin_email }
  const index = tabs.indexOf(tab)
  const plan = plans.data?.data.find((p) => String(p.id) === planId)

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-6">
      <PageHeader
        title={t('admin.hotels.new')}
        subtitle={t('admin.newHotel.subtitle')}
        breadcrumbs={[{ label: t('nav.hotels'), to: '/admin/hotels' }, { label: t('admin.newHotel.crumb') }]}
      />
      <form
        onSubmit={(e) => {
          if (tab !== 'admin') {
            e.preventDefault()
            setTab(tabs[index + 1])
            return
          }
          submit(e)
        }}
        noValidate
        className="flex flex-col gap-5"
      >
        <Tabs
          label={t('admin.hotels.new')}
          active={tab}
          onChange={(k) => setTab(k as (typeof tabs)[number])}
          items={tabs.map((k) => ({
            key: k,
            label: t(`admin.newHotel.tab.${k}`),
            state: hasError(k) ? 'error' : complete[k] && k !== tab ? 'done' : undefined,
          }))}
        />

        {create.error && !error && <Alert>{t('common.serverUnreachable')}</Alert>}

        <Card>
          <TabPanel id="hotel" active={tab}>
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <Field label={t('admin.newHotel.name')} value={form.name} onChange={set('name')} error={error?.field('name')} required autoFocus />
              </div>
              <Field label={t('admin.newHotel.city')} value={form.city} onChange={set('city')} error={error?.field('city')} />
              <Field label={t('admin.newHotel.address')} value={form.address} onChange={set('address')} error={error?.field('address')} />
              <Field label={t('admin.newHotel.phone')} type="tel" inputMode="tel" value={form.phone} onChange={set('phone')} error={error?.field('phone')} />
              <Field label={t('admin.newHotel.email')} type="email" value={form.email} onChange={set('email')} error={error?.field('email')} />
              <Select label={t('admin.newHotel.currency')} value={form.default_currency} onChange={set('default_currency')}>
                <option value="ETB">ETB – Ethiopian birr</option>
                <option value="USD">USD – US dollar</option>
              </Select>
            </div>
          </TabPanel>

          <TabPanel id="plan" active={tab}>
            <div className="flex flex-col gap-5">
              <fieldset className="flex flex-col gap-2">
                <legend className="mb-2 text-sm font-medium text-muted">{t('admin.hotels.plan')}</legend>
                <div className="grid gap-3 sm:grid-cols-2">
                  {plans.data?.data.filter((p) => p.is_active).map((p) => (
                    <label
                      key={p.id}
                      className={cn(
                        'flex cursor-pointer flex-col gap-1 rounded-lg p-4 shadow-sm ring-1 transition duration-75',
                        String(p.id) === planId ? 'bg-accent-soft ring-2 ring-accent' : 'ring-ring-strong hover:bg-sunken',
                      )}
                    >
                      <span className="flex items-center gap-3">
                        <input type="radio" name="plan" aria-label={p.name} className="size-5 accent-accent" value={p.id} checked={String(p.id) === planId} onChange={set('plan_id')} />
                        <span className="font-semibold">{p.name}</span>
                      </span>
                      <span className="pl-8 text-sm text-muted">
                        {t('admin.plans.limits', {
                          rooms: p.max_rooms ?? t('admin.plans.unlimited'),
                          branches: p.max_branches ?? t('admin.plans.unlimited'),
                          staff: p.max_staff ?? t('admin.plans.unlimited'),
                        })}
                      </span>
                    </label>
                  ))}
                </div>
                {error?.field('plan_id') && <p className="text-sm text-danger-text">{error.field('plan_id')}</p>}
              </fieldset>
              <div className="grid gap-4 sm:grid-cols-2">
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
              </div>
            </div>
          </TabPanel>

          <TabPanel id="admin" active={tab}>
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <Field label={t('admin.newHotel.adminName')} value={form.admin_name} onChange={set('admin_name')} error={error?.field('admin.name')} required />
              </div>
              <Field label={t('admin.newHotel.adminEmail')} type="email" value={form.admin_email} onChange={set('admin_email')} error={error?.field('admin.email')} required />
              <Field label={t('admin.newHotel.adminPhone')} type="tel" inputMode="tel" value={form.admin_phone} onChange={set('admin_phone')} error={error?.field('admin.phone')} />
              <p className="text-sm text-muted sm:col-span-2">{t('admin.newHotel.adminNote')}</p>
            </div>
          </TabPanel>
        </Card>

        {tab === 'admin' && (
          <div className="flex flex-col gap-1 rounded-lg bg-sunken p-4 text-sm">
            <span className="font-semibold">{t('admin.newHotel.summary')}</span>
            <span className="text-muted">
              {form.name || '—'}, {plan?.name ?? '—'}, {t(`admin.billing.${form.billing_cycle}`)}
            </span>
          </div>
        )}

        <div className="flex items-center justify-between gap-3">
          {index > 0 ? (
            <Button variant="ghost" onClick={() => setTab(tabs[index - 1])}>
              <ArrowLeftIcon aria-hidden className="size-5" />
              {t('common.back')}
            </Button>
          ) : (
            <Link to="/admin/hotels" className={buttonClass('ghost')}>
              {t('common.cancel')}
            </Link>
          )}
          {tab !== 'admin' ? (
            <Button type="submit">
              {t('common.next')}
              <ArrowRightIcon aria-hidden className="size-5" />
            </Button>
          ) : (
            <Button type="submit" size="lg" disabled={create.isPending || !complete.hotel || !complete.admin}>
              {create.isPending ? t('common.saving') : t('admin.newHotel.submit')}
            </Button>
          )}
        </div>
      </form>
    </div>
  )
}
