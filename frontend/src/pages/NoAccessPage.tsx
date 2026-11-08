import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { LockKeyhole } from 'lucide-react'
import { buttonClass } from '@/components/ui/Button'

/**
 * A screen the person's role may not open (owner, QA 8 Nov): say so plainly, with the way back,
 * instead of silently jumping to the start page. Shown in place of the screen; nothing of it is loaded.
 */
export function NoAccessPage() {
  const { t } = useTranslation()
  return (
    <div className="mx-auto flex max-w-md flex-col items-center justify-center gap-2 py-16 text-center">
      <span aria-hidden className="mb-2 flex size-14 items-center justify-center rounded-full bg-sunken">
        <LockKeyhole className="size-7 text-icon" />
      </span>
      <h1 className="text-lg font-semibold">{t('noAccess.title')}</h1>
      <p className="text-sm text-muted">{t('noAccess.text')}</p>
      <div className="mt-6 flex gap-4">
        <Link to="/" className={buttonClass()}>
          {t('noAccess.back')}
        </Link>
      </div>
    </div>
  )
}
