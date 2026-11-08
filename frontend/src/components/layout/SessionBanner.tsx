import { useEffect, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { useTranslation } from 'react-i18next'
import { LifeBuoy, Sparkles, Undo2 } from 'lucide-react'
import { ME_KEY, useMe, useStopImpersonation } from '@/lib/auth'
import { markDemoOver, mmss } from '@/lib/demo'

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
      <div role="status" className="flex flex-wrap items-center gap-x-3 gap-y-1 bg-warn-bg px-4 py-2 text-sm text-warn-text">
        <LifeBuoy aria-hidden className="size-4 shrink-0" />
        <span className="flex-1">
          {t('support.banner', { name: me.data.user.name, hotel: me.data.hotel?.name ?? '', by: support.by })}
          <span className="opacity-80"> {t('support.reason', { reason: support.reason })}</span>
        </span>
        <button
          type="button"
          disabled={stop.isPending}
          onClick={() => stop.mutate(undefined, { onSuccess: () => navigate('/admin', { replace: true }) })}
          className="inline-flex min-h-8 items-center gap-1.5 rounded-md px-2 font-medium hover:bg-warn-text/10 pointer-coarse:min-h-11"
        >
          <Undo2 aria-hidden className="size-4" />
          {t('support.return')}
        </button>
      </div>
    )
  }

  if (me.data?.hotel?.is_demo) {
    return (
      <div role="status" className="flex flex-wrap items-center gap-x-2 bg-info-bg px-4 py-2 text-sm text-info-text">
        <Sparkles aria-hidden className="size-4 shrink-0" />
        {t('demo.banner')}
        {me.data.hotel.demo_ends_at && <DemoClock endsAt={me.data.hotel.demo_ends_at} />}
      </div>
    )
  }

  return null
}

/** "Try the demo": time left; at zero the visitor is signed out (the server does the same). */
function DemoClock({ endsAt }: { endsAt: string }) {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const end = new Date(endsAt).getTime()
  const [left, setLeft] = useState(() => Math.round((end - Date.now()) / 1000))
  useEffect(() => {
    const id = window.setInterval(() => {
      const s = Math.round((end - Date.now()) / 1000)
      setLeft(s)
      if (s <= 0) {
        window.clearInterval(id)
        markDemoOver()
        qc.setQueryData(ME_KEY, null)
      }
    }, 1000)
    return () => window.clearInterval(id)
  }, [end, qc])
  return <strong className={left <= 120 ? 'text-warn-text' : undefined}>{t('demo.timeLeft', { time: mmss(left) })}</strong>
}
