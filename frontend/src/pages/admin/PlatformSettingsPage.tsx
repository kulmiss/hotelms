import { useRef, useState, type FormEvent, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { PhotoIcon } from '@heroicons/react/24/outline'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Select } from '@/components/ui/Select'
import { TabPanel, Tabs } from '@/components/ui/Tabs'
import { usePlans, usePlatformSettings, useSaveSettings, useUploadLogo, type PlatformSettings } from '@/lib/admin'
import { ApiError } from '@/lib/api'
import { onColor } from '@/lib/brand'

const TABS = ['general', 'billing', 'branding', 'emails', 'telegram'] as const
type Tab = (typeof TABS)[number]

export function PlatformSettingsPage() {
  const { t } = useTranslation()
  const settings = usePlatformSettings()
  const [tab, setTab] = useState<Tab>('general')

  return (
    <div className="flex flex-col gap-6">
      <PageHeader title={t('nav.platformSettings')} subtitle={t('admin.settings.subtitle')} />
      <Tabs
        label={t('nav.platformSettings')}
        active={tab}
        onChange={(k) => setTab(k as Tab)}
        items={TABS.map((k) => ({ key: k, label: t(`admin.settings.tabs.${k}`) }))}
      />
      {settings.data && (
        <>
          <TabPanel id="general" active={tab}>
            <GeneralTab initial={settings.data.data} />
          </TabPanel>
          <TabPanel id="billing" active={tab}>
            <BillingTab initial={settings.data.data} />
          </TabPanel>
          <TabPanel id="branding" active={tab}>
            <BrandingTab initial={settings.data.data} />
          </TabPanel>
          <TabPanel id="emails" active={tab}>
            <EmailsTab initial={settings.data.data} />
          </TabPanel>
          <TabPanel id="telegram" active={tab}>
            <TelegramTab initial={settings.data.data} />
          </TabPanel>
        </>
      )}
    </div>
  )
}

/** One settings form: fields, a save button and a "Saved" note. */
function SettingsForm({
  title,
  description,
  children,
  onSave,
}: {
  title: string
  description: string
  children: (error: ApiError | null) => ReactNode
  onSave: () => Partial<PlatformSettings>
}) {
  const { t } = useTranslation()
  const save = useSaveSettings()
  const error = save.error instanceof ApiError ? save.error : null

  function submit(e: FormEvent) {
    e.preventDefault()
    save.mutate(onSave())
  }

  return (
    <form onSubmit={submit} noValidate>
      <Card className="grid gap-6 lg:grid-cols-3">
        <div className="flex flex-col gap-1">
          <h2 className="text-sm font-semibold">{title}</h2>
          <p className="text-[13px] text-muted">{description}</p>
        </div>
        <div className="flex flex-col gap-4 lg:col-span-2">
          {save.isSuccess && <Alert tone="ok">{t('common.saved')}</Alert>}
          {save.error && !error && <Alert>{t('common.serverUnreachable')}</Alert>}
          {children(error)}
          <div className="flex justify-end">
            <Button type="submit" disabled={save.isPending}>
              {t('common.save')}
            </Button>
          </div>
        </div>
      </Card>
    </form>
  )
}

function GeneralTab({ initial }: { initial: PlatformSettings }) {
  const { t } = useTranslation()
  const [name, setName] = useState(initial.platform_name)
  const [email, setEmail] = useState(initial.support_email ?? '')
  const [phone, setPhone] = useState(initial.support_phone ?? '')
  const [demo, setDemo] = useState(initial.demo_public)
  return (
    <SettingsForm
      title={t('admin.settings.tabs.general')}
      description={t('admin.settings.generalHelp')}
      onSave={() => ({ platform_name: name, support_email: email || null, support_phone: phone || null, demo_public: demo })}
    >
      {(error) => (
        <>
          <Field label={t('admin.settings.platformName')} value={name} onChange={(e) => setName(e.target.value)} error={error?.field('platform_name')} />
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('admin.settings.supportEmail')} type="email" value={email} onChange={(e) => setEmail(e.target.value)} error={error?.field('support_email')} />
            <Field label={t('admin.settings.supportPhone')} type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} error={error?.field('support_phone')} />
          </div>
          <label className="flex min-h-touch items-start gap-3 text-[13px]">
            <input type="checkbox" className="mt-0.5 size-4 rounded accent-accent" checked={demo} onChange={(e) => setDemo(e.target.checked)} />
            <span className="flex flex-col">
              <span className="font-medium">{t('admin.settings.demoPublic')}</span>
              <span className="text-muted">{t('admin.settings.demoPublicHint')}</span>
            </span>
          </label>
        </>
      )}
    </SettingsForm>
  )
}

function BillingTab({ initial }: { initial: PlatformSettings }) {
  const { t } = useTranslation()
  const plans = usePlans()
  const [days, setDays] = useState(String(initial.default_trial_days))
  const [plan, setPlan] = useState(initial.default_trial_plan_id ? String(initial.default_trial_plan_id) : '')
  const [grace, setGrace] = useState(String(initial.grace_days))
  return (
    <SettingsForm
      title={t('admin.settings.trials')}
      description={t('admin.settings.trialsHelp')}
      onSave={() => ({ default_trial_days: Number(days), default_trial_plan_id: plan ? Number(plan) : null, grace_days: Number(grace) })}
    >
      {(error) => (
        <>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('admin.settings.trialDays')} type="number" inputMode="numeric" min={0} max={365} value={days} hint={t('admin.settings.trialDaysHint')} onChange={(e) => setDays(e.target.value)} error={error?.field('default_trial_days')} />
            <Field label={t('admin.settings.graceDays')} type="number" inputMode="numeric" min={0} max={30} value={grace} hint={t('admin.settings.graceHint')} onChange={(e) => setGrace(e.target.value)} error={error?.field('grace_days')} />
          </div>
          <Select label={t('admin.settings.trialPlan')} value={plan} hint={t('admin.settings.trialPlanHint')} onChange={(e) => setPlan(e.target.value)}>
            <option value="">{t('admin.settings.samePlan')}</option>
            {plans.data?.data.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
          </Select>
        </>
      )}
    </SettingsForm>
  )
}

const SWATCHES = ['#0f766e', '#2563eb', '#4f46e5', '#7c3aed', '#db2777', '#dc2626', '#ea580c', '#ca8a04', '#16a34a', '#18181b']

function BrandingTab({ initial }: { initial: PlatformSettings }) {
  const { t } = useTranslation()
  const [name, setName] = useState(initial.platform_name)
  const [color, setColor] = useState(initial.brand_color)
  const [message, setMessage] = useState(initial.login_message ?? '')
  const validColor = /^#[0-9a-fA-F]{6}$/.test(color)

  return (
    <div className="flex flex-col gap-6">
      <LogoCard current={initial.brand_logo_url} />
      <SettingsForm
        title={t('admin.settings.brandTitle')}
        description={t('admin.settings.brandHelp')}
        onSave={() => ({ platform_name: name, brand_color: color.toLowerCase(), login_message: message || null })}
      >
        {(error) => (
          <>
            <Field label={t('admin.settings.platformName')} value={name} onChange={(e) => setName(e.target.value)} error={error?.field('platform_name')} />
            <fieldset className="flex flex-col gap-2">
              <legend className="mb-1 text-[13px] font-medium text-text">{t('admin.settings.brandColor')}</legend>
              <div className="flex flex-wrap items-center gap-2">
                {SWATCHES.map((c) => (
                  <button
                    key={c}
                    type="button"
                    aria-label={c}
                    aria-pressed={color.toLowerCase() === c}
                    onClick={() => setColor(c)}
                    className="flex size-touch items-center justify-center rounded-full"
                  >
                    <span
                      className="size-7 rounded-full ring-2 ring-offset-2 ring-offset-surface transition"
                      style={{ background: c, ['--tw-ring-color' as string]: color.toLowerCase() === c ? c : 'transparent' }}
                    />
                  </button>
                ))}
                <label className="ml-2 flex items-center gap-2">
                  <span className="sr-only">{t('admin.settings.customColor')}</span>
                  <input type="color" value={validColor ? color : '#0f766e'} onChange={(e) => setColor(e.target.value)} className="size-9 cursor-pointer rounded-lg border-0 bg-transparent p-0" />
                  <input
                    value={color}
                    onChange={(e) => setColor(e.target.value)}
                    aria-label={t('admin.settings.hex')}
                    className="min-h-touch w-28 rounded-lg bg-surface px-3 font-mono text-[13px] shadow-sm ring-1 ring-ring-strong focus:ring-2 focus:ring-accent focus:outline-none"
                  />
                </label>
              </div>
              {error?.field('brand_color') && <p className="text-sm text-danger-text">{error.field('brand_color')}</p>}
            </fieldset>
            <Field label={t('admin.settings.loginMessage')} value={message} placeholder={t('login.subtitle')} hint={t('admin.settings.loginMessageHint')} onChange={(e) => setMessage(e.target.value)} error={error?.field('login_message')} />

            {/* Live preview of how the brand looks */}
            <div className="flex flex-col gap-2">
              <span className="text-[13px] font-medium">{t('admin.settings.preview')}</span>
              <div className="flex flex-wrap items-center gap-3 rounded-lg bg-sunken p-4">
                <span className="flex size-8 items-center justify-center rounded-lg text-sm font-bold" style={{ background: validColor ? color : undefined, color: validColor ? onColor(color) : undefined }}>
                  {(name || 'H').slice(0, 1).toUpperCase()}
                </span>
                <span className="font-semibold">{name}</span>
                <span className="ml-auto rounded-lg px-3 py-1.5 text-[13px] font-semibold" style={{ background: validColor ? color : undefined, color: validColor ? onColor(color) : undefined }}>
                  {t('login.submit')}
                </span>
              </div>
            </div>
          </>
        )}
      </SettingsForm>
    </div>
  )
}

function LogoCard({ current }: { current: string | null }) {
  const { t } = useTranslation()
  const uploadLogo = useUploadLogo()
  const input = useRef<HTMLInputElement>(null)
  const error = uploadLogo.error instanceof ApiError ? uploadLogo.error : null

  return (
    <Card className="grid gap-6 lg:grid-cols-3">
      <div className="flex flex-col gap-1">
        <h2 className="text-sm font-semibold">{t('admin.settings.logo')}</h2>
        <p className="text-[13px] text-muted">{t('admin.settings.logoHelp')}</p>
      </div>
      <div className="flex flex-wrap items-center gap-4 lg:col-span-2">
        <span className="flex size-16 items-center justify-center overflow-hidden rounded-xl bg-sunken ring-1 ring-ring">
          {current ? <img src={current} alt="" className="size-full object-contain p-1.5" /> : <PhotoIcon aria-hidden className="size-6 text-icon" />}
        </span>
        <input
          ref={input}
          type="file"
          accept="image/png,image/jpeg,image/webp,image/svg+xml"
          className="sr-only"
          aria-label={t('admin.settings.uploadLogo')}
          onChange={(e) => e.target.files?.[0] && uploadLogo.mutate(e.target.files[0])}
        />
        <Button variant="secondary" disabled={uploadLogo.isPending} onClick={() => input.current?.click()}>
          {current ? t('admin.settings.replaceLogo') : t('admin.settings.uploadLogo')}
        </Button>
        {current && (
          <Button variant="ghost" disabled={uploadLogo.isPending} onClick={() => uploadLogo.mutate(null)}>
            {t('admin.settings.removeLogo')}
          </Button>
        )}
        {error && <p className="w-full text-sm text-danger-text">{error.field('logo') ?? error.message}</p>}
      </div>
    </Card>
  )
}

function EmailsTab({ initial }: { initial: PlatformSettings }) {
  const { t } = useTranslation()
  const [from, setFrom] = useState(initial.email_from_name)
  const [footer, setFooter] = useState(initial.email_footer ?? '')
  return (
    <SettingsForm
      title={t('admin.settings.tabs.emails')}
      description={t('admin.settings.emailsHelp')}
      onSave={() => ({ email_from_name: from, email_footer: footer || null })}
    >
      {(error) => (
        <>
          <Field label={t('admin.settings.fromName')} value={from} hint={t('admin.settings.fromNameHint')} onChange={(e) => setFrom(e.target.value)} error={error?.field('email_from_name')} />
          <Field label={t('admin.settings.footer')} value={footer} hint={t('admin.settings.footerHint')} onChange={(e) => setFooter(e.target.value)} error={error?.field('email_footer')} />
        </>
      )}
    </SettingsForm>
  )
}

function TelegramTab({ initial }: { initial: PlatformSettings }) {
  const { t } = useTranslation()
  const [token, setToken] = useState(initial.telegram_bot_token ?? '')
  const [username, setUsername] = useState(initial.telegram_bot_username ?? '')
  return (
    <SettingsForm
      title={t('admin.settings.tabs.telegram')}
      description={t('admin.settings.telegramHelp')}
      onSave={() => ({ telegram_bot_token: token || null, telegram_bot_username: username || null })}
    >
      {(error) => (
        <>
          <Field label={t('admin.settings.botToken')} value={token} autoComplete="off" hint={t('admin.settings.botTokenHint')} onChange={(e) => setToken(e.target.value)} error={error?.field('telegram_bot_token')} />
          <Field label={t('admin.settings.botUsername')} value={username} placeholder="MyHotelBot" onChange={(e) => setUsername(e.target.value)} error={error?.field('telegram_bot_username')} />
        </>
      )}
    </SettingsForm>
  )
}
