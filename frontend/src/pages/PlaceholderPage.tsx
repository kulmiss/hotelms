import { useTranslation } from 'react-i18next'
import type { NavItem } from '@/app/navigation'
import { Badge } from '@/components/ui/Badge'
import { Card } from '@/components/ui/Card'

export function PlaceholderPage({ item }: { item: NavItem }) {
  const { t } = useTranslation()
  const Icon = item.icon
  return (
    <div className="mx-auto flex max-w-5xl flex-col gap-6">
      <h1 className="text-2xl font-bold">{t(`nav.${item.key}`)}</h1>
      <Card className="flex flex-col items-center gap-3 py-12 text-center">
        <span className="flex size-16 items-center justify-center rounded-2xl bg-accent-soft text-accent-text">
          <Icon aria-hidden weight="duotone" className="size-9" />
        </span>
        <Badge tone="accent">{t('placeholder.comingIn', { phase: item.phase })}</Badge>
        <p className="max-w-sm text-muted">{t('placeholder.body', { phase: item.phase })}</p>
      </Card>
    </div>
  )
}
