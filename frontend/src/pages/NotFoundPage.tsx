import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { buttonClass } from '@/components/ui/Button'

/** The template's 404 page: a big number, a short line and the way back. */
export function NotFoundPage() {
  const { t } = useTranslation()
  return (
    <div className="mx-auto flex max-w-md flex-col items-center justify-center gap-2 py-16 text-center">
      <span aria-hidden className="text-[7rem] leading-tight font-bold">
        404
      </span>
      <h1 className="font-medium">{t('notFound.title')}</h1>
      <div className="mt-6 flex gap-4">
        <Link to="/dashboard" className={buttonClass()}>
          {t('notFound.back')}
        </Link>
      </div>
    </div>
  )
}
