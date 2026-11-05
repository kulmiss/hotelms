import { Navigate, Outlet, useLocation } from 'react-router'
import { useTranslation } from 'react-i18next'
import { useMe } from '@/lib/auth'
import { Button } from '@/components/ui/Button'

/** Lets signed-in users through; sends everyone else to the sign-in page. */
export function RequireAuth() {
  const { t } = useTranslation()
  const me = useMe()
  const location = useLocation()

  if (me.isPending) {
    return (
      <div role="status" className="flex h-full items-center justify-center text-muted">
        {t('common.loading')}
      </div>
    )
  }

  if (me.isError) {
    return (
      <div className="flex h-full flex-col items-center justify-center gap-4 p-4 text-center">
        <p className="text-muted">{t('common.serverUnreachable')}</p>
        <Button variant="secondary" onClick={() => me.refetch()}>
          {t('common.tryAgain')}
        </Button>
      </div>
    )
  }

  if (!me.data) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }

  if (me.data.user.must_change_password && location.pathname !== '/change-password') {
    return <Navigate to="/change-password" replace />
  }

  return <Outlet />
}
