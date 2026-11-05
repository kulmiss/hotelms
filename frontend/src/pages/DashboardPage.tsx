import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Badge } from '@/components/ui/Badge'
import { Card } from '@/components/ui/Card'
import { getHealth } from '@/lib/api'

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
