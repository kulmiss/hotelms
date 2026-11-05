import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { ApiError } from '@/lib/api'
import { useChangePassword, useLogout, useMe } from '@/lib/auth'

export function ChangePasswordPage() {
  const { t } = useTranslation()
  const me = useMe()
  const change = useChangePassword()
  const logout = useLogout()
  const navigate = useNavigate()
  const [current, setCurrent] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')

  const forced = me.data?.user.must_change_password ?? false
  const error = change.error instanceof ApiError ? change.error : null
  const mismatch = confirm !== '' && confirm !== password

  function submit(e: FormEvent) {
    e.preventDefault()
    change.mutate(
      { current_password: current, password, password_confirmation: confirm },
      { onSuccess: () => navigate('/', { replace: true }) },
    )
  }

  return (
    <div className="flex min-h-full items-center justify-center p-4">
      <Card className="flex w-full max-w-md flex-col gap-y-6 p-8 animate-page-in motion-reduce:animate-none">
        <div className="flex flex-col gap-2 text-center">
          <h1 className="text-xl font-bold">{t('password.title')}</h1>
          <p className="text-sm text-muted">{forced ? t('password.forcedIntro') : t('password.intro')}</p>
        </div>
        <form className="flex flex-col gap-4" onSubmit={submit} noValidate>
          {change.error && !error && <Alert>{t('common.serverUnreachable')}</Alert>}
          <Field
            label={t('password.current')}
            type="password"
            autoComplete="current-password"
            value={current}
            onChange={(e) => setCurrent(e.target.value)}
            error={error?.field('current_password')}
          />
          <Field
            label={t('password.new')}
            type="password"
            autoComplete="new-password"
            hint={t('password.rules')}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            error={error?.field('password')}
          />
          <Field
            label={t('password.confirm')}
            type="password"
            autoComplete="new-password"
            value={confirm}
            onChange={(e) => setConfirm(e.target.value)}
            error={mismatch ? t('password.mismatch') : undefined}
          />
          <Button type="submit" size="lg" disabled={change.isPending || !current || !password || mismatch}>
            {t('password.submit')}
          </Button>
          {!forced && (
            <Button variant="ghost" onClick={() => navigate(-1)}>
              {t('common.cancel')}
            </Button>
          )}
          {forced && (
            <Button variant="ghost" onClick={() => logout.mutate()}>
              {t('topbar.signOut')}
            </Button>
          )}
        </form>
      </Card>
    </div>
  )
}
