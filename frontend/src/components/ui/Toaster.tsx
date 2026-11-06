import { useTranslation } from 'react-i18next'
import { CheckCircleIcon, ExclamationCircleIcon, InformationCircleIcon, XMarkIcon } from '@heroicons/react/20/solid'
import { cn } from '@/lib/cn'
import { dismissToast, useToasts } from '@/lib/toast'

const ICONS = { ok: CheckCircleIcon, info: InformationCircleIcon, danger: ExclamationCircleIcon }
const TONES = { ok: 'text-ok-text', info: 'text-info-text', danger: 'text-danger-text' }

/** Where toasts appear (bottom right; bottom centre on phones). Read out by screen readers. */
export function Toaster() {
  const { t } = useTranslation()
  const toasts = useToasts()
  return (
    <div aria-live="polite" className="pointer-events-none fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4 sm:items-end sm:px-6">
      {toasts.map((x) => {
        const Icon = ICONS[x.tone]
        return (
          <div key={x.id} role={x.tone === 'danger' ? 'alert' : 'status'} className="panel pointer-events-auto flex w-full max-w-sm items-center gap-3 px-4 py-3 animate-page-in motion-reduce:animate-none">
            <Icon aria-hidden className={cn('size-5 shrink-0', TONES[x.tone])} />
            <p className="flex-1 text-[13px] font-medium">{x.message}</p>
            <button type="button" aria-label={t('common.close')} onClick={() => dismissToast(x.id)} className="-m-2 flex size-touch items-center justify-center rounded-lg text-icon transition duration-75 hover:bg-sunken hover:text-muted">
              <XMarkIcon aria-hidden className="size-4" />
            </button>
          </div>
        )
      })}
    </div>
  )
}
