import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { ComputerDesktopIcon, DevicePhoneMobileIcon, EnvelopeIcon } from '@heroicons/react/24/outline'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { useMe } from '@/lib/auth'
import { cn } from '@/lib/cn'
import {
  errorText,
  lastSeen,
  STATUS_TONE,
  useBeginTwoFactor,
  useConfirmTwoFactor,
  useDisableTwoFactor,
  useMySecurity,
  useNewRecoveryCodes,
  useSetPin,
  useSignOutMyDevice,
  useSignOutOthers,
  type Device,
  type TwoFactorStart,
} from '@/lib/security'

/** Turn on two-step sign-in: pick app or email, confirm a code, then keep the recovery codes. */
export function TwoFactorSetup({ onDone }: { onDone: () => void }) {
  const { t } = useTranslation()
  const begin = useBeginTwoFactor()
  const confirm = useConfirmTwoFactor()
  const [method, setMethod] = useState<'app' | 'email'>('app')
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [start, setStart] = useState<TwoFactorStart | null>(null)
  const [codes, setCodes] = useState<string[] | null>(null)

  if (codes) return <RecoveryCodes codes={codes} onDone={onDone} />

  if (!start) {
    const submit = (e: FormEvent) => {
      e.preventDefault()
      begin.mutate({ method, password }, { onSuccess: (r) => setStart(r.data) })
    }
    return (
      <form onSubmit={submit} noValidate className="flex flex-col gap-4">
        <fieldset className="grid gap-2 sm:grid-cols-2">
          <legend className="mb-2 text-[13px] font-medium">{t('security.howCodes')}</legend>
          {(['app', 'email'] as const).map((m) => {
            const Icon = m === 'app' ? DevicePhoneMobileIcon : EnvelopeIcon
            return (
              <label key={m} className={cn('flex min-h-touch cursor-pointer items-start gap-3 rounded-lg p-3 ring-1 transition duration-75', method === m ? 'bg-accent-soft ring-accent' : 'ring-ring hover:bg-sunken')}>
                <input type="radio" name="method" className="sr-only" checked={method === m} onChange={() => setMethod(m)} />
                <Icon aria-hidden className="mt-0.5 size-5 shrink-0 text-icon" />
                <span className="flex flex-col">
                  <span className="text-[13px] font-semibold">{t(`security.methods.${m}`)}</span>
                  <span className="text-xs text-muted">{t(`security.methods.${m}Hint`)}</span>
                </span>
              </label>
            )
          })}
        </fieldset>
        <Field label={t('security.yourPassword')} type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
        {begin.error && <Alert>{errorText(begin.error)}</Alert>}
        <div className="flex justify-end">
          <Button type="submit" disabled={!password || begin.isPending}>
            {t('common.next')}
          </Button>
        </div>
      </form>
    )
  }

  const submit = (e: FormEvent) => {
    e.preventDefault()
    confirm.mutate(code, { onSuccess: (r) => setCodes(r.recovery_codes) })
  }
  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      {start.method === 'app' ? (
        <ol className="flex list-decimal flex-col gap-3 pl-5 text-[13px]">
          <li>{t('security.app.step1')}</li>
          <li className="flex flex-col gap-2">
            <span>{t('security.app.step2')}</span>
            <code className="w-fit rounded-lg bg-sunken px-3 py-2 font-mono text-sm tracking-wider break-all select-all">{start.secret.match(/.{1,4}/g)?.join(' ')}</code>
            <a href={start.uri} className="w-fit text-accent-text underline">
              {t('security.app.openOnPhone')}
            </a>
          </li>
          <li>{t('security.app.step3')}</li>
        </ol>
      ) : (
        <p className="text-[13px]">{t('security.emailSent', { email: start.email })}</p>
      )}
      <Field label={t('security.code')} inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} />
      {confirm.error && <Alert>{errorText(confirm.error)}</Alert>}
      <div className="flex justify-end gap-2">
        <Button variant="ghost" onClick={() => setStart(null)}>
          {t('common.back')}
        </Button>
        <Button type="submit" disabled={code.length !== 6 || confirm.isPending}>
          {t('security.turnOn')}
        </Button>
      </div>
    </form>
  )
}

export function RecoveryCodes({ codes, onDone }: { codes: string[]; onDone: () => void }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-col gap-4">
      <Alert tone="info">{t('security.recoveryHelp')}</Alert>
      <ul className="grid grid-cols-2 gap-2 rounded-lg bg-sunken p-3 font-mono text-sm">
        {codes.map((c) => (
          <li key={c}>{c}</li>
        ))}
      </ul>
      <div className="flex justify-end gap-2 print:hidden">
        <Button variant="secondary" onClick={() => void navigator.clipboard?.writeText(codes.join('\n'))}>
          {t('security.copy')}
        </Button>
        <Button onClick={onDone}>{t('security.savedThem')}</Button>
      </div>
    </div>
  )
}

/** Profile → two-step sign-in, PIN and your devices. */
export function SecurityCards() {
  const { t } = useTranslation()
  const me = useMe()
  const q = useMySecurity()
  const s = q.data?.data
  if (!s) return null
  return (
    <>
      <TwoFactorCard required={s.two_factor_required} method={s.two_factor} left={s.recovery_codes_left} />
      {me.data?.hotel && <PinCard hasPin={s.has_pin} />}
      <MyDevicesCard devices={s.devices} />
    </>
  )
}

function TwoFactorCard({ required, method, left }: { required: boolean; method: 'app' | 'email' | null; left: number }) {
  const { t } = useTranslation()
  const [mode, setMode] = useState<'idle' | 'setup' | 'off' | 'codes'>('idle')
  const [password, setPassword] = useState('')
  const [codes, setCodes] = useState<string[] | null>(null)
  const disable = useDisableTwoFactor()
  const renew = useNewRecoveryCodes()

  return (
    <Card className="flex flex-col gap-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h2 className="font-semibold">{t('security.twoStep')}</h2>
          <p className="text-sm text-muted">{method ? t('security.onWith', { method: t(`security.methods.${method}`) }) : required ? t('security.requiredHelp') : t('security.offHelp')}</p>
          {method && <p className="text-xs text-muted">{t('security.codesLeft', { count: left })}</p>}
        </div>
        {method ? <Badge tone="ok">{t('common.on')}</Badge> : <Badge tone={required ? 'warn' : 'grey'}>{t('common.off')}</Badge>}
      </div>
      {mode === 'setup' && <TwoFactorSetup onDone={() => setMode('idle')} />}
      {codes && <RecoveryCodes codes={codes} onDone={() => setCodes(null)} />}
      {(mode === 'off' || mode === 'codes') && (
        <form
          noValidate
          className="flex flex-wrap items-end gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            if (mode === 'off') disable.mutate(password, { onSuccess: () => setMode('idle') })
            else renew.mutate(password, { onSuccess: (r) => { setCodes(r.recovery_codes); setMode('idle') } })
            setPassword('')
          }}
        >
          <div className="w-64">
            <Field label={t('security.yourPassword')} type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
          </div>
          <Button type="submit" variant={mode === 'off' ? 'danger' : 'primary'} disabled={!password}>
            {mode === 'off' ? t('security.turnOff') : t('security.newCodes')}
          </Button>
          <Button variant="ghost" onClick={() => setMode('idle')}>
            {t('common.cancel')}
          </Button>
        </form>
      )}
      {(disable.error || renew.error) && <Alert>{errorText(disable.error ?? renew.error)}</Alert>}
      {mode === 'idle' && !codes && (
        <div className="flex flex-wrap gap-2">
          {method ? (
            <>
              <Button variant="secondary" onClick={() => setMode('codes')}>
                {t('security.newCodes')}
              </Button>
              {!required && (
                <Button variant="ghost" onClick={() => setMode('off')}>
                  {t('security.turnOff')}
                </Button>
              )}
            </>
          ) : (
            <Button onClick={() => setMode('setup')}>{t('security.turnOn')}</Button>
          )}
        </div>
      )}
    </Card>
  )
}

function PinCard({ hasPin }: { hasPin: boolean }) {
  const { t } = useTranslation()
  const save = useSetPin()
  const [open, setOpen] = useState(false)
  const [pin, setPin] = useState('')
  const [password, setPassword] = useState('')
  return (
    <Card className="flex flex-col gap-3">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h2 className="font-semibold">{t('security.pin')}</h2>
          <p className="text-sm text-muted">{t('security.pinHelp')}</p>
        </div>
        {hasPin ? <Badge tone="ok">{t('security.pinSet')}</Badge> : <Badge>{t('security.noPin')}</Badge>}
      </div>
      {save.isSuccess && !open && <Alert tone="ok">{t('common.saved')}</Alert>}
      {open ? (
        <form
          noValidate
          className="flex flex-wrap items-end gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            save.mutate({ pin, password }, { onSuccess: () => { setOpen(false); setPin(''); setPassword('') } })
          }}
        >
          <div className="w-32">
            <Field label={t('security.newPin')} type="password" inputMode="numeric" autoComplete="off" maxLength={6} value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))} />
          </div>
          <div className="w-56">
            <Field label={t('security.yourPassword')} type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
          </div>
          <Button type="submit" disabled={pin.length < 4 || !password || save.isPending}>
            {t('common.save')}
          </Button>
          <Button variant="ghost" onClick={() => setOpen(false)}>
            {t('common.cancel')}
          </Button>
        </form>
      ) : (
        <div>
          <Button variant="secondary" onClick={() => setOpen(true)}>
            {hasPin ? t('security.changePin') : t('security.setPin')}
          </Button>
        </div>
      )}
      {save.error && <Alert>{errorText(save.error)}</Alert>}
    </Card>
  )
}

function MyDevicesCard({ devices }: { devices: Device[] }) {
  const { t } = useTranslation()
  const out = useSignOutMyDevice()
  const others = useSignOutOthers()
  const active = devices.filter((d) => d.status !== 'signed_out')
  return (
    <Card className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="font-semibold">{t('security.myDevices')}</h2>
        {active.filter((d) => !d.this_device && d.status === 'active').length > 0 && (
          <Button variant="secondary" disabled={others.isPending} onClick={() => others.mutate(undefined)}>
            {t('security.signOutOthers')}
          </Button>
        )}
      </div>
      <ul className="flex flex-col">
        {active.map((d) => (
          <li key={d.id} className="flex items-center gap-3 border-t border-border py-2 first:border-t-0">
            <ComputerDesktopIcon aria-hidden className="size-5 shrink-0 text-icon" />
            <span className="flex min-w-0 flex-1 flex-col text-[13px]">
              <span className="font-medium">
                {d.name} {d.this_device && <span className="text-accent-text">· {t('security.thisDevice')}</span>}
              </span>
              <span className="text-xs text-muted">
                {lastSeen(d.last_seen)} {d.ip && `· ${d.ip}`}
              </span>
            </span>
            <Badge tone={STATUS_TONE[d.status]}>{t(`security.statuses.${d.status}`)}</Badge>
            {!d.this_device && d.status === 'active' && (
              <Button variant="ghost" disabled={out.isPending} onClick={() => out.mutate(d.id)}>
                {t('security.signOut')}
              </Button>
            )}
          </li>
        ))}
      </ul>
    </Card>
  )
}
