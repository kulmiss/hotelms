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

  // Two-step sign-in is required and not set up yet: nothing else opens until it is (after the password).
  if (me.data.security?.two_factor_setup_required && !me.data.user.must_change_password) {
    return location.pathname === '/two-step' ? <Outlet /> : <Navigate to="/two-step" replace />
  }

  // A new Hotel Admin goes through the setup walkthrough (its first step is the new password).
  // After "Save and exit" the dashboard shows a "Finish setup" card instead.
  // (The walkthrough itself is a Super Admin switch.)
  const walkthrough = me.data.switches?.setup_walkthrough !== false
  const setup = me.data.role?.is_hotel_admin && !me.data.impersonation && walkthrough ? me.data.hotel?.setup : null
  const onSetup = location.pathname === '/setup'
  if (setup && !setup.completed && (!setup.started || me.data.user.must_change_password) && !onSetup) {
    return <Navigate to="/setup" replace />
  }

  // Support ("log in as hotel") never sets the person's password: the server lets support through, so does the app.
  if (me.data.user.must_change_password && !me.data.impersonation && location.pathname !== '/change-password' && !onSetup) {
    return <Navigate to="/change-password" replace />
  }

  return <Outlet />
}
