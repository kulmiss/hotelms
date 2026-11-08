import { useTranslation } from 'react-i18next'

/** Thin bar at the very top while a screen is downloading (template navigation progress: 2px, grey). */
export function ProgressBar() {
  const { t } = useTranslation()
  return (
    <div role="progressbar" aria-label={t('common.loading')} className="fixed inset-x-0 top-0 z-[100] h-0.5 overflow-hidden">
      <div className="h-full w-full origin-left animate-progress bg-muted motion-reduce:animate-none" />
    </div>
  )
}
