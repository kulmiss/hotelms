import { useNavigate } from 'react-router'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { useLogout } from '@/lib/auth'
import { TwoFactorSetup } from './SecurityParts'

/** Shown when two-step sign-in is required and not set up yet (nothing else opens until it is). */
export function TwoStepPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const logout = useLogout()
  return (
    <div className="flex min-h-svh items-center justify-center p-4">
      <Card className="flex w-full max-w-lg flex-col gap-y-6 p-8 animate-page-in motion-reduce:animate-none">
        <div className="flex flex-col gap-1.5">
          <h1 className="text-lg font-semibold tracking-tight">{t('security.forcedTitle')}</h1>
          <p className="text-sm text-muted">{t('security.forcedIntro')}</p>
        </div>
        <TwoFactorSetup onDone={() => navigate('/', { replace: true })} />
        <Button variant="ghost" onClick={() => logout.mutate(undefined, { onSettled: () => navigate('/login', { replace: true }) })}>
          {t('topbar.signOut')}
        </Button>
      </Card>
    </div>
  )
}
