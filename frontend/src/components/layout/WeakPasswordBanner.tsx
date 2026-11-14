import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { X } from 'lucide-react'
import { useMe } from '@/lib/auth'

/**
 * The password used at sign-in is weaker than the rules from 14 Nov 2026 (10+ characters, not a common
 * password). It still works; this note asks the person to change it. Closed until the next sign-in.
 */
export function WeakPasswordBanner() {
  const { t } = useTranslation()
  const me = useMe()
  const [closed, setClosed] = useState(false)
  if (closed || !me.data?.security?.password_weak) return null
  return (
    <div role="status" className="flex items-center gap-x-2 bg-warn-bg px-4 py-2 text-sm text-warn-text">
      <span className="flex-1">
        {t('weakPassword.message')}{' '}
        <Link to="/profile" className="tap-link font-medium underline">
          {t('weakPassword.change')}
        </Link>
      </span>
      <button
        type="button"
        aria-label={t('common.close')}
        onClick={() => setClosed(true)}
        className="flex size-8 items-center justify-center rounded-md hover:bg-black/5 pointer-coarse:size-11 dark:hover:bg-white/10"
      >
        <X aria-hidden className="size-4" />
      </button>
    </div>
  )
}
