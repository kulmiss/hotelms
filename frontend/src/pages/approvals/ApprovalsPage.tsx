import { dateTime } from '@/lib/format'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { CheckIcon, XMarkIcon } from '@heroicons/react/20/solid'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Select } from '@/components/ui/Select'
import { Tabs } from '@/components/ui/Tabs'
import { ApiError } from '@/lib/api'
import { useApprovals, useApprove, useReject, type ApprovalItem } from '@/lib/automation'
import { useLookups } from '@/lib/front'
import { useCan } from '@/lib/hotel'

const errorOf = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : e ? String(e) : null)
const when = (v: string | null) => (v ? dateTime(v) : '')

/** Approvals (automation.md): price suggestions and refunds wait here for a manager. Nothing happens without a yes. */
export function ApprovalsPage() {
  const { t } = useTranslation()
  const [status, setStatus] = useState('pending')
  const q = useApprovals(status)
  const rows = q.data?.data ?? []
  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-6">
      <PageHeader title={t('nav.approvals')} subtitle={t('approvals.subtitle')} />
      <Tabs label={t('nav.approvals')} active={status} onChange={setStatus} items={['pending', 'approved', 'rejected', 'expired'].map((s) => ({ key: s, label: t(`approvals.status.${s}`) }))} />
      {q.isPending && <p className="text-[13px] text-muted">{t('common.loading')}</p>}
      {!q.isPending && rows.length === 0 && <Card className="text-center text-[13px] text-muted">{t('approvals.none')}</Card>}
      <ul className="flex flex-col gap-3">
        {rows.map((a) => (
          <ApprovalCard key={a.id} a={a} />
        ))}
      </ul>
    </div>
  )
}

function ApprovalCard({ a }: { a: ApprovalItem }) {
  const { t } = useTranslation()
  const can = useCan()
  const approve = useApprove()
  const reject = useReject()
  const lookups = useLookups()
  const [method, setMethod] = useState('')
  const [reference, setReference] = useState('')
  const [note, setNote] = useState('')
  const refund = a.kind === 'refund'
  const allowed = refund ? can('payments.refund') : can('settings.pricing')
  const error = approve.error ?? reject.error

  return (
    <li>
      <Card className="flex flex-col gap-3">
        <div className="flex flex-wrap items-start gap-2">
          <div className="min-w-0 flex-1">
            <p className="text-[13px] font-semibold">{a.title}</p>
            <p className="text-xs text-muted">
              {[a.branch, when(a.created_at)].filter(Boolean).join(' · ')}
              {!refund && ` · ${t('approvals.usual', { usual: Math.round(Number(a.payload.usual ?? 0)) })}`}
            </p>
          </div>
          <Badge tone={refund ? 'warn' : a.kind === 'price_up' ? 'ok' : 'info'}>{t(`approvals.kinds.${a.kind}`)}</Badge>
        </div>
        {a.status !== 'pending' && (
          <p className="text-xs text-muted">
            {t(`approvals.status.${a.status}`)}
            {a.decided_by && ` · ${a.decided_by} · ${when(a.decided_at)}`}
            {a.note && ` · ${a.note}`}
          </p>
        )}
        {a.status === 'pending' && (
          <>
            {!allowed && <Alert tone="info">{refund ? t('approvals.needRefund') : t('approvals.needPricing')}</Alert>}
            {refund && allowed && (
              <div className="grid gap-3 sm:grid-cols-2">
                <Select label={t('approvals.method')} value={method} onChange={(e) => setMethod(e.target.value)}>
                  <option value="">—</option>
                  {lookups.data?.payment_methods.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.name}
                    </option>
                  ))}
                </Select>
                <Field label={t('approvals.reference')} value={reference} maxLength={100} onChange={(e) => setReference(e.target.value)} />
              </div>
            )}
            <Field label={t('approvals.note')} value={note} maxLength={200} onChange={(e) => setNote(e.target.value)} />
            {error && <Alert>{errorOf(error)}</Alert>}
            <div className="flex flex-wrap gap-2">
              <Button disabled={!allowed || approve.isPending || (refund && !method)} onClick={() => approve.mutate({ id: a.id, payment_method_id: refund ? Number(method) : undefined, reference: reference || undefined })}>
                <CheckIcon aria-hidden className="size-4" />
                {refund ? t('approvals.giveRefund') : t('approvals.approve')}
              </Button>
              <Button variant="secondary" disabled={reject.isPending} onClick={() => reject.mutate({ id: a.id, note: note || undefined })}>
                <XMarkIcon aria-hidden className="size-4 text-icon" />
                {t('approvals.reject')}
              </Button>
            </div>
          </>
        )}
      </Card>
    </li>
  )
}
