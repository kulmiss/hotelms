import { useState } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Tabs } from '@/components/ui/Tabs'
import { useDecideSignup, useSignups, type Signup } from '@/lib/admin'
import { ApiError } from '@/lib/api'
import { dateTime } from '@/lib/format'

const STATUSES = ['pending', 'unverified', 'approved', 'rejected'] as const

/** Super Admin → Pending hotels: hotels that registered themselves. Approve, or refuse with a reason (both are emailed). */
export function SignupsPage() {
  const { t } = useTranslation()
  const [status, setStatus] = useState<(typeof STATUSES)[number]>('pending')
  const q = useSignups(status)
  const counts = q.data?.counts ?? {}
  return (
    <div className="flex flex-col gap-6">
      <PageHeader title={t('nav.signups')} subtitle={t('admin.signups.subtitle')} />
      {q.data && q.data.mode === 'off' && <Alert tone="info">{t('admin.signups.modeOff')}</Alert>}
      <Tabs
        label={t('nav.signups')}
        active={status}
        onChange={(k) => setStatus(k as typeof status)}
        items={STATUSES.map((k) => ({ key: k, label: `${t(`admin.signups.status.${k}`)}${counts[k] ? ` (${counts[k]})` : ''}` }))}
      />
      {q.data?.data.length === 0 && <p className="text-sm text-muted">{t('admin.signups.empty')}</p>}
      <div className="grid gap-4 lg:grid-cols-2">
        {q.data?.data.map((s) => <SignupCard key={s.id} s={s} />)}
      </div>
    </div>
  )
}

function SignupCard({ s }: { s: Signup }) {
  const { t } = useTranslation()
  const decide = useDecideSignup()
  const [refusing, setRefusing] = useState(false)
  const [reason, setReason] = useState('')
  const error = decide.error instanceof ApiError ? decide.error : null
  const canDecide = s.signup_status === 'pending' || s.signup_status === 'unverified'
  return (
    <Card className="flex flex-col gap-3">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <h2 className="truncate font-semibold">
            <Link to={`/admin/hotels/${s.id}`} className="hover:underline">{s.name}</Link>
          </h2>
          <p className="text-sm text-muted">{[s.city, s.rooms ? t('admin.signups.rooms', { count: s.rooms }) : null].filter(Boolean).join(' · ')}</p>
        </div>
        <Badge tone={s.email_confirmed ? 'ok' : 'grey'}>{s.email_confirmed ? t('admin.signups.confirmed') : t('admin.signups.notConfirmed')}</Badge>
      </div>
      <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
        <dt className="text-muted">{t('admin.signups.owner')}</dt>
        <dd>{s.owner_name}</dd>
        <dt className="text-muted">{t('login.email')}</dt>
        <dd className="break-all">{s.email}</dd>
        <dt className="text-muted">{t('signup.phone')}</dt>
        <dd>{s.phone}</dd>
        <dt className="text-muted">{t('admin.signups.registered')}</dt>
        <dd>{dateTime(s.created_at)}</dd>
        {s.reason && (
          <>
            <dt className="text-muted">{t('admin.signups.reason')}</dt>
            <dd>{s.reason}</dd>
          </>
        )}
      </dl>
      {error && <Alert>{error.field('reason') ?? error.field('hotel') ?? error.message}</Alert>}
      {decide.isSuccess && <Alert tone="ok">{decide.data.message}</Alert>}
      {canDecide && !decide.isSuccess && (
        refusing ? (
          <div className="flex flex-col gap-2">
            <Field label={t('admin.signups.reason')} value={reason} hint={t('admin.signups.reasonHint')} onChange={(e) => setReason(e.target.value)} />
            <div className="flex flex-wrap gap-2">
              <Button variant="danger" disabled={decide.isPending || reason.trim().length < 3} onClick={() => decide.mutate({ id: s.id, approve: false, reason })}>
                {t('admin.signups.refuse')}
              </Button>
              <Button variant="secondary" onClick={() => setRefusing(false)}>{t('common.cancel')}</Button>
            </div>
          </div>
        ) : (
          <div className="flex flex-wrap gap-2">
            {s.signup_status === 'pending' && (
              <Button disabled={decide.isPending} onClick={() => decide.mutate({ id: s.id, approve: true })}>
                {t('admin.signups.approve')}
              </Button>
            )}
            <Button variant="secondary" onClick={() => setRefusing(true)}>{t('admin.signups.refuse')}</Button>
          </div>
        )
      )}
    </Card>
  )
}
