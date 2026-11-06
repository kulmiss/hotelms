import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { Card } from '@/components/ui/Card'
import { PageHeader } from '@/components/ui/PageHeader'
import { useMe } from '@/lib/auth'
import { date } from '@/lib/format'
import { useWhatsNew } from '@/lib/platform'
import { markWhatsNewSeen } from '@/lib/whatsNew'

/** "What's new": notes the HotelMS team published with each version. Opening it clears the dot in the menu. */
export function WhatsNewPage() {
  const { t } = useTranslation()
  const q = useWhatsNew()
  const me = useMe()
  const newest = q.data?.data[0]?.id
  useEffect(() => {
    if (newest && me.data) markWhatsNewSeen(me.data.user.id, newest)
  }, [newest, me.data])
  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-6">
      <PageHeader title={t('whatsNew.title')} subtitle={q.data ? t('whatsNew.version', { version: q.data.version }) : undefined} />
      {q.data?.data.length === 0 && <p className="text-[13px] text-muted">{t('whatsNew.none')}</p>}
      <ul className="flex flex-col gap-3">
        {q.data?.data.map((r) => (
          <li key={r.id}>
            <Card className="flex flex-col gap-1">
              <p className="text-xs text-muted">
                {r.version} · {date(r.published_at?.slice(0, 10) ?? null)}
              </p>
              <h2 className="text-sm font-semibold">{r.title}</h2>
              <p className="text-[13px] whitespace-pre-wrap">{r.body}</p>
            </Card>
          </li>
        ))}
      </ul>
    </div>
  )
}
