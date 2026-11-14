import { useEffect } from 'react'
import { useRouteError } from 'react-router'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/Button'

const RELOADED = 'hms-reloaded-for-update'

/** A new version was put online while this tab was open: the old screen files are gone. */
export function isUpdateError(error: unknown): boolean {
  const text = error instanceof Error ? `${error.name} ${error.message}` : String(error ?? '')
  return /dynamically imported module|Importing a module script failed|ChunkLoadError|Loading chunk|Failed to load module script/i.test(text)
}

/**
 * Shown instead of a blank page when a screen breaks. After an update, the app reloads once by itself
 * (to get the new files); otherwise the person can reload or go back to the start.
 */
export function RouteError() {
  const { t } = useTranslation()
  const error = useRouteError()
  const update = isUpdateError(error)

  useEffect(() => {
    if (update && !sessionStorage.getItem(RELOADED)) {
      sessionStorage.setItem(RELOADED, '1')
      window.location.reload()
    }
  }, [update])

  if (import.meta.env.DEV) console.error(error)

  return (
    <div className="flex min-h-full items-center justify-center bg-bg p-6">
      {/* The template's error page: a big title, a short line, the way out. */}
      <div className="flex w-full max-w-md flex-col items-center gap-2 text-center">
        {!update && (
          <span aria-hidden className="text-[7rem] leading-tight font-bold">
            500
          </span>
        )}
        <h1 className="font-medium">{update ? t('routeError.updateTitle') : t('routeError.title')}</h1>
        <p className="text-sm text-muted">{update ? t('routeError.updateText') : t('routeError.text')}</p>
        <div className="mt-6 flex flex-wrap justify-center gap-4">
          <Button
            onClick={() => {
              sessionStorage.removeItem(RELOADED)
              window.location.reload()
            }}
          >
            {t('routeError.reload')}
          </Button>
          <Button variant="secondary" onClick={() => window.location.assign('/dashboard')}>
            {t('routeError.home')}
          </Button>
        </div>
      </div>
    </div>
  )
}
