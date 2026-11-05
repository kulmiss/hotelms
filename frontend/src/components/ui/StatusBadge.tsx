import { useTranslation } from 'react-i18next'
import { Badge, type Tone } from './Badge'

const tones: Record<string, Tone> = { trial: 'info', active: 'ok', grace: 'warn', suspended: 'danger' }

export function StatusBadge({ status }: { status: string }) {
  const { t } = useTranslation()
  return <Badge tone={tones[status] ?? 'grey'}>{t(`hotelStatus.${status}`, status)}</Badge>
}
