import { useEffect, useState, type FormEvent } from 'react'
import { Link, useLocation } from 'react-router'
import { useTranslation } from 'react-i18next'
import { KeyRoundIcon } from 'lucide-react'
import { Alert } from '@/components/ui/Alert'
import { Button, buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { ApiError, type CalendarMode, type Me } from '@/lib/api'
import { Select } from '@/components/ui/Select'
import { useMe } from '@/lib/auth'
import { useTelegram, useTelegramLink, useTelegramTest, useTelegramUnlink } from '@/lib/automation'
import { useUpdateEmail, useUpdateProfile } from '@/lib/profile'
import { SecurityCards } from './security/SecurityParts'

export function ProfilePage() {
  const { t } = useTranslation()
  const me = useMe()
  const { hash } = useLocation()
  // "Sign-in security" in the account menu opens this page at the security cards.
  const ready = Boolean(me.data)
  useEffect(() => {
    if (hash === '#security' && ready) document.getElementById('security')?.scrollIntoView({ block: 'start' })
  }, [hash, ready])
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
          <KeyRoundIcon aria-hidden className="size-4 text-icon" />
          {t('topbar.changePassword')}
        </Link>
      </Card>
      {!me.data?.impersonation && (
        <div id="security" className="flex scroll-mt-20 flex-col gap-6">
          <SecurityCards />
        </div>
      )}
      {(me.data?.hotel || me.data?.user.is_super_admin) && !me.data?.impersonation && <TelegramCard />}
    </div>
  )
}

/** Link this account to Telegram so scheduled reports and alerts can be sent there. */
function TelegramCard() {
  const { t } = useTranslation()
  const q = useTelegram()
  const link = useTelegramLink()
  const unlink = useTelegramUnlink()
  const test = useTelegramTest()
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
          <div className="flex flex-wrap gap-2">
            <Button variant="secondary" disabled={test.isPending} onClick={() => test.mutate(undefined)}>
              {test.isPending ? t('telegram.sending') : t('telegram.test')}
            </Button>
            <Button variant="ghost" disabled={unlink.isPending} onClick={() => unlink.mutate(undefined)}>
              {t('telegram.unlink')}
            </Button>
          </div>
          {(test.isSuccess || test.error instanceof ApiError) && (
            <div className="w-full">
              {test.isSuccess ? <Alert tone="ok">{t('telegram.testSent')}</Alert> : <Alert>{(test.error as ApiError).field('telegram') ?? (test.error as ApiError).message}</Alert>}
            </div>
          )}
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
  const [calendar, setCalendar] = useState<string>(me.user.calendar ?? '')
  const error = update.error instanceof ApiError ? update.error : null
  const owner = me.user.is_super_admin && me.platform?.role === 'owner' && !me.impersonation

  function submit(e: FormEvent) {
    e.preventDefault()
    update.mutate({ name, phone: phone || null, ...(me.hotel ? { calendar: (calendar || null) as CalendarMode | null } : {}) })
  }

  return (
    <Card>
      <form onSubmit={submit} noValidate className="flex flex-col gap-4">
        {update.isSuccess && <Alert tone="ok">{t('common.saved')}</Alert>}
        {update.error && !error && <Alert>{t('common.serverUnreachable')}</Alert>}
        <Field label={t('profile.name')} value={name} onChange={(e) => setName(e.target.value)} error={error?.field('name')} />
        <Field
          label={t('login.email')}
          value={me.user.email}
          readOnly
          disabled
          hint={owner ? undefined : t(me.user.is_super_admin ? 'profile.emailHintTeam' : 'profile.emailHint')}
        />
        {owner && <ChangeEmail />}
        <Field label={t('profile.phone')} type="tel" inputMode="tel" value={phone} onChange={(e) => setPhone(e.target.value)} error={error?.field('phone')} />
        {me.hotel && (
          <Select label={t('calendar.mine')} hint={t('calendar.mineHint')} value={calendar} onChange={(e) => setCalendar(e.target.value)} error={error?.field('calendar')}>
            <option value="">{t('calendar.hotelDefault', { choice: t(`calendar.modes.${me.hotel.calendar ?? 'gregorian'}`) })}</option>
            {(['gregorian', 'ethiopian', 'both'] as const).map((m) => <option key={m} value={m}>{t(`calendar.modes.${m}`)}</option>)}
          </Select>
        )}
        <div className="flex justify-end">
          <Button type="submit" disabled={update.isPending || !name}>
            {t('common.save')}
          </Button>
        </div>
      </form>
    </Card>
  )
}

/** Platform Owner: change the sign-in email. Needs the current password; the old address gets a notice. */
function ChangeEmail() {
  const { t } = useTranslation()
  const change = useUpdateEmail()
  const [open, setOpen] = useState(false)
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const error = change.error instanceof ApiError ? change.error : null

  if (!open) {
    return (
      <div className="-mt-2 flex flex-wrap items-center gap-2">
        {change.isSuccess && <Alert tone="ok">{t('profile.emailChanged')}</Alert>}
        <Button type="button" variant="secondary" onClick={() => setOpen(true)}>
          {t('profile.changeEmail')}
        </Button>
      </div>
    )
  }
  return (
    <div className="flex flex-col gap-3 rounded-lg bg-sunken p-3">
      <Field label={t('profile.newEmail')} type="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} error={error?.field('email')} />
      <Field
        label={t('profile.currentPassword')}
        type="password"
        autoComplete="current-password"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        error={error?.field('current_password')}
      />
      <p className="text-xs text-muted">{t('profile.changeEmailHint')}</p>
      <div className="flex justify-end gap-2">
        <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
          {t('common.cancel')}
        </Button>
        <Button
          type="button"
          disabled={change.isPending || !email || !password}
          onClick={() =>
            change.mutate(
              { email: email.trim(), current_password: password },
              {
                onSuccess: () => {
                  setOpen(false)
                  setEmail('')
                  setPassword('')
                },
              },
            )
          }
        >
          {t('profile.saveEmail')}
        </Button>
      </div>
    </div>
  )
}
