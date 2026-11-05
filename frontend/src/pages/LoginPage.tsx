import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'

export function LoginPage() {
  const { t } = useTranslation()
  return (
    <div className="flex min-h-full items-center justify-center p-4">
      <Card className="flex w-full max-w-sm flex-col gap-5 p-6">
        <div className="flex flex-col items-center gap-2 text-center">
          <img src="/favicon.svg" alt="" className="size-12" />
          <h1 className="text-xl font-bold">{t('login.title')}</h1>
          <p className="text-sm text-muted">{t('login.subtitle')}</p>
        </div>
        <form className="flex flex-col gap-4" onSubmit={(e) => e.preventDefault()}>
          <Field label={t('login.email')} type="email" autoComplete="email" />
          <Field label={t('login.password')} type="password" autoComplete="current-password" />
          <Button type="submit" size="lg" disabled>
            {t('login.submit')}
          </Button>
          <p className="text-center text-xs text-muted">{t('login.notReady')}</p>
        </form>
      </Card>
    </div>
  )
}
