import { useTranslation } from 'react-i18next'
import { setLanguage } from '@/i18n'
import { cn } from '@/lib/cn'

/** English / አማርኛ – before sign-in (remembered on this device); after sign-in use My profile. */
export function LanguageSwitch({ className }: { className?: string }) {
  const { i18n } = useTranslation()
  return (
    <div role="group" aria-label="Language / ቋንቋ" className={cn('inline-flex rounded-md border border-border p-0.5 text-sm', className)}>
      {(['en', 'am'] as const).map((l) => (
        <button
          key={l}
          type="button"
          lang={l}
          aria-pressed={i18n.language === l}
          onClick={() => void setLanguage(l)}
          className={cn('min-h-touch rounded px-3 font-medium transition-colors duration-150', i18n.language === l ? 'bg-sunken text-text' : 'text-muted hover:text-text')}
        >
          {l === 'en' ? 'English' : 'አማርኛ'}
        </button>
      ))}
    </div>
  )
}
