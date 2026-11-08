import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { Wrench } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { useLogout, useMe } from '@/lib/auth'

/** Full screen while HotelMS has switched on maintenance for this hotel (W4). Checks again every minute. */
export function Maintenance() {
  const { t } = useTranslation()
  const me = useMe()
  const logout = useLogout()
  const { refetch } = me
  useEffect(() => {
    const id = window.setInterval(() => void refetch(), 60_000)
    return () => window.clearInterval(id)
  }, [refetch])
  return (
    <div role="status" className="flex min-h-full flex-col items-center justify-center gap-4 p-6 text-center">
      <Wrench aria-hidden className="size-10 text-icon" />
      <h1 className="text-lg font-semibold">{t('switches.maintenance.title')}</h1>
      <p className="max-w-sm text-[13px] text-muted">{t('switches.maintenance.body')}</p>
      <div className="flex gap-2">
        <Button onClick={() => me.refetch()} disabled={me.isFetching}>
          {t('switches.maintenance.retry')}
        </Button>
        <Button variant="secondary" onClick={() => logout.mutate()}>
          {t('topbar.signOut')}
        </Button>
      </div>
    </div>
  )
}

export function ReadOnlyNotice() {
  const { t } = useTranslation()
  return (
    <div role="status" className="bg-warn-bg px-4 py-2 text-sm text-warn-text">
      {t('switches.readOnly')}
    </div>
  )
}
