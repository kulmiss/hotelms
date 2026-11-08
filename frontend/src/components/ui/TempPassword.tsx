import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Check, Copy } from 'lucide-react'
import { Button } from './Button'

/** Shows a new temporary password once, with a copy button. */
export function TempPassword({ password, emailSent, email }: { password: string; emailSent: boolean; email: string }) {
  const { t } = useTranslation()
  const [copied, setCopied] = useState(false)
  return (
    <div className="flex flex-col gap-2 rounded-lg border bg-sunken p-4">
      <span className="text-xs text-muted">{t('admin.hotel.newPasswordReady')}</span>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="font-mono text-lg font-semibold break-all">{password}</span>
        <Button
          variant="secondary"
          onClick={() => void navigator.clipboard?.writeText(password).then(() => setCopied(true))}
        >
          {copied ? <Check aria-hidden /> : <Copy aria-hidden />}
          {copied ? t('admin.hotel.copied') : t('admin.hotel.copy')}
        </Button>
      </div>
      <span className="text-xs text-muted">
        {emailSent ? t('admin.hotel.resetEmailSent', { email }) : t('admin.hotel.resetEmailNotSent')}
      </span>
    </div>
  )
}
