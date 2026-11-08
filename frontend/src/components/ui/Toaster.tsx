import { useTranslation } from 'react-i18next'
import { CircleAlert, CircleCheck, Info, X } from 'lucide-react'
import { cn } from '@/lib/cn'
import { dismissToast, useToasts } from '@/lib/toast'

const ICONS = { ok: CircleCheck, info: Info, danger: CircleAlert }
const TONES = { ok: 'text-ok-text', info: 'text-info-text', danger: 'text-danger-text' }

/** Where toasts appear (bottom right; bottom centre on phones), in the sonner look the template uses. Read out by screen readers. */
export function Toaster() {
  const { t } = useTranslation()
  const toasts = useToasts()
  return (
    <div aria-live="polite" className="pointer-events-none fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4 sm:items-end sm:px-6">
      {toasts.map((x) => {
        const Icon = ICONS[x.tone]
        return (
          <div key={x.id} role={x.tone === 'danger' ? 'alert' : 'status'} className="pointer-events-auto flex w-full max-w-sm items-center gap-3 rounded-md border bg-surface p-4 text-text shadow-lg animate-in fade-in-0 slide-in-from-bottom-2 motion-reduce:animate-none">
            <Icon aria-hidden className={cn('size-4 shrink-0', TONES[x.tone])} />
            <p className="flex-1 text-sm font-medium">{x.message}</p>
            <button type="button" aria-label={t('common.close')} onClick={() => dismissToast(x.id)} className="-m-2 flex size-touch items-center justify-center rounded-md text-muted transition-colors hover:bg-sunken hover:text-text">
              <X aria-hidden className="size-4" />
            </button>
          </div>
        )
      })}
    </div>
  )
}
