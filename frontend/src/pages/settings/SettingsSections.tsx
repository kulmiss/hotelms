import { useRef, useState, type FormEvent, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { ImageIcon } from 'lucide-react'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button, buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { ResourceList } from '@/components/ui/ResourceList'
import { Select } from '@/components/ui/Select'
import { ApiError, apiUrl, post } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { date, money } from '@/lib/format'
import { useHotelLogo, useSettingsList, useSettingsObject, useSettingsObjectSave } from '@/lib/settings'
import { useQueryClient } from '@tanstack/react-query'
import { MyInvoices } from '@/pages/admin/HotelInvoices'
import type { HotelInvoice } from '@/lib/platform'

/* ------------------------------------------------------------- helpers */

function SectionForm({
  title,
  description,
  onSubmit,
  saving,
  saved,
  error,
  children,
}: {
  title: string
  description?: string
  onSubmit: () => void
  saving: boolean
  saved: boolean
  error: unknown
  children: ReactNode
}) {
  const { t } = useTranslation()
  return (
    <form
      noValidate
      onSubmit={(e: FormEvent) => {
        e.preventDefault()
        onSubmit()
      }}
      className="flex flex-col gap-4"
    >
      <div className="flex flex-col gap-1">
        <h2 className="text-base font-semibold">{title}</h2>
        {description && <p className="max-w-prose text-sm text-muted">{description}</p>}
      </div>
      <Card className="flex flex-col gap-4">
        {saved && <Alert tone="ok">{t('common.saved')}</Alert>}
        {error && !(error instanceof ApiError) ? <Alert>{t('common.serverUnreachable')}</Alert> : null}
        {children}
        <div className="flex justify-end">
          <Button type="submit" disabled={saving}>
            {t('common.save')}
          </Button>
        </div>
      </Card>
    </form>
  )
}

const yesNo = (v: unknown, t: (k: string) => string) => (v ? <Badge tone="ok">{t('common.on')}</Badge> : <Badge tone="grey">{t('common.off')}</Badge>)

/* ------------------------------------------------------------- profile */

type Profile = {
  name: string
  legal_name: string | null
  tin: string | null
  vat_number: string | null
  email: string | null
  phone: string | null
  city: string | null
  address: string | null
  logo_url: string | null
  invoice_prefix: string
  invoice_footer: string | null
  receipt_paper: 'a4' | 'receipt'
  calendar?: 'gregorian' | 'ethiopian' | 'both'
  language?: 'en' | 'am'
}

export function ProfileSettings() {
  const q = useSettingsObject<Profile>('/settings/hotel')
  return q.data ? <ProfileForm initial={q.data.data} /> : null
}

function ProfileForm({ initial }: { initial: Profile }) {
  const { t } = useTranslation()
  const save = useSettingsObjectSave<Profile>('/settings/hotel')
  const logo = useHotelLogo()
  const input = useRef<HTMLInputElement>(null)
  const [f, setF] = useState(initial)
  const error = save.error instanceof ApiError ? save.error : null
  const set = (k: keyof Profile) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value })

  return (
    <div className="flex flex-col gap-6">
      <SectionForm title={t('settings.sections.profile')} description={t('settings.profile.help')} onSubmit={() => save.mutate(f)} saving={save.isPending} saved={save.isSuccess} error={save.error}>
        <div className="flex flex-wrap items-center gap-4">
          <span className="flex size-16 items-center justify-center overflow-hidden rounded-xl bg-sunken border">
            {initial.logo_url ? <img src={initial.logo_url} alt="" className="size-full object-contain p-1.5" /> : <ImageIcon aria-hidden className="size-6 text-icon" />}
          </span>
          <input ref={input} type="file" accept="image/png,image/jpeg,image/webp" className="sr-only" aria-label={t('settings.profile.uploadLogo')} onChange={(e) => e.target.files?.[0] && logo.mutate(e.target.files[0])} />
          <Button variant="secondary" disabled={logo.isPending} onClick={() => input.current?.click()}>
            {initial.logo_url ? t('admin.settings.replaceLogo') : t('settings.profile.uploadLogo')}
          </Button>
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('settings.profile.name')} value={f.name} onChange={set('name')} error={error?.field('name')} />
          <Field label={t('settings.profile.legalName')} value={f.legal_name ?? ''} onChange={set('legal_name')} error={error?.field('legal_name')} />
          <Field label={t('settings.profile.tin')} value={f.tin ?? ''} onChange={set('tin')} error={error?.field('tin')} />
          <Field label={t('settings.profile.vat')} value={f.vat_number ?? ''} onChange={set('vat_number')} error={error?.field('vat_number')} />
          <Field label={t('admin.newHotel.email')} type="email" value={f.email ?? ''} onChange={set('email')} error={error?.field('email')} />
          <Field label={t('admin.newHotel.phone')} type="tel" value={f.phone ?? ''} onChange={set('phone')} error={error?.field('phone')} />
          <Field label={t('admin.newHotel.city')} value={f.city ?? ''} onChange={set('city')} error={error?.field('city')} />
          <Field label={t('admin.newHotel.address')} value={f.address ?? ''} onChange={set('address')} error={error?.field('address')} />
        </div>
        <h3 className="pt-2 text-sm font-semibold">{t('settings.profile.invoices')}</h3>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('settings.profile.prefix')} value={f.invoice_prefix} hint={t('settings.profile.prefixHint', { prefix: (f.invoice_prefix || 'INV').toUpperCase() })} onChange={set('invoice_prefix')} error={error?.field('invoice_prefix')} />
          <Field label={t('settings.profile.footer')} value={f.invoice_footer ?? ''} hint={t('settings.profile.footerHint')} onChange={set('invoice_footer')} error={error?.field('invoice_footer')} />
          <Select label={t('settings.profile.paper')} hint={t('settings.profile.paperHint')} value={f.receipt_paper ?? 'a4'} onChange={(e) => setF({ ...f, receipt_paper: e.target.value as Profile['receipt_paper'] })}>
            <option value="a4">{t('billing.paper.a4')}</option>
            <option value="receipt">{t('billing.paper.receipt')}</option>
          </Select>
          <Select label={t('language.hotel')} hint={t('language.hotelHint')} value={f.language ?? 'en'} onChange={(e) => setF({ ...f, language: e.target.value as Profile['language'] })}>
            <option value="en">{t('language.names.en')}</option>
            <option value="am">{t('language.names.am')}</option>
          </Select>
          <Select label={t('calendar.hotel')} hint={t('calendar.hotelHint')} value={f.calendar ?? 'gregorian'} onChange={(e) => setF({ ...f, calendar: e.target.value as Profile['calendar'] })}>
            {(['gregorian', 'ethiopian', 'both'] as const).map((m) => <option key={m} value={m}>{t(`calendar.modes.${m}`)}</option>)}
          </Select>
        </div>
        {/* Live invoice header preview */}
        <div className="flex flex-col gap-1 rounded-lg bg-sunken p-4 text-sm">
          <span className="text-xs text-muted">{t('admin.settings.preview')}</span>
          <span className="text-sm font-semibold">{f.legal_name || f.name}</span>
          <span className="text-muted">{[f.address, f.city].filter(Boolean).join(', ')}</span>
          <span className="text-muted">
            {f.tin && `${t('settings.profile.tin')}: ${f.tin}`} {f.vat_number && `  ${t('settings.profile.vat')}: ${f.vat_number}`}
          </span>
        </div>
      </SectionForm>
    </div>
  )
}

/* ------------------------------------------------------------- lists */

export function BranchesSettings() {
  const { t } = useTranslation()
  return (
    <ResourceList
      endpoint="/settings/branches"
      title={t('settings.sections.branches')}
      description={t('settings.branches.help')}
      addLabel={t('settings.branches.add')}
      defaults={{ name: '', code: '', city: '', address: '', phone: '', email: '', check_in_time: '14:00', check_out_time: '12:00', status: 'active' }}
      columns={[
        { label: t('settings.branches.name'), render: (r) => <span className="font-medium">{String(r.name)}</span> },
        { label: t('settings.branches.code'), render: (r) => String(r.code ?? '—') },
        { label: t('settings.branches.times'), render: (r) => `${String(r.check_in_time).slice(0, 5)} / ${String(r.check_out_time).slice(0, 5)}` },
        { label: t('team.staff.status'), render: (r) => (r.status === 'active' ? <Badge tone="ok">{t('common.active')}</Badge> : <Badge tone="grey">{t('common.inactive')}</Badge>) },
      ]}
      fields={[
        { name: 'name', label: t('settings.branches.name') },
        { name: 'code', label: t('settings.branches.code'), hint: t('settings.branches.codeHint'), nullable: true },
        { name: 'city', label: t('admin.newHotel.city'), nullable: true },
        { name: 'address', label: t('admin.newHotel.address'), nullable: true },
        { name: 'phone', label: t('admin.newHotel.phone'), nullable: true },
        { name: 'email', label: t('admin.newHotel.email'), nullable: true },
        { name: 'check_in_time', label: t('settings.branches.checkIn'), type: 'time' },
        { name: 'check_out_time', label: t('settings.branches.checkOut'), type: 'time' },
        { name: 'status', label: t('team.staff.status'), type: 'select', options: [{ value: 'active', label: t('common.active') }, { value: 'inactive', label: t('common.inactive') }] },
      ]}
    />
  )
}

export function TaxesSettings() {
  const { t } = useTranslation()
  const applies = [
    { value: 'all', label: t('settings.taxes.all') },
    { value: 'rooms', label: t('settings.taxes.rooms') },
    { value: 'extras', label: t('settings.taxes.extras') },
  ]
  return (
    <ResourceList
      endpoint="/settings/taxes"
      title={t('settings.sections.taxes')}
      description={t('settings.taxes.help')}
      addLabel={t('settings.taxes.add')}
      defaults={{ name: '', rate: '', applies_to: 'all', is_inclusive: false, is_active: true }}
      header={<TaxExample />}
      columns={[
        { label: t('settings.taxes.name'), render: (r) => <span className="font-medium">{String(r.name)}</span> },
        { label: t('settings.taxes.rate'), render: (r) => `${Number(r.rate)}%` },
        { label: t('settings.taxes.appliesTo'), render: (r) => applies.find((a) => a.value === r.applies_to)?.label },
        { label: t('settings.taxes.included'), render: (r) => (r.is_inclusive ? t('settings.taxes.inPrice') : t('settings.taxes.added')) },
        { label: t('common.active'), render: (r) => yesNo(r.is_active, t) },
      ]}
      fields={[
        { name: 'name', label: t('settings.taxes.name') },
        { name: 'rate', label: t('settings.taxes.ratePercent'), type: 'decimal' },
        { name: 'applies_to', label: t('settings.taxes.appliesTo'), type: 'select', options: applies },
        { name: 'is_inclusive', label: t('settings.taxes.inclusive'), type: 'checkbox', hint: t('settings.taxes.inclusiveHint') },
        { name: 'is_active', label: t('common.active'), type: 'checkbox' },
      ]}
    />
  )
}

/** "Prices include tax" explained with a live example. */
function TaxExample() {
  const { t } = useTranslation()
  const taxes = useSettingsList<{ rate: string; is_inclusive: boolean; is_active: boolean; name: string }>('/settings/taxes')
  const active = (taxes.data?.data ?? []).filter((x) => x.is_active)
  if (!active.length) return null
  // Example on a 1,000 room price, in whole cents to avoid float maths.
  const base = 100000
  const added = active.filter((x) => !x.is_inclusive).reduce((sum, x) => sum + Math.round((base * Number(x.rate) * 100) / 10000), 0)
  return (
    <p className="rounded-lg bg-sunken px-4 py-3 text-sm text-muted">
      {t('settings.taxes.example', { price: money('1000.00'), total: money(((base + added) / 100).toFixed(2)), names: active.map((x) => x.name).join(', ') })}
    </p>
  )
}

export function PaymentMethodsSettings() {
  const { t } = useTranslation()
  const types = [
    { value: 'cash', label: t('admin.methods.cash') },
    { value: 'mobile_money', label: t('admin.methods.mobile_money') },
    { value: 'bank_transfer', label: t('admin.methods.bank_transfer') },
  ]
  return (
    <ResourceList
      endpoint="/settings/payment-methods"
      title={t('settings.sections.payment-methods')}
      description={t('settings.payments.help')}
      addLabel={t('settings.payments.add')}
      defaults={{ name: '', type: 'mobile_money', is_active: true }}
      columns={[
        { label: t('settings.payments.name'), render: (r) => <span className="font-medium">{String(r.name)}</span> },
        { label: t('settings.payments.type'), render: (r) => types.find((x) => x.value === r.type)?.label },
        { label: t('common.active'), render: (r) => yesNo(r.is_active, t) },
      ]}
      fields={[
        { name: 'name', label: t('settings.payments.name') },
        { name: 'type', label: t('settings.payments.type'), type: 'select', options: types },
        { name: 'is_active', label: t('common.active'), type: 'checkbox' },
      ]}
    />
  )
}

export function ChargesSettings() {
  const { t } = useTranslation()
  return (
    <ResourceList
      endpoint="/settings/charge-types"
      title={t('settings.sections.charges')}
      description={t('settings.charges.help')}
      addLabel={t('settings.charges.add')}
      defaults={{ name: '', default_price: '', currency: 'ETB', is_active: true, is_quick: false, sort_order: 0 }}
      columns={[
        { label: t('settings.charges.name'), render: (r) => <span className="font-medium">{String(r.name)}</span> },
        { label: t('settings.charges.price'), render: (r) => (r.default_price ? money(String(r.default_price), String(r.currency)) : t('settings.charges.askEachTime')) },
        { label: t('settings.charges.quick'), render: (r) => yesNo(r.is_quick, t) },
        { label: t('common.active'), render: (r) => yesNo(r.is_active, t) },
      ]}
      fields={[
        { name: 'name', label: t('settings.charges.name'), hint: t('settings.charges.nameHint') },
        { name: 'default_price', label: t('settings.charges.price'), type: 'decimal', nullable: true, hint: t('settings.charges.priceHint') },
        { name: 'currency', label: t('admin.newHotel.currency') },
        { name: 'is_quick', label: t('settings.charges.quick'), type: 'checkbox', hint: t('settings.charges.quickHint') },
        { name: 'sort_order', label: t('settings.charges.order'), type: 'number', hint: t('settings.charges.orderHint') },
        { name: 'is_active', label: t('common.active'), type: 'checkbox' },
      ]}
    />
  )
}

export function CancellationSettings() {
  const { t } = useTranslation()
  const penalty = [
    { value: 'nights', label: t('settings.cancellation.nights') },
    { value: 'percent', label: t('settings.cancellation.percent') },
    { value: 'fixed', label: t('settings.cancellation.fixed') },
  ]
  const describe = (r: Record<string, unknown>) => {
    const fee = r.penalty_type === 'nights' ? t('settings.cancellation.feeNights', { count: Number(r.penalty_value) }) : r.penalty_type === 'percent' ? `${Number(r.penalty_value)}%` : money(String(r.penalty_value))
    return r.free_until_hours === null ? t('settings.cancellation.neverFree', { fee }) : t('settings.cancellation.freeUntil', { hours: Number(r.free_until_hours), fee })
  }
  return (
    <ResourceList
      endpoint="/settings/cancellation-policies"
      title={t('settings.sections.cancellation')}
      description={t('settings.cancellation.help')}
      addLabel={t('settings.cancellation.add')}
      defaults={{ name: '', free_until_hours: 48, penalty_type: 'nights', penalty_value: '1', applies_to_no_show: true, is_default: false }}
      canDelete={(r) => !r.is_default}
      columns={[
        { label: t('settings.cancellation.name'), render: (r) => <span className="flex items-center gap-2 font-medium">{String(r.name)}{r.is_default ? <Badge tone="accent">{t('team.roles.default')}</Badge> : null}</span> },
        { label: t('settings.cancellation.rule'), render: (r) => describe(r) },
        { label: t('settings.cancellation.noShow'), render: (r) => yesNo(r.applies_to_no_show, t) },
      ]}
      fields={[
        { name: 'name', label: t('settings.cancellation.name') },
        { name: 'free_until_hours', label: t('settings.cancellation.freeHours'), type: 'number', nullable: true, hint: t('settings.cancellation.freeHoursHint') },
        { name: 'penalty_type', label: t('settings.cancellation.penaltyType'), type: 'select', options: penalty },
        { name: 'penalty_value', label: t('settings.cancellation.penaltyValue'), type: 'decimal' },
        { name: 'applies_to_no_show', label: t('settings.cancellation.appliesNoShow'), type: 'checkbox' },
        { name: 'is_default', label: t('settings.cancellation.isDefault'), type: 'checkbox', hint: t('settings.cancellation.isDefaultHint') },
      ]}
    />
  )
}

/* ------------------------------------------------------------- currencies */

type Currency = { id: number; code: string; symbol: string; is_default: boolean; current_rate: string | null; rate_date: string | null }

export function CurrenciesSettings() {
  const { t } = useTranslation()
  const list = useSettingsList<Currency>('/settings/currencies')
  const main = String(list.data?.main ?? 'ETB')
  return (
    <div className="flex flex-col gap-8">
      <ResourceList
        endpoint="/settings/currencies"
        title={t('settings.sections.currencies')}
        description={t('settings.currencies.help', { main })}
        addLabel={t('settings.currencies.add')}
        defaults={{ code: '', symbol: '' }}
        canDelete={(r) => !r.is_default}
        columns={[
          { label: t('settings.currencies.code'), render: (r) => <span className="flex items-center gap-2 font-medium">{String(r.code)}{r.is_default ? <Badge tone="accent">{t('settings.currencies.main')}</Badge> : null}</span> },
          { label: t('settings.currencies.symbol'), render: (r) => String(r.symbol) },
          { label: t('settings.currencies.rate'), render: (r) => (r.is_default ? '—' : r.current_rate ? `1 ${String(r.code)} = ${Number(r.current_rate)} ${main} (${date(String(r.rate_date))})` : <Badge tone="warn">{t('settings.currencies.noRate')}</Badge>) },
        ]}
        fields={[
          { name: 'code', label: t('settings.currencies.code'), hint: t('settings.currencies.codeHint') },
          { name: 'symbol', label: t('settings.currencies.symbol') },
        ]}
      />
      <RateForm currencies={(list.data?.data ?? []).filter((c) => !c.is_default)} main={main} />
    </div>
  )
}

function RateForm({ currencies, main }: { currencies: Currency[]; main: string }) {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const [code, setCode] = useState('')
  const [rate, setRate] = useState('')
  const [state, setState] = useState<{ saving: boolean; saved: boolean; error: ApiError | null }>({ saving: false, saved: false, error: null })
  const selected = code || currencies[0]?.code || ''
  if (!currencies.length) return null

  async function submit() {
    setState({ saving: true, saved: false, error: null })
    try {
      await post('/settings/exchange-rates', { from_currency: selected, rate })
      setRate('')
      setState({ saving: false, saved: true, error: null })
      void qc.invalidateQueries({ queryKey: ['settings'] })
    } catch (e) {
      setState({ saving: false, saved: false, error: e instanceof ApiError ? e : null })
    }
  }

  return (
    <SectionForm title={t('settings.currencies.rateTitle')} description={t('settings.currencies.rateHelp')} onSubmit={submit} saving={state.saving} saved={state.saved} error={state.error}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Select label={t('settings.currencies.code')} value={selected} onChange={(e) => setCode(e.target.value)}>
          {currencies.map((c) => (
            <option key={c.code} value={c.code}>
              {c.code}
            </option>
          ))}
        </Select>
        <Field label={t('settings.currencies.rateFor', { code: selected, main })} inputMode="decimal" value={rate} onChange={(e) => setRate(e.target.value)} error={state.error?.field('rate')} />
      </div>
    </SectionForm>
  )
}

/* ------------------------------------------------------------- booking rules */

type BookingRules = Record<string, string | number | boolean>

export function BookingRulesSettings() {
  const q = useSettingsObject<BookingRules>('/settings/booking-rules')
  return q.data ? <BookingRulesForm initial={q.data.data} /> : null
}

function BookingRulesForm({ initial }: { initial: BookingRules }) {
  const { t } = useTranslation()
  const save = useSettingsObjectSave<BookingRules>('/settings/booking-rules')
  const [f, setF] = useState(initial)
  const error = save.error instanceof ApiError ? save.error : null
  const set = (k: string, v: string | boolean) => setF({ ...f, [k]: v })
  const val = (k: string) => String(f[k] ?? '')

  return (
    <SectionForm title={t('settings.sections.booking-rules')} description={t('settings.rules.help')} onSubmit={() => save.mutate(f)} saving={save.isPending} saved={save.isSuccess} error={save.error}>
      <h3 className="text-sm font-semibold">{t('settings.rules.deposit')}</h3>
      <label className="flex min-h-touch items-center gap-3 text-sm">
        <input type="checkbox" checked={!!f.deposit_required} onChange={(e) => set('deposit_required', e.target.checked)} />
        {t('settings.rules.depositRequired')}
      </label>
      <div className="grid gap-4 sm:grid-cols-3">
        <Select label={t('settings.rules.depositType')} value={val('deposit_type')} onChange={(e) => set('deposit_type', e.target.value)}>
          <option value="percent">{t('settings.rules.percent')}</option>
          <option value="fixed">{t('settings.rules.fixed')}</option>
          <option value="first_night">{t('settings.rules.firstNight')}</option>
        </Select>
        {f.deposit_type !== 'first_night' && <Field label={t('settings.rules.amount')} inputMode="decimal" value={val('deposit_value')} onChange={(e) => set('deposit_value', e.target.value)} error={error?.field('deposit_value')} />}
        <Field label={t('settings.rules.pendingHours')} type="number" value={val('pending_expiry_hours')} hint={t('settings.rules.pendingHint')} onChange={(e) => set('pending_expiry_hours', e.target.value)} error={error?.field('pending_expiry_hours')} />
      </div>
      <Select label={t('settings.rules.walkIn')} value={val('walk_in_payment')} onChange={(e) => set('walk_in_payment', e.target.value)}>
        <option value="full">{t('settings.rules.walkInFull')}</option>
        <option value="deposit">{t('settings.rules.walkInDeposit')}</option>
      </Select>
      <label className="flex min-h-touch items-start gap-3 text-sm">
        <input type="checkbox" className="mt-0.5 size-4 rounded" checked={!!f.allow_pay_later} onChange={(e) => set('allow_pay_later', e.target.checked)} />
        <span className="flex flex-col">
          {t('settings.rules.payLater')}
          <span className="text-xs text-muted">{t('settings.rules.payLaterHint')}</span>
        </span>
      </label>

      <h3 className="pt-2 text-sm font-semibold">{t('settings.rules.idPhoto')}</h3>
      <label className="flex min-h-touch items-start gap-3 text-sm">
        <input type="checkbox" className="mt-0.5 size-4 rounded" checked={!!f.id_photo_enabled} onChange={(e) => set('id_photo_enabled', e.target.checked)} />
        <span className="flex flex-col">
          {t('settings.rules.idPhotoEnabled')}
          <span className="text-xs text-muted">{t('settings.rules.idPhotoHint')}</span>
        </span>
      </label>
      {!!f.id_photo_enabled && (
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="flex min-h-touch items-center gap-3 text-sm">
            <input type="checkbox" checked={!!f.id_photo_required} onChange={(e) => set('id_photo_required', e.target.checked)} />
            {t('settings.rules.idPhotoRequired')}
          </label>
          <Select label={t('settings.rules.idPhotoKeep')} value={val('id_photo_keep_months')} onChange={(e) => setF({ ...f, id_photo_keep_months: Number(e.target.value) })}>
            {[3, 6, 12, 24, 60, 0].map((m) => (
              <option key={m} value={m}>
                {m === 0 ? t('settings.rules.keepForever') : t('settings.rules.keepMonths', { count: m })}
              </option>
            ))}
          </Select>
        </div>
      )}

      <h3 className="pt-2 text-sm font-semibold">{t('settings.rules.fees')}</h3>
      <div className="grid gap-4 sm:grid-cols-2">
        {(['early_check_in', 'late_check_out', 'early_check_out'] as const).map((k) => (
          <div key={k} className="grid grid-cols-2 gap-3">
            <Select label={t(`settings.rules.${k}`)} value={val(`${k}_fee_type`)} onChange={(e) => set(`${k}_fee_type`, e.target.value)}>
              <option value="percent">{t('settings.rules.percentNight')}</option>
              <option value="fixed">{t('settings.rules.fixed')}</option>
            </Select>
            <Field label={t('settings.rules.amount')} inputMode="decimal" value={val(`${k}_fee_value`)} onChange={(e) => set(`${k}_fee_value`, e.target.value)} error={error?.field(`${k}_fee_value`)} />
          </div>
        ))}
      </div>

      <p className="-mt-2 text-xs text-muted">{t('settings.rules.earlyFeeHint')}</p>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label={t('settings.rules.nightCutoff')} type="time" value={val('night_cutoff') || '06:00'} hint={t('settings.rules.nightCutoffHint')} onChange={(e) => set('night_cutoff', e.target.value)} error={error?.field('night_cutoff')} />
      </div>

      <h3 className="pt-2 text-sm font-semibold">{t('settings.rules.hourly')}</h3>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label={t('settings.rules.minHours')} type="number" value={val('hourly_min_hours')} onChange={(e) => set('hourly_min_hours', e.target.value)} error={error?.field('hourly_min_hours')} />
        <Field label={t('settings.rules.cleaning')} type="number" value={val('hourly_cleaning_minutes')} hint={t('settings.rules.cleaningHint')} onChange={(e) => set('hourly_cleaning_minutes', e.target.value)} error={error?.field('hourly_cleaning_minutes')} />
      </div>
    </SectionForm>
  )
}

/* ------------------------------------------------------------- modules */

type ModuleRow = { module: string; available: boolean; enabled: boolean; plans: string[] }

export function ModulesSettings() {
  const { t } = useTranslation()
  const q = useSettingsObject<ModuleRow[]>('/settings/modules')
  const save = useSettingsObjectSave<{ modules: Record<string, boolean> }>('/settings/modules')
  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <h2 className="text-base font-semibold">{t('settings.sections.modules')}</h2>
        <p className="max-w-prose text-sm text-muted">{t('settings.modules.help')}</p>
      </div>
      <div className="grid gap-3 md:grid-cols-2">
        {q.data?.data.map((m) => (
          <Card key={m.module} className="flex items-start justify-between gap-4">
            <div className="flex flex-col gap-1">
              <span className="text-sm font-semibold">{t(`modules.${m.module}`)}</span>
              <span className="text-sm text-muted">{t(`settings.modules.about.${m.module}`)}</span>
              {!m.available && <span className="text-xs text-warn-text">{t('settings.modules.inPlans', { plans: m.plans.join(', ') || '—' })}</span>}
            </div>
            <label className="flex min-h-touch items-center">
              <span className="sr-only">{t(`modules.${m.module}`)}</span>
              <input
                type="checkbox"
                role="switch"
                className="peer sr-only"
                checked={m.enabled}
                disabled={!m.available || save.isPending}
                onChange={(e) => save.mutate({ modules: { [m.module]: e.target.checked } })}
              />
              {/* The template's switch: small track, brand colour when on. */}
              <span className="relative inline-flex h-[1.15rem] w-8 shrink-0 items-center rounded-full border border-transparent bg-input shadow-xs transition-all peer-checked:bg-primary peer-disabled:opacity-50 peer-focus-visible:ring-[3px] peer-focus-visible:ring-focus/50 after:absolute after:left-px after:size-4 after:rounded-full after:bg-background after:transition-transform peer-checked:after:translate-x-[calc(100%-2px)] dark:bg-input/80 dark:after:bg-text dark:peer-checked:after:bg-primary-foreground" />
            </label>
          </Card>
        ))}
      </div>
    </div>
  )
}

/* ------------------------------------------------------------- attendance */

export function AttendanceSettings() {
  const { t } = useTranslation()
  const q = useSettingsObject<{ attendance_methods: string[] }>('/settings/attendance')
  const save = useSettingsObjectSave<{ attendance_methods: string[] }>('/settings/attendance')
  const methods = q.data?.data.attendance_methods ?? []
  const toggle = (m: string, on: boolean) => save.mutate({ attendance_methods: on ? [...methods, m] : methods.filter((x) => x !== m) })
  const error = save.error instanceof ApiError ? Object.values(save.error.errors)[0]?.[0] : null
  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <h2 className="text-base font-semibold">{t('settings.sections.attendance')}</h2>
        <p className="max-w-prose text-sm text-muted">{t('settings.attendance.help')}</p>
      </div>
      <Card className="flex flex-col gap-2">
        {save.isSuccess && <Alert tone="ok">{t('common.saved')}</Alert>}
        {error && <Alert>{error}</Alert>}
        {['self', 'kiosk_pin', 'manual'].map((m) => (
          <label key={m} className="flex min-h-touch items-start gap-3 text-sm">
            <input type="checkbox" className="mt-0.5 size-4 rounded" checked={methods.includes(m)} disabled={save.isPending} onChange={(e) => toggle(m, e.target.checked)} />
            <span className="flex flex-col">
              <span className="font-medium">{t(`settings.attendance.${m}`)}</span>
              <span className="text-muted">{t(`settings.attendance.${m}Hint`)}</span>
            </span>
          </label>
        ))}
      </Card>
    </div>
  )
}

/* ------------------------------------------------------------- security */

type Security = { require_two_factor_staff: boolean; approved_devices_only: boolean; screen_lock_minutes: number }

export function SecuritySettings() {
  const { t } = useTranslation()
  const me = useMe()
  const q = useSettingsObject<Security>('/settings/security')
  const save = useSettingsObjectSave<Security>('/settings/security')
  const v = q.data?.data
  const error = save.error instanceof ApiError ? Object.values(save.error.errors)[0]?.[0] : null
  if (!v) return null
  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <h2 className="text-base font-semibold">{t('settings.sections.security')}</h2>
        <p className="max-w-prose text-sm text-muted">{t('settings.security.help')}</p>
      </div>
      <Card className="flex flex-col gap-3">
        {save.isSuccess && <Alert tone="ok">{t('common.saved')}</Alert>}
        {error && <Alert>{error}</Alert>}
        {(['require_two_factor_staff', 'approved_devices_only'] as const).map((k) => (
          <label key={k} className="flex min-h-touch items-start gap-3 text-sm">
            <input type="checkbox" className="mt-0.5 size-4 rounded" checked={v[k]} disabled={save.isPending} onChange={(e) => save.mutate({ ...v, [k]: e.target.checked })} />
            <span className="flex flex-col">
              <span className="font-medium">{t(`settings.security.${k}`)}</span>
              <span className="text-muted">{t(`settings.security.${k}Hint`)}</span>
            </span>
          </label>
        ))}
        <div className="w-60">
          <Select label={t('settings.security.screenLock')} hint={t('settings.security.screenLockHint')} value={String(v.screen_lock_minutes)} disabled={save.isPending} onChange={(e) => save.mutate({ ...v, screen_lock_minutes: Number(e.target.value) })}>
            {[0, 5, 10, 15, 30, 60].map((m) => (
              <option key={m} value={m}>
                {m === 0 ? t('settings.security.never') : t('settings.security.minutes', { count: m })}
              </option>
            ))}
          </Select>
        </div>
      </Card>
      {me.data?.role?.is_hotel_admin && (
        <Card className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex flex-col gap-1">
            <h3 className="font-semibold">{t('settings.security.exportTitle')}</h3>
            <p className="max-w-prose text-sm text-muted">{t('settings.security.exportHelp')}</p>
          </div>
          <a className={buttonClass('secondary')} href={apiUrl('/settings/export')} download>
            {t('settings.security.export')}
          </a>
        </Card>
      )}
    </div>
  )
}

/* ------------------------------------------------------------- subscription */

type Subscription = {
  status: string
  plan: { name: string; max_rooms: number | null; max_branches: number | null; max_staff: number | null } | null
  trial_plan: { name: string } | null
  billing_cycle: string
  trial_ends_at: string | null
  subscription_ends_at: string | null
  usage: { branches: number; staff: number; rooms: number; storage_mb?: number }
  limits?: { rooms: number | null; branches: number | null; staff: number | null; storage_mb: number | null }
  contact?: { whatsapp: string | null; email: string | null }
  invoices?: HotelInvoice[]
}

/** No online billing: upgrades go through WhatsApp or email (set in Super Admin → Settings). */
function UpgradeCard({ contact }: { contact?: { whatsapp: string | null; email: string | null } }) {
  const { t } = useTranslation()
  const wa = contact?.whatsapp?.replace(/[^0-9]/g, '') ?? ''
  if (!wa && !contact?.email) return null
  return (
    <Card className="flex flex-wrap items-center justify-between gap-3">
      <div className="flex flex-col gap-1">
        <h3 className="font-semibold">{t('settings.subscription.upgradeTitle')}</h3>
        <p className="max-w-prose text-sm text-muted">{t('settings.subscription.upgradeHelp')}</p>
      </div>
      <div className="flex flex-wrap gap-2">
        {wa && (
          <a className={buttonClass('primary')} href={`https://wa.me/${wa}`} target="_blank" rel="noopener noreferrer">
            {t('settings.subscription.whatsapp')}
          </a>
        )}
        {contact?.email && (
          <a className={buttonClass('secondary')} href={`mailto:${contact.email}`}>
            {t('settings.subscription.email')}
          </a>
        )}
      </div>
    </Card>
  )
}

export function SubscriptionSettings() {
  const { t } = useTranslation()
  const q = useSettingsObject<Subscription>('/subscription')
  const s = q.data?.data
  if (!s) return null
  const meter = (label: string, used: number, max: number | null) => (
    <div className="flex flex-col gap-1.5">
      <div className="flex justify-between text-sm">
        <span className="font-medium">{label}</span>
        <span className="text-muted">{max === null ? t('settings.subscription.unlimited', { used }) : `${used} / ${max}`}</span>
      </div>
      <div className="h-1.5 overflow-hidden rounded-full bg-sunken">
        <div className="h-full rounded-full bg-accent" style={{ width: `${max ? Math.min(100, (used / max) * 100) : 8}%` }} />
      </div>
    </div>
  )
  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <h2 className="text-base font-semibold">{t('settings.sections.subscription')}</h2>
        <p className="text-sm text-muted">{t('settings.subscription.help')}</p>
      </div>
      <Card className="grid gap-6 md:grid-cols-2">
        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
          <dt className="text-muted">{t('admin.hotels.plan')}</dt>
          <dd className="font-medium">{s.plan?.name ?? '—'}</dd>
          <dt className="text-muted">{t('admin.hotels.status')}</dt>
          <dd>{t(`hotelStatus.${s.status}`)}</dd>
          <dt className="text-muted">{t('admin.hotel.trialEnds')}</dt>
          <dd>{date(s.trial_ends_at)}{s.trial_plan && <span className="text-muted"> ({s.trial_plan.name})</span>}</dd>
          <dt className="text-muted">{t('admin.hotels.paidUntil')}</dt>
          <dd>{date(s.subscription_ends_at)}</dd>
        </dl>
        <div className="flex flex-col gap-4">
          {meter(t('settings.subscription.rooms'), s.usage.rooms, s.limits ? s.limits.rooms : (s.plan?.max_rooms ?? null))}
          {meter(t('admin.hotels.branches'), s.usage.branches, s.limits ? s.limits.branches : (s.plan?.max_branches ?? null))}
          {meter(t('nav.staff'), s.usage.staff, s.limits ? s.limits.staff : (s.plan?.max_staff ?? null))}
          {s.usage.storage_mb !== undefined && meter(t('settings.subscription.storage'), s.usage.storage_mb, s.limits?.storage_mb ?? null)}
        </div>
      </Card>
      <UpgradeCard contact={s.contact} />
      <MyInvoices rows={s.invoices ?? []} />
    </div>
  )
}

/* ------------------------------------------------------------- booking page */

type BookingPageSettings = { enabled: boolean; slug: string | null; url: string | null; note: string | null; powered_by: boolean }

/** The hotel's public booking page: guests send requests, reception books them (owner, 14 Nov 2026). */
export function BookingPageSettings() {
  const { t } = useTranslation()
  const q = useSettingsObject<BookingPageSettings>('/settings/booking-page')
  const save = useSettingsObjectSave<BookingPageSettings>('/settings/booking-page')
  const v = q.data?.data
  const [slug, setSlug] = useState<string | null>(null)
  const [note, setNote] = useState<string | null>(null)
  if (!v) return null
  const error = save.error instanceof ApiError ? save.error : null
  const send = (enabled: boolean) => save.mutate({ enabled, slug: slug ?? v.slug ?? '', note: note ?? v.note ?? '' })
  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <h2 className="text-base font-semibold">{t('settings.sections.booking-page')}</h2>
        <p className="max-w-prose text-sm text-muted">{t('settings.bookingPage.help')}</p>
      </div>
      <Card className="flex flex-col gap-4">
        {save.isSuccess && <Alert tone="ok">{t('common.saved')}</Alert>}
        {error && !error.field('slug') && <Alert>{error.message}</Alert>}
        <label className="flex min-h-touch items-start gap-3 text-sm">
          <input type="checkbox" className="mt-0.5 size-4 rounded" checked={v.enabled} disabled={save.isPending} onChange={(e) => send(e.target.checked)} />
          <span className="flex flex-col">
            <span className="font-medium">{t('settings.bookingPage.enabled')}</span>
            <span className="text-muted">{t('settings.bookingPage.enabledHint')}</span>
          </span>
        </label>
        {v.url && v.enabled && (
          <p className="text-sm">
            {t('settings.bookingPage.link')}{' '}
            <a className="font-medium break-all text-accent underline" href={v.url} target="_blank" rel="noopener noreferrer">{v.url}</a>
          </p>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('settings.bookingPage.slug')} value={slug ?? v.slug ?? ''} hint={t('settings.bookingPage.slugHint')} maxLength={50} onChange={(e) => setSlug(e.target.value)} error={error?.field('slug')} />
          <Field label={t('settings.bookingPage.note')} value={note ?? v.note ?? ''} hint={t('settings.bookingPage.noteHint')} maxLength={300} onChange={(e) => setNote(e.target.value)} />
        </div>
        {v.powered_by && <p className="text-sm text-muted">{t('settings.bookingPage.poweredBy')}</p>}
        <div>
          <Button disabled={save.isPending} onClick={() => send(v.enabled)}>{t('common.save')}</Button>
        </div>
      </Card>
    </div>
  )
}
