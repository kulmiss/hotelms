import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Badge } from '@/components/ui/Badge'
import { Card } from '@/components/ui/Card'
import { getHealth } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { buttonClass } from '@/components/ui/Button'
import { Link } from 'react-router'

export function DashboardPage() {
  const { t } = useTranslation()
  const health = useQuery({ queryKey: ['health'], queryFn: getHealth, retry: 1 })

  const status = health.isPending ? (
    <Badge tone="grey">{t('dashboard.checking')}</Badge>
  ) : health.isError ? (
    <Badge tone="danger">{t('dashboard.offline')}</Badge>
  ) : health.data.status === 'ok' ? (
    <Badge tone="ok">{t('dashboard.connected')}</Badge>
  ) : (
    <Badge tone="warn">{t('dashboard.degraded')}</Badge>
  )

  return (
    <div className="mx-auto flex max-w-5xl flex-col gap-6">
      <h1 className="text-2xl font-bold">{t('dashboard.title')}</h1>
      <FinishSetupCard />
      <Card className="flex flex-col gap-2">
        <h2 className="text-lg font-semibold">{t('dashboard.welcome')}</h2>
        <p className="text-muted">{t('dashboard.intro')}</p>
      </Card>
      <Card className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-col gap-1">
          <span className="text-sm font-medium text-muted">{t('dashboard.apiStatus')}</span>
          {health.data && (
            <span className="text-sm text-muted">{t('dashboard.version', { version: health.data.version })}</span>
          )}
        </div>
        {status}
      </Card>
    </div>
  )
}

/** Shown to the Hotel Admin while setup steps are left or were skipped. */
function FinishSetupCard() {
  const { t } = useTranslation()
  const me = useMe()
  const setup = me.data?.role?.is_hotel_admin && !me.data.impersonation ? me.data.hotel?.setup : null
  if (!setup || (setup.completed && setup.skipped === 0)) return null
  return (
    <Card className="flex flex-wrap items-center justify-between gap-3 ring-accent/40">
      <div className="flex flex-col gap-1">
        <h2 className="text-sm font-semibold">{t('setup.card.title')}</h2>
        <p className="text-[13px] text-muted">
          {setup.completed ? t('setup.card.skipped', { count: setup.skipped }) : t('setup.card.unfinished', { count: setup.remaining })}
        </p>
      </div>
      <Link to="/setup" className={buttonClass()}>
        {t('setup.card.action')}
      </Link>
    </Card>
  )
}
