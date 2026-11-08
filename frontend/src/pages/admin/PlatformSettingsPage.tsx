import { useRef, useState, type FormEvent, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { ImageIcon } from 'lucide-react'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Select } from '@/components/ui/Select'
import { TabPanel, Tabs } from '@/components/ui/Tabs'
import { usePlans, usePlatformSettings, useSaveSettings, useTestEmail, useUploadLogo, type PlatformSettings } from '@/lib/admin'
import { useMe } from '@/lib/auth'
import { ApiError } from '@/lib/api'
import { onColor } from '@/lib/brand'
import { inputClass } from '@/components/ui/formStyles'
import { cn } from '@/lib/cn'

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
            <div className="flex flex-col gap-6">
              <EmailsTab initial={settings.data.data} />
              <MailServerTab initial={settings.data.data} />
            </div>
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
          <p className="text-sm text-muted">{description}</p>
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

/** Branding, e-mails, Telegram and two-step sign-in for admins: the Owner only (the server checks this too). */
function useIsOwner() {
  return useMe().data?.platform?.role === 'owner'
}

function OwnerOnly({ title }: { title: string }) {
  const { t } = useTranslation()
  return (
    <Card className="flex flex-col gap-1">
      <h2 className="text-sm font-semibold">{title}</h2>
      <p className="text-sm text-muted">{t('admin.settings.ownerOnly')}</p>
    </Card>
  )
}

function GeneralTab({ initial }: { initial: PlatformSettings }) {
  const { t } = useTranslation()
  const [name, setName] = useState(initial.platform_name)
  const [email, setEmail] = useState(initial.support_email ?? '')
  const [phone, setPhone] = useState(initial.support_phone ?? '')
  const [demo, setDemo] = useState(initial.demo_public)
  const [demoMinutes, setDemoMinutes] = useState(String(initial.demo_minutes ?? 30))
  const [twoStep, setTwoStep] = useState(initial.two_factor_admins ?? true)
  const owner = useIsOwner()
  return (
    <SettingsForm
      title={t('admin.settings.tabs.general')}
      description={t('admin.settings.generalHelp')}
      onSave={() => ({ support_email: email || null, support_phone: phone || null, demo_public: demo, demo_minutes: Number(demoMinutes) || 30, ...(owner ? { platform_name: name, two_factor_admins: twoStep } : {}) })}
    >
      {(error) => (
        <>
          <Field label={t('admin.settings.platformName')} value={name} disabled={!owner} onChange={(e) => setName(e.target.value)} error={error?.field('platform_name')} />
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('admin.settings.supportEmail')} type="email" value={email} onChange={(e) => setEmail(e.target.value)} error={error?.field('support_email')} />
            <Field label={t('admin.settings.supportPhone')} type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} error={error?.field('support_phone')} />
          </div>
          <label className="flex min-h-touch items-start gap-3 text-sm">
            <input type="checkbox" className="mt-0.5 size-4 rounded" checked={demo} onChange={(e) => setDemo(e.target.checked)} />
            <span className="flex flex-col">
              <span className="font-medium">{t('admin.settings.demoPublic')}</span>
              <span className="text-muted">{t('admin.settings.demoPublicHint')}</span>
            </span>
          </label>
          {demo && (
            <div className="max-w-xs">
              <Field label={t('admin.settings.demoMinutes')} type="number" min={5} max={480} value={demoMinutes} onChange={(e) => setDemoMinutes(e.target.value)} hint={t('admin.settings.demoMinutesHint')} error={error?.field('demo_minutes')} />
            </div>
          )}
          <label className="flex min-h-touch items-start gap-3 text-sm">
            <input type="checkbox" className="mt-0.5 size-4 rounded" checked={twoStep} disabled={!owner} onChange={(e) => setTwoStep(e.target.checked)} />
            <span className="flex flex-col">
              <span className="font-medium">{t('admin.settings.twoFactorAdmins')}</span>
              <span className="text-muted">{t('admin.settings.twoFactorAdminsHint')}</span>
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
  const owner = useIsOwner()
  if (!owner) return <OwnerOnly title={t('admin.settings.brandTitle')} />

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
              <legend className="mb-1 text-sm font-medium text-text">{t('admin.settings.brandColor')}</legend>
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
                    className={cn(inputClass, 'w-28 font-mono')}
                  />
                </label>
              </div>
              {error?.field('brand_color') && <p className="text-sm text-danger-text">{error.field('brand_color')}</p>}
            </fieldset>
            <Field label={t('admin.settings.loginMessage')} value={message} placeholder={t('login.subtitle')} hint={t('admin.settings.loginMessageHint')} onChange={(e) => setMessage(e.target.value)} error={error?.field('login_message')} />

            {/* Live preview of how the brand looks */}
            <div className="flex flex-col gap-2">
              <span className="text-sm font-medium">{t('admin.settings.preview')}</span>
              <div className="flex flex-wrap items-center gap-3 rounded-lg bg-sunken p-4">
                <span className="flex size-8 items-center justify-center rounded-lg text-sm font-bold" style={{ background: validColor ? color : undefined, color: validColor ? onColor(color) : undefined }}>
                  {(name || 'H').slice(0, 1).toUpperCase()}
                </span>
                <span className="font-semibold">{name}</span>
                <span className="ml-auto rounded-lg px-3 py-1.5 text-sm font-semibold" style={{ background: validColor ? color : undefined, color: validColor ? onColor(color) : undefined }}>
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
        <p className="text-sm text-muted">{t('admin.settings.logoHelp')}</p>
      </div>
      <div className="flex flex-wrap items-center gap-4 lg:col-span-2">
        <span className="flex size-16 items-center justify-center overflow-hidden rounded-xl bg-sunken border">
          {current ? <img src={current} alt="" className="size-full object-contain p-1.5" /> : <ImageIcon aria-hidden className="size-6 text-icon" />}
        </span>
        <input
          ref={input}
          type="file"
          accept="image/png,image/jpeg,image/webp"
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
  const owner = useIsOwner()
  if (!owner) return <OwnerOnly title={t('admin.settings.tabs.emails')} />
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
  const owner = useIsOwner()
  if (!owner) return <OwnerOnly title={t('admin.settings.tabs.telegram')} />
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

/** The mail server (SMTP). Owner only. Empty = the MAIL_* lines of the server's .env file are used. */
function MailServerTab({ initial }: { initial: PlatformSettings }) {
  const { t } = useTranslation()
  const me = useMe()
  const owner = me.data?.platform?.role === 'owner'
  const [host, setHost] = useState(initial.mail_host ?? '')
  const [port, setPort] = useState(String(initial.mail_port ?? 587))
  const [encryption, setEncryption] = useState<'tls' | 'ssl' | 'none'>(initial.mail_encryption ?? 'tls')
  const [username, setUsername] = useState(initial.mail_username ?? '')
  const [password, setPassword] = useState(initial.mail_password ?? '')
  const [from, setFrom] = useState(initial.mail_from_address ?? '')
  const [testTo, setTestTo] = useState('')
  const test = useTestEmail()
  const testError = test.error instanceof ApiError ? (test.error.field('mail_host') ?? test.error.message) : null

  if (!owner) {
    return (
      <Card className="flex flex-col gap-1">
        <h2 className="text-sm font-semibold">{t('admin.settings.mailServer')}</h2>
        <p className="text-sm text-muted">{t('admin.settings.mailOwnerOnly')}</p>
      </Card>
    )
  }
  return (
    <SettingsForm
      title={t('admin.settings.mailServer')}
      description={t('admin.settings.mailServerHelp')}
      onSave={() => ({ mail_host: host || null, mail_port: Number(port) || 587, mail_encryption: encryption, mail_username: username || null, mail_password: password || null, mail_from_address: from || null })}
    >
      {(error) => (
        <>
          <div className="grid gap-4 sm:grid-cols-[1fr_7rem_9rem]">
            <Field label={t('admin.settings.mailHost')} value={host} placeholder="mail.yourdomain.com" autoComplete="off" onChange={(e) => setHost(e.target.value)} error={error?.field('mail_host')} />
            <Field label={t('admin.settings.mailPort')} inputMode="numeric" value={port} onChange={(e) => setPort(e.target.value.replace(/\D/g, ''))} error={error?.field('mail_port')} />
            <Select label={t('admin.settings.mailEncryption')} value={encryption} onChange={(e) => setEncryption(e.target.value as 'tls' | 'ssl' | 'none')}>
              <option value="tls">TLS (587)</option>
              <option value="ssl">SSL (465)</option>
              <option value="none">{t('admin.settings.mailNone')}</option>
            </Select>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('admin.settings.mailUsername')} value={username} autoComplete="off" onChange={(e) => setUsername(e.target.value)} error={error?.field('mail_username')} />
            <Field label={t('admin.settings.mailPassword')} type="password" value={password} autoComplete="new-password" hint={t('admin.settings.mailPasswordHint')} onChange={(e) => setPassword(e.target.value)} error={error?.field('mail_password')} />
          </div>
          <Field label={t('admin.settings.mailFrom')} type="email" value={from} placeholder="no-reply@yourdomain.com" hint={t('admin.settings.mailFromHint')} onChange={(e) => setFrom(e.target.value)} error={error?.field('mail_from_address')} />
          <div className="flex flex-wrap items-end gap-3">
            <div className="min-w-56 flex-1">
              <Field label={t('admin.settings.mailTestTo')} type="email" value={testTo} placeholder={me.data?.user.email} onChange={(e) => setTestTo(e.target.value)} />
            </div>
            <Button type="button" variant="secondary" disabled={test.isPending} onClick={() => test.mutate(testTo.trim())}>
              {test.isPending ? t('admin.settings.mailSending') : t('admin.settings.mailTest')}
            </Button>
          </div>
          <span className="text-xs text-muted">{t('admin.settings.mailTestHint')}</span>
          {test.isSuccess && <Alert tone="ok">{test.data.message}</Alert>}
          {testError && <Alert>{testError}</Alert>}
        </>
      )}
    </SettingsForm>
  )
}
