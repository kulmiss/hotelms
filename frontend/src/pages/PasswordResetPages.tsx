import { useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { useMutation } from '@tanstack/react-query'
import { SILENT } from '@/lib/toast'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { ApiError, post, prepareSession } from '@/lib/api'
import { BrandMark, useBrand } from '@/lib/brand'

function Frame({ title, intro, children }: { title: string; intro: string; children: ReactNode }) {
  const brand = useBrand()
  return (
    <div className="flex min-h-svh flex-col items-center justify-center gap-y-4 px-4 py-8">
      <span className="mb-2 flex items-center justify-center gap-x-2 text-xl font-medium">
        <BrandMark className="size-8" />
        {brand.name}
      </span>
      <Card className="flex w-full max-w-[480px] flex-col gap-y-6 p-6 animate-page-in sm:p-8 motion-reduce:animate-none">
        <div className="flex flex-col gap-y-1.5">
          <h1 className="text-lg font-semibold tracking-tight">{title}</h1>
          <p className="text-sm text-muted">{intro}</p>
        </div>
        {children}
      </Card>
    </div>
  )
}

const errorOf = (e: unknown, fallback: string) => (e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? (e.status === 429 ? null : e.message)) : e ? fallback : null)

/** "Forgot password?": we email a link (the answer is the same for every email). */
export function ForgotPasswordPage() {
  const { t } = useTranslation()
  const [email, setEmail] = useState('')
  const send = useMutation({
    meta: SILENT,
    mutationFn: async (v: string) => {
      await prepareSession()
      return post('/auth/forgot-password', { email: v })
    },
  })

  function submit(e: FormEvent) {
    e.preventDefault()
    send.mutate(email)
  }

  return (
    <Frame title={t('reset.forgotTitle')} intro={t('reset.forgotIntro')}>
      {send.isSuccess ? (
        <Alert tone="ok">{t('reset.sent')}</Alert>
      ) : (
        <form className="flex flex-col gap-y-5" onSubmit={submit} noValidate>
          {send.error && <Alert>{errorOf(send.error, t('common.serverUnreachable')) ?? t('login.tooManyAttempts')}</Alert>}
          <Field label={t('login.email')} type="email" autoComplete="email" autoFocus value={email} onChange={(e) => setEmail(e.target.value)} />
          <Button type="submit" size="lg" disabled={send.isPending || !email}>
            {t('reset.sendLink')}
          </Button>
        </form>
      )}
      <Link to="/login" className="text-center text-sm font-medium text-muted hover:opacity-75">
        {t('reset.backToSignIn')}
      </Link>
    </Frame>
  )
}

/** The link from the email: choose a new password. */
export function ResetPasswordPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const reset = useMutation({
    meta: SILENT,
    mutationFn: async () => {
      await prepareSession()
      return post('/auth/reset-password', { token: params.get('token') ?? '', email: params.get('email') ?? '', password, password_confirmation: confirm })
    },
  })
  const error = reset.error instanceof ApiError ? reset.error : null

  return (
    <Frame title={t('reset.newTitle')} intro={t('reset.newIntro')}>
      {reset.isSuccess ? (
        <div className="flex flex-col gap-4">
          <Alert tone="ok">{t('reset.done')}</Alert>
          <Button size="lg" onClick={() => navigate('/login', { replace: true })}>
            {t('login.submit')}
          </Button>
        </div>
      ) : (
        <form
          className="flex flex-col gap-y-5"
          noValidate
          onSubmit={(e) => {
            e.preventDefault()
            reset.mutate()
          }}
        >
          {error?.field('email') && <Alert>{error.field('email')}</Alert>}
          {reset.error && !error && <Alert>{t('common.serverUnreachable')}</Alert>}
          <Field label={t('reset.password')} hint={t('reset.passwordHint')} type="password" autoComplete="new-password" autoFocus value={password} onChange={(e) => setPassword(e.target.value)} error={error?.field('password')} />
          <Field label={t('reset.confirm')} type="password" autoComplete="new-password" value={confirm} onChange={(e) => setConfirm(e.target.value)} />
          <Button type="submit" size="lg" disabled={reset.isPending || password.length < 10 || password !== confirm}>
            {t('reset.save')}
          </Button>
        </form>
      )}
    </Frame>
  )
}

/** The link sent to a new sign-in email (SECURITY_REPORT M7): opening it makes the change. */
export function ConfirmEmailPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const confirm = useMutation({
    meta: SILENT,
    mutationFn: async () => {
      await prepareSession()
      return post<{ message: string; email: string }>('/auth/confirm-email', { token: params.get('token') ?? '' })
    },
  })
  const error = confirm.error instanceof ApiError ? confirm.error : null

  return (
    <Frame title={t('confirmEmail.title')} intro={t('confirmEmail.intro')}>
      {confirm.isSuccess ? (
        <div className="flex flex-col gap-4">
          <Alert tone="ok">{t('confirmEmail.done', { email: confirm.data.email })}</Alert>
          <Button size="lg" onClick={() => navigate('/login', { replace: true })}>
            {t('login.submit')}
          </Button>
        </div>
      ) : (
        <div className="flex flex-col gap-4">
          {error && <Alert>{error.field('token') ?? error.message}</Alert>}
          {confirm.error && !error && <Alert>{t('common.serverUnreachable')}</Alert>}
          <Button size="lg" disabled={confirm.isPending} onClick={() => confirm.mutate()}>
            {t('confirmEmail.button')}
          </Button>
        </div>
      )}
    </Frame>
  )
}
