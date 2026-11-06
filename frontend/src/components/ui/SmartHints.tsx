import { useState } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { SparklesIcon, XMarkIcon } from '@heroicons/react/20/solid'
import { cn } from '@/lib/cn'
import { useDismissed, type SmartHint } from '@/lib/smart'

const TONES = { info: 'bg-info-bg/60 ring-info-text/15', ok: 'bg-ok-bg/60 ring-ok-text/15', warn: 'bg-warn-bg/60 ring-warn-text/20' }

/** ✨ Smart hints: say why, can be dismissed, never change anything by themselves. */
export function SmartHints({ hints, className }: { hints: SmartHint[] | undefined; className?: string }) {
  const { isDismissed } = useDismissed()
  const shown = (hints ?? []).filter((h) => !isDismissed(h))
  if (!shown.length) return null
  return (
    <ul className={cn('flex flex-col gap-2', className)}>
      {shown.map((h) => (
        <li key={`${h.key}:${h.text}`}>
          <SmartHintRow h={h} />
        </li>
      ))}
    </ul>
  )
}

function SmartHintRow({ h }: { h: SmartHint }) {
  const { t } = useTranslation()
  const { dismiss } = useDismissed()
  const [why, setWhy] = useState(false)
  return (
    <div role="note" className={cn('flex items-start gap-2 rounded-lg px-3 py-2 text-[13px] ring-1 ring-inset animate-page-in', TONES[h.tone])}>
      <span className="mt-0.5 inline-flex shrink-0 items-center gap-1 rounded-md bg-surface px-1.5 py-0.5 text-[11px] font-semibold text-accent-text ring-1 ring-accent/20">
        <SparklesIcon aria-hidden className="size-3" />
        {t('smart.label')}
      </span>
      <div className="min-w-0 flex-1">
        <p>
          {h.text}{' '}
          {h.link && (
            <Link to={h.link} className="font-medium text-accent-text hover:underline">
              {t('smart.open')}
            </Link>
          )}
        </p>
        {why && <p className="mt-0.5 text-xs text-muted">{h.why}</p>}
      </div>
      <button type="button" aria-expanded={why} onClick={() => setWhy(!why)} className="min-h-6 shrink-0 rounded px-1 text-xs font-medium text-muted hover:text-text">
        {t('smart.why')}
      </button>
      <button type="button" aria-label={t('smart.dismiss')} onClick={() => dismiss(h)} className="-m-1 flex size-7 shrink-0 items-center justify-center rounded-md text-icon hover:bg-surface hover:text-muted">
        <XMarkIcon aria-hidden className="size-4" />
      </button>
    </div>
  )
}
