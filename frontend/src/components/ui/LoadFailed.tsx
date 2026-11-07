import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { ApiError } from '@/lib/api'
import { Alert } from './Alert'
import { Button } from './Button'

/**
 * Shown when a screen's data could not be loaded, instead of an empty page:
 * "not found" (wrong link, removed, another branch), "not allowed", or "could not load" with Try again.
 */
export function LoadFailed({ error, back, onRetry }: { error: unknown; back?: { to: string; label: string }; onRetry?: () => void }) {
  const { t } = useTranslation()
  const status = error instanceof ApiError ? error.status : 0
  const message = status === 404 ? t('loadFailed.notFound') : status === 403 ? t('loadFailed.forbidden') : t('loadFailed.other')
  return (
    <div className="mx-auto flex w-full max-w-md flex-col gap-3 py-10">
      <Alert>{message}</Alert>
      <div className="flex flex-wrap gap-2">
        {onRetry && status !== 404 && status !== 403 && (
          <Button variant="secondary" onClick={onRetry}>
            {t('loadFailed.retry')}
          </Button>
        )}
        {back && (
          <Link to={back.to} className="flex min-h-touch items-center px-1 font-medium text-accent-text underline">
            {back.label}
          </Link>
        )}
      </div>
    </div>
  )
}
