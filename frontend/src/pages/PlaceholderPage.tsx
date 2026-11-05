import { useTranslation } from 'react-i18next'
import type { NavItem } from '@/app/navigation'
import { Card } from '@/components/ui/Card'
import { PageHeader } from '@/components/ui/PageHeader'

/** Filament-style empty state for screens that arrive in a later phase. */
export function PlaceholderPage({ item }: { item: NavItem }) {
  const { t } = useTranslation()
  const Icon = item.icon
  return (
    <div className="flex flex-col gap-8">
      <PageHeader title={t(`nav.${item.key}`)} />
      <Card className="flex flex-col items-center gap-y-4 px-6 py-12 text-center">
        <span className="flex size-12 items-center justify-center rounded-full bg-sunken">
          <Icon aria-hidden className="size-6 text-icon" />
        </span>
        <div className="flex flex-col gap-y-1">
          <h2 className="text-base font-semibold">{t('placeholder.comingIn', { phase: item.phase })}</h2>
          <p className="max-w-sm text-sm text-muted">{t('placeholder.body', { phase: item.phase })}</p>
        </div>
      </Card>
    </div>
  )
}
