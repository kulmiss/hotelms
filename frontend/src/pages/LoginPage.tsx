import { useState, type FormEvent } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { ApiError } from '@/lib/api'
import { useLogin, useMe } from '@/lib/auth'

export function LoginPage() {
  const { t } = useTranslation()
  const me = useMe()
  const login = useLogin()
  const navigate = useNavigate()
  const location = useLocation()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(true)

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
    login.mutate({ email, password, remember }, { onSuccess: () => navigate(from, { replace: true }) })
  }

  return (
    <div className="flex min-h-full flex-col items-center justify-center gap-y-6 px-4 py-12">
      <span className="flex items-center gap-x-2.5 text-2xl font-bold tracking-tight">
        <img src="/favicon.svg" alt="" className="size-10" />
        {t('app.name')}
      </span>
      <Card className="flex w-full max-w-md flex-col gap-y-8 p-8 animate-page-in motion-reduce:animate-none">
        <div className="flex flex-col gap-y-2 text-center">
          <h1 className="text-2xl font-bold tracking-tight">{t('login.title')}</h1>
          <p className="text-sm text-muted">{t('login.subtitle')}</p>
        </div>
        <form className="flex flex-col gap-y-6" onSubmit={submit} noValidate>
          {fieldError && <Alert>{fieldError}</Alert>}
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
              className="size-5 rounded accent-accent"
              checked={remember}
              onChange={(e) => setRemember(e.target.checked)}
            />
            {t('login.remember')}
          </label>
          <Button type="submit" size="lg" disabled={login.isPending || !email || !password}>
            {login.isPending ? t('login.signingIn') : t('login.submit')}
          </Button>
        </form>
      </Card>
    </div>
  )
}
