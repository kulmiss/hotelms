import { useState, type FormEvent } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { KeyIcon } from '@heroicons/react/24/outline'
import { Alert } from '@/components/ui/Alert'
import { Button, buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { ApiError, type Me } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { useTelegram, useTelegramLink, useTelegramUnlink } from '@/lib/automation'
import { useUpdateProfile } from '@/lib/profile'

export function ProfilePage() {
  const { t } = useTranslation()
  const me = useMe()
  return (
    <div className="mx-auto flex max-w-2xl flex-col gap-6">
      <PageHeader title={t('profile.title')} subtitle={t('profile.subtitle')} />
      {me.data && <ProfileForm me={me.data} />}
      <Card className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h2 className="font-semibold">{t('profile.passwordTitle')}</h2>
          <p className="text-sm text-muted">{t('profile.passwordBody')}</p>
        </div>
        <Link
          to="/change-password"
          className={buttonClass('secondary')}
        >
          <KeyIcon aria-hidden className="size-4 text-icon" />
          {t('topbar.changePassword')}
        </Link>
      </Card>
      {me.data?.hotel && !me.data.impersonation && <TelegramCard />}
    </div>
  )
}

/** Link this account to Telegram so scheduled reports and alerts can be sent there. */
function TelegramCard() {
  const { t } = useTranslation()
  const q = useTelegram()
  const link = useTelegramLink()
  const unlink = useTelegramUnlink()
  const code = link.data?.data
  if (!q.data) return null
  return (
    <Card className="flex flex-col gap-3">
      <div className="flex flex-col gap-1">
        <h2 className="font-semibold">{t('telegram.title')}</h2>
        <p className="text-sm text-muted">{q.data.enabled ? t('telegram.body') : t('telegram.notSetUp')}</p>
      </div>
      {q.data.linked ? (
        <div className="flex flex-wrap items-center justify-between gap-3">
          <span className="text-sm">{t('telegram.linkedAs', { name: q.data.chat_name ?? '' })}</span>
          <Button variant="secondary" disabled={unlink.isPending} onClick={() => unlink.mutate(undefined)}>
            {t('telegram.unlink')}
          </Button>
        </div>
      ) : (
        q.data.enabled && (
          <div className="flex flex-col gap-3">
            {code ? (
              <div className="flex flex-col gap-2 rounded-lg bg-sunken p-3 text-sm">
                <span>{t('telegram.step', { bot: code.bot ?? '' })}</span>
                <span className="font-mono text-lg font-semibold tracking-wider">/start {code.code}</span>
                {code.url && (
                  <a href={code.url} target="_blank" rel="noreferrer" className={buttonClass('primary')}>
                    {t('telegram.open')}
                  </a>
                )}
                <span className="text-xs text-muted">{t('telegram.wait')}</span>
                <Button variant="ghost" onClick={() => void q.refetch()}>
                  {t('telegram.check')}
                </Button>
              </div>
            ) : (
              <div>
                <Button disabled={link.isPending} onClick={() => link.mutate(undefined)}>
                  {t('telegram.link')}
                </Button>
              </div>
            )}
            {link.error && <Alert>{link.error instanceof ApiError ? (Object.values(link.error.errors)[0]?.[0] ?? link.error.message) : t('common.serverUnreachable')}</Alert>}
          </div>
        )
      )}
    </Card>
  )
}

function ProfileForm({ me }: { me: Me }) {
  const { t } = useTranslation()
  const update = useUpdateProfile()
  const [name, setName] = useState(me.user.name)
  const [phone, setPhone] = useState(me.user.phone ?? '')
  const error = update.error instanceof ApiError ? update.error : null

  function submit(e: FormEvent) {
    e.preventDefault()
    update.mutate({ name, phone: phone || null })
  }

  return (
    <Card>
      <form onSubmit={submit} noValidate className="flex flex-col gap-4">
        {update.isSuccess && <Alert tone="ok">{t('common.saved')}</Alert>}
        {update.error && !error && <Alert>{t('common.serverUnreachable')}</Alert>}
        <Field label={t('profile.name')} value={name} onChange={(e) => setName(e.target.value)} error={error?.field('name')} />
        <Field label={t('login.email')} value={me.user.email} readOnly disabled hint={t('profile.emailHint')} />
        <Field label={t('profile.phone')} type="tel" inputMode="tel" value={phone} onChange={(e) => setPhone(e.target.value)} error={error?.field('phone')} />
        <div className="flex justify-end">
          <Button type="submit" disabled={update.isPending || !name}>
            {t('common.save')}
          </Button>
        </div>
      </form>
    </Card>
  )
}
