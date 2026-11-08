import { useTranslation } from 'react-i18next'
import { RefreshCw, WifiOff } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { applyUpdate, useUpdateReady } from '@/app/serviceWorker'
import { useOnline } from '@/lib/offline'

/** "You're offline" (read-only copy) and "New version available" strips (decisions.md W6, W1). */
export function ConnectionBanner() {
  const { t } = useTranslation()
  const online = useOnline()
  const update = useUpdateReady()
  const qc = useQueryClient()
  const at = qc.getQueryState(['desk', 'today'])?.dataUpdatedAt ?? qc.getQueryState(['me'])?.dataUpdatedAt ?? 0

  return (
    <>
      {!online && (
        <div role="status" className="flex flex-wrap items-center gap-x-2 gap-y-1 bg-warn-bg px-4 py-2 text-sm text-warn-text">
          <WifiOff aria-hidden className="size-4 shrink-0" />
          <span className="flex-1">
            <span className="font-semibold">{t('offline.title')}</span> {at ? t('offline.copyFrom', { time: new Date(at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }) : t('offline.noCopy')}
          </span>
        </div>
      )}
      {update && (
        <div role="status" className="flex flex-wrap items-center gap-x-3 gap-y-1 bg-info-bg px-4 py-2 text-sm text-info-text">
          <span className="flex-1">{t('offline.newVersion')}</span>
          <button type="button" onClick={applyUpdate} className="inline-flex min-h-8 items-center gap-1.5 rounded-md px-2 font-medium hover:bg-info-text/10 pointer-coarse:min-h-11">
            <RefreshCw aria-hidden className="size-4" />
            {t('offline.refresh')}
          </button>
        </div>
      )}
    </>
  )
}
