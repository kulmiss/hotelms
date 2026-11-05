import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'

export function NotFoundPage() {
  const { t } = useTranslation()
  return (
    <div className="mx-auto flex max-w-md flex-col items-center gap-4 py-16 text-center">
      <h1 className="text-2xl font-bold">{t('notFound.title')}</h1>
      <Link to="/" className="flex min-h-touch items-center font-semibold text-accent-text underline">
        {t('notFound.back')}
      </Link>
    </div>
  )
}
