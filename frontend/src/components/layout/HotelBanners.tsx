import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { XMarkIcon } from '@heroicons/react/20/solid'
import { useMe } from '@/lib/auth'
import { cn } from '@/lib/cn'

const KEY = 'hms_banners_closed'
const closed = (): number[] => {
  try {
    return JSON.parse(sessionStorage.getItem(KEY) ?? '[]') as number[]
  } catch {
    return []
  }
}

/** Messages from HotelMS to all hotels or chosen ones (W4). Can be closed until the next sign-in. */
export function HotelBanners() {
  const { t } = useTranslation()
  const me = useMe()
  const [hidden, setHidden] = useState(closed)
  const banners = (me.data?.banners ?? []).filter((b) => !hidden.includes(b.id))
  if (!banners.length) return null
  return (
    <>
      {banners.map((b) => (
        <div
          key={b.id}
          role="status"
          className={cn(
            'flex items-center gap-x-2 px-4 py-2 text-[13px] md:px-6 lg:px-8',
            b.tone === 'danger' ? 'bg-danger-bg text-danger-text' : b.tone === 'warn' ? 'bg-warn-bg text-warn-text' : 'bg-info-bg text-info-text',
          )}
        >
          <span className="flex-1">{b.message}</span>
          <button
            type="button"
            aria-label={t('common.close')}
            onClick={() => {
              const next = [...hidden, b.id]
              setHidden(next)
              try {
                sessionStorage.setItem(KEY, JSON.stringify(next))
              } catch {
                /* private mode */
              }
            }}
            className="flex size-8 items-center justify-center rounded-md hover:bg-black/5 dark:hover:bg-white/10"
          >
            <XMarkIcon aria-hidden className="size-4" />
          </button>
        </div>
      ))}
    </>
  )
}
