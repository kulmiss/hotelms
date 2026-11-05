import { useNavigate } from 'react-router'
import { useTranslation } from 'react-i18next'
import { ArrowUturnLeftIcon, LifebuoyIcon, SparklesIcon } from '@heroicons/react/20/solid'
import { useMe, useStopImpersonation } from '@/lib/auth'

/**
 * A strip above the app when HotelMS support is inside a hotel ("Log in as"),
 * or when someone is exploring the demo hotel.
 */
export function SessionBanner() {
  const { t } = useTranslation()
  const me = useMe()
  const stop = useStopImpersonation()
  const navigate = useNavigate()
  const support = me.data?.impersonation

  if (support && me.data) {
    return (
      <div role="status" className="flex flex-wrap items-center gap-x-3 gap-y-1 bg-warn-bg px-4 py-2 text-[13px] text-warn-text ring-1 ring-warn-text/20 md:px-6 lg:px-8">
        <LifebuoyIcon aria-hidden className="size-4 shrink-0" />
        <span className="flex-1">
          {t('support.banner', { name: me.data.user.name, hotel: me.data.hotel?.name ?? '', by: support.by })}
          <span className="opacity-80"> {t('support.reason', { reason: support.reason })}</span>
        </span>
        <button
          type="button"
          disabled={stop.isPending}
          onClick={() => stop.mutate(undefined, { onSuccess: () => navigate('/admin', { replace: true }) })}
          className="inline-flex min-h-touch items-center gap-1.5 rounded-lg px-2 font-semibold hover:bg-warn-text/10"
        >
          <ArrowUturnLeftIcon aria-hidden className="size-4" />
          {t('support.return')}
        </button>
      </div>
    )
  }

  if (me.data?.hotel?.is_demo) {
    return (
      <div role="status" className="flex items-center gap-x-2 bg-info-bg px-4 py-2 text-[13px] text-info-text md:px-6 lg:px-8">
        <SparklesIcon aria-hidden className="size-4 shrink-0" />
        {t('demo.banner')}
      </div>
    )
  }

  return null
}
