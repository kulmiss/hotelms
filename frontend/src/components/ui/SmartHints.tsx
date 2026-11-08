import { useState } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { Sparkles, X } from 'lucide-react'
import { cn } from '@/lib/cn'
import { useDismissed, type SmartHint } from '@/lib/smart'

const TONES = { info: 'border-info-text/15 bg-info-bg/60', ok: 'border-ok-text/15 bg-ok-bg/60', warn: 'border-warn-text/20 bg-warn-bg/60' }

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
    <div role="note" className={cn('flex items-start gap-2 rounded-lg border px-4 py-3 text-sm animate-page-in', TONES[h.tone])}>
      <span className="mt-0.5 inline-flex shrink-0 items-center gap-1 rounded-md border border-accent-text/20 bg-surface px-1.5 py-0.5 text-xs font-medium text-accent-text">
        <Sparkles aria-hidden className="size-3" />
        {t('smart.label')}
      </span>
      <div className="min-w-0 flex-1">
        <p>
          {h.text}{' '}
          {h.link && (
            <Link to={h.link} className="font-medium text-accent-text underline-offset-4 hover:underline">
              {t('smart.open')}
            </Link>
          )}
        </p>
        {why && <WhyPanel h={h} />}
      </div>
      <button type="button" aria-expanded={why} onClick={() => setWhy(!why)} className="min-h-6 shrink-0 rounded-md px-1.5 text-xs font-medium text-muted hover:bg-surface hover:text-text pointer-coarse:min-h-11">
        {t('smart.why')}
      </button>
      <button type="button" aria-label={t('smart.dismiss')} onClick={() => dismiss(h)} className="-m-1 flex size-7 shrink-0 items-center justify-center rounded-md text-muted hover:bg-surface hover:text-text pointer-coarse:size-11">
        <X aria-hidden className="size-4" />
      </button>
    </div>
  )
}

/** "Why?": the reason in a sentence, the numbers behind it, how it was worked out and what you can do. */
function WhyPanel({ h }: { h: SmartHint }) {
  const { t } = useTranslation()
  const e = h.explain
  return (
    <div className="mt-2 flex flex-col gap-2 rounded-md border bg-surface/70 p-3 text-xs animate-page-in">
      <p className="text-text">{h.why}</p>
      {e && e.facts.length > 0 && (
        <dl className="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-1">
          {e.facts.map(([label, value], i) => (
            <div key={i} className="contents">
              <dt className="text-muted">{label}</dt>
              <dd className="text-right font-medium tabular-nums">{value}</dd>
            </div>
          ))}
        </dl>
      )}
      {e?.how && (
        <p>
          <span className="font-semibold">{t('smart.how')} </span>
          <span className="text-muted">{e.how}</span>
        </p>
      )}
      {e?.do && (
        <p>
          <span className="font-semibold">{t('smart.whatToDo')} </span>
          <span className="text-muted">{e.do}</span>
        </p>
      )}
      <p className="text-[11px] text-muted">{t('smart.never')}</p>
    </div>
  )
}
