import { useEffect, useState, type FormEvent } from 'react'
import { Link, Navigate, useLocation, useNavigate } from 'react-router'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { ApiError } from '@/lib/api'
import { useDemoLogin, useLogin, useMe, useResendCode, useTwoFactorLogin } from '@/lib/auth'
import { BrandMark, useBrand } from '@/lib/brand'
import { clearDemoOver, isDemoOver } from '@/lib/demo'
import { ResendLink } from './SignupPages'

export function LoginPage() {
  const { t } = useTranslation()
  // After a "Try the demo" visit ended: say so once.
  const [demoOver] = useState(isDemoOver)
  useEffect(() => clearDemoOver(), [])
  const me = useMe()
  const login = useLogin()
  const brand = useBrand()
  const navigate = useNavigate()
  const location = useLocation()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(true)
  const [step, setStep] = useState<{ method: 'app' | 'email'; email?: string | null } | null>(null)

  const from = (location.state as { from?: string } | null)?.from ?? '/'

  if (me.data) return <Navigate to={from} replace />

  const error = login.error instanceof ApiError ? login.error : null
  const fieldError = error?.field('email') ?? error?.field('password')
  const generalError =
    login.error && !fieldError
      ? error?.status === 429
        ? t('login.tooManyAttempts')
        : t('common.serverUnreachable')
      : null

  function submit(e: FormEvent) {
    e.preventDefault()
    login.mutate(
      { email, password, remember },
      { onSuccess: (r) => (r?.two_factor ? setStep({ method: r.two_factor, email: r.email }) : navigate(from, { replace: true })) },
    )
  }

  return (
    <div className="flex min-h-svh flex-col items-center justify-center gap-y-4 px-4 py-8">
      <span className="mb-2 flex items-center justify-center gap-x-2 text-xl font-medium">
        <BrandMark className="size-8" />
        {brand.name}
      </span>
      <Card className="flex w-full max-w-[480px] flex-col gap-y-6 p-6 animate-page-in sm:p-8 motion-reduce:animate-none">
        <div className="flex flex-col gap-y-1.5">
          <h1 className="text-lg font-semibold tracking-tight">{t('login.title')}</h1>
          <p className="text-sm text-muted">{brand.loginMessage || t('login.subtitle')}</p>
          {demoOver && <Alert tone="info">{t('demo.over')}</Alert>}
        </div>
        {step ? (
          <CodeStep method={step.method} email={step.email} onDone={() => navigate(from, { replace: true })} onBack={() => { setStep(null); setPassword('') }} />
        ) : (
        <form className="flex flex-col gap-y-6" onSubmit={submit} noValidate>
          {fieldError && <Alert>{fieldError}</Alert>}
          {error?.code === 'email_unverified' && <ResendLink email={email} />}
          {generalError && <Alert>{generalError}</Alert>}
          <Field
            label={t('login.email')}
            type="email"
            autoComplete="email"
            inputMode="email"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
          <Field
            label={t('login.password')}
            type="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
          <label className="flex min-h-touch items-center gap-3 text-sm text-text">
            <input
              type="checkbox"
              className="size-5 rounded"
              checked={remember}
              onChange={(e) => setRemember(e.target.checked)}
            />
            {t('login.remember')}
          </label>
          <Button type="submit" size="lg" disabled={login.isPending || !email || !password}>
            {login.isPending ? t('login.signingIn') : t('login.submit')}
          </Button>
          <Link to="/forgot-password" className="text-center text-sm font-medium text-muted hover:opacity-75">
            {t('login.forgot')}
          </Link>
          {brand.raw.signup_mode && brand.raw.signup_mode !== 'off' && (
            <Link to="/register" className="text-center text-sm font-medium text-accent hover:opacity-75">
              {t('signup.registerLink')}
            </Link>
          )}
        </form>
        )}
      </Card>
      {brand.raw.demo_public && <TryDemo onDone={() => navigate('/', { replace: true })} />}
    </div>
  )
}

/** Second step of signing in: a 6-digit code (app or email), or a recovery code. */
function CodeStep({ method, email, onDone, onBack }: { method: 'app' | 'email'; email?: string | null; onDone: () => void; onBack: () => void }) {
  const { t } = useTranslation()
  const verify = useTwoFactorLogin()
  const resend = useResendCode()
  const [code, setCode] = useState('')
  const [recovery, setRecovery] = useState(false)
  const error = verify.error instanceof ApiError ? (verify.error.field('code') ?? (verify.error.status === 429 ? t('login.tooManyAttempts') : verify.error.message)) : verify.error ? t('common.serverUnreachable') : null
  const ok = recovery ? code.includes('-') : code.length === 6

  function submit(e: FormEvent) {
    e.preventDefault()
    verify.mutate(code.trim(), { onSuccess: onDone })
  }

  return (
    <form className="flex flex-col gap-y-5" onSubmit={submit} noValidate>
      <p className="text-center text-sm text-muted">{recovery ? t('login.recoveryHelp') : method === 'email' ? t('login.codeEmail', { email: email ?? '' }) : t('login.codeApp')}</p>
      {error && <Alert>{error}</Alert>}
      {resend.isSuccess && <Alert tone="ok">{t('login.codeResent')}</Alert>}
      <Field
        label={recovery ? t('login.recoveryCode') : t('security.code')}
        inputMode={recovery ? 'text' : 'numeric'}
        autoComplete="one-time-code"
        autoFocus
        maxLength={recovery ? 11 : 6}
        value={code}
        onChange={(e) => setCode(recovery ? e.target.value.trim().toLowerCase() : e.target.value.replace(/\D/g, ''))}
      />
      <Button type="submit" size="lg" disabled={!ok || verify.isPending}>
        {t('login.verify')}
      </Button>
      <div className="flex flex-wrap justify-between gap-2">
        <Button variant="ghost" onClick={onBack}>
          {t('common.back')}
        </Button>
        {method === 'email' && !recovery && (
          <Button variant="ghost" disabled={resend.isPending} onClick={() => resend.mutate()}>
            {t('login.resend')}
          </Button>
        )}
        <Button variant="ghost" onClick={() => { setRecovery(!recovery); setCode('') }}>
          {recovery ? t('login.useCode') : t('login.useRecovery')}
        </Button>
      </div>
    </form>
  )
}

const DEMO_ROLES = ['hotel_admin', 'branch_manager', 'receptionist', 'housekeeping', 'accountant', 'restaurant'] as const

/** "Try the demo": one tap to explore the demo hotel as a chosen role. */
function TryDemo({ onDone }: { onDone: () => void }) {
  const { t } = useTranslation()
  const demo = useDemoLogin()
  return (
    <div className="flex w-full max-w-sm flex-col items-center gap-3 text-center">
      <span className="text-sm text-muted">{t('demo.tryTitle')}</span>
      <div className="flex flex-wrap justify-center gap-2">
        {DEMO_ROLES.map((r) => (
          <Button key={r} variant="secondary" disabled={demo.isPending} onClick={() => demo.mutate(r, { onSuccess: onDone })}>
            {t(`demo.roles.${r}`)}
          </Button>
        ))}
      </div>
    </div>
  )
}
