import { dateTime } from '@/lib/format'
import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'
import { PlusIcon } from '@heroicons/react/20/solid'
import { ChevronDownIcon } from '@heroicons/react/24/outline'
import { Conversation, SupportStatusBadge } from '@/components/support/Conversation'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { ApiError } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { useBrand } from '@/lib/brand'
import { guideFor } from '@/lib/guides'
import { useCan } from '@/lib/hotel'
import { Tabs } from '@/components/ui/Tabs'
import { cn } from '@/lib/cn'
import { useAnswerSupport, useAskSupport, useMySupport, useMySupportRequest } from '@/lib/platform'

const errorOf = (e: unknown) => (e instanceof ApiError ? e.message : e ? String(e) : null)

/** Help: "How to use" guides for the person's own work, and "Ask us" (questions to HotelMS, answered in Super Admin → Support). */
export function HelpPage() {
  const { t } = useTranslation()
  const brand = useBrand()
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') === 'ask' ? 'ask' : 'guide'

  return (
    <div className="mx-auto flex max-w-5xl flex-col gap-6">
      <PageHeader title={t('help.title')} subtitle={t('help.subtitle', { name: brand.name })} />
      <div className="max-w-xs">
        <Tabs label={t('help.title')} active={tab} onChange={(k) => setParams(k === 'ask' ? { tab: 'ask' } : {})} items={(['guide', 'ask'] as const).map((k) => ({ key: k, label: t(`guide.tabs.${k}`) }))} />
      </div>
      {tab === 'guide' ? <Guides /> : <AskUs />}
    </div>
  )
}

/** The guides this person can use (by permission and the hotel's modules), searchable. */
function Guides() {
  const { t } = useTranslation()
  const can = useCan()
  const me = useMe()
  const [q, setQ] = useState('')
  const steps = (key: string) => t(`guide.topics.${key}.steps`, { returnObjects: true }) as unknown as string[]
  const term = q.trim().toLowerCase()
  const sections = guideFor(can, me.data?.hotel?.modules ?? [])
    .map((s) => ({ ...s, topics: s.topics.filter((tp) => !term || [t(`guide.topics.${tp.key}.title`), ...steps(tp.key)].join(' ').toLowerCase().includes(term)) }))
    .filter((s) => s.topics.length > 0)

  return (
    <div className="flex flex-col gap-5">
      <p className="max-w-prose text-[13px] text-muted">{t('guide.intro')}</p>
      <div className="max-w-sm">
        <Field label={t('guide.searchLabel')} type="search" value={q} placeholder={t('guide.searchPlaceholder')} onChange={(e) => setQ(e.target.value)} />
      </div>
      {sections.length === 0 && <Card className="text-[13px] text-muted">{t('guide.none')}</Card>}
      {sections.map((s) => (
        <section key={s.key} className="flex flex-col gap-2">
          <h2 className="text-sm font-semibold">{t(`guide.sections.${s.key}`)}</h2>
          <div className="panel divide-y divide-border p-0">
            {s.topics.map((tp) => (
              <details key={tp.key} open={Boolean(term)} className="group">
                <summary className="flex min-h-touch cursor-pointer list-none items-center justify-between gap-3 px-4 py-2 text-[13px] font-medium transition duration-75 hover:bg-sunken [&::-webkit-details-marker]:hidden">
                  {t(`guide.topics.${tp.key}.title`)}
                  <ChevronDownIcon aria-hidden className="size-4 shrink-0 text-icon transition duration-150 group-open:rotate-180" />
                </summary>
                <ol className="flex list-decimal flex-col gap-1.5 px-4 pt-1 pb-4 pl-9 text-[13px] leading-relaxed">
                  {steps(tp.key).map((step, i) => (
                    <li key={i}>{step}</li>
                  ))}
                </ol>
              </details>
            ))}
          </div>
        </section>
      ))}
    </div>
  )
}

function AskUs() {
  const { t } = useTranslation()
  const brand = useBrand()
  const list = useMySupport()
  const [open, setOpen] = useState<number | 'new' | null>(null)
  const rows = list.data?.data ?? []

  return (
    <div className="flex flex-col gap-4">
      <div>
        <Button onClick={() => setOpen('new')}>
          <PlusIcon aria-hidden className="size-4" />
          {t('help.new')}
        </Button>
      </div>
      {(brand.raw.support_phone || brand.raw.support_email) && (
        <p className="text-[13px] text-muted">
          {t('help.contact')} {[brand.raw.support_phone, brand.raw.support_email].filter(Boolean).join(' · ')}
        </p>
      )}
      <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
        <Card className="flex flex-col p-1.5">
          {list.isPending && <p className="p-3 text-[13px] text-muted">{t('common.loading')}</p>}
          {!list.isPending && rows.length === 0 && <p className="p-3 text-[13px] text-muted">{t('help.none')}</p>}
          <ul className="flex flex-col">
            {rows.map((r) => (
              <li key={r.id}>
                <button
                  type="button"
                  onClick={() => setOpen(r.id)}
                  aria-current={open === r.id}
                  className={cn('flex min-h-touch w-full flex-col items-start gap-1 rounded-lg px-3 py-2 text-left transition duration-75 hover:bg-sunken', open === r.id && 'bg-sunken')}
                >
                  <span className="flex w-full items-center justify-between gap-2">
                    <span className="truncate text-[13px] font-medium">{r.subject}</span>
                    <SupportStatusBadge status={r.status} />
                  </span>
                  <span className="text-xs text-muted">
                    {r.from && `${r.from} · `}
                    {r.last_message_at && dateTime(r.last_message_at)}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        </Card>
        {open === 'new' ? <NewRequest onDone={(id) => setOpen(id)} /> : open ? <RequestView id={open} /> : <Card className="text-[13px] text-muted">{t('help.pick')}</Card>}
      </div>
    </div>
  )
}

function NewRequest({ onDone }: { onDone: (id: number) => void }) {
  const { t } = useTranslation()
  const ask = useAskSupport()
  const [f, setF] = useState({ subject: '', body: '', urgent: false })
  const submit = (e: FormEvent) => {
    e.preventDefault()
    ask.mutate({ subject: f.subject.trim(), body: f.body.trim(), priority: f.urgent ? 'urgent' : 'normal' }, { onSuccess: (r) => onDone(r.data.id) })
  }
  return (
    <Card>
      <form onSubmit={submit} className="flex flex-col gap-4">
        <h2 className="font-semibold">{t('help.new')}</h2>
        {ask.error && <Alert>{errorOf(ask.error)}</Alert>}
        <Field label={t('help.subject')} value={f.subject} maxLength={150} required onChange={(e) => setF({ ...f, subject: e.target.value })} />
        <TextArea label={t('help.message')} value={f.body} onChange={(body) => setF({ ...f, body })} />
        <label className="flex min-h-touch items-center gap-2 text-[13px]">
          <input type="checkbox" checked={f.urgent} onChange={(e) => setF({ ...f, urgent: e.target.checked })} className="size-4 accent-accent" />
          {t('help.urgent')}
        </label>
        <div>
          <Button type="submit" disabled={ask.isPending || !f.subject.trim() || !f.body.trim()}>
            {ask.isPending ? t('common.saving') : t('help.send')}
          </Button>
        </div>
      </form>
    </Card>
  )
}

function RequestView({ id }: { id: number }) {
  const { t } = useTranslation()
  const req = useMySupportRequest(id)
  const answer = useAnswerSupport(id)
  const [body, setBody] = useState('')
  if (req.isPending) return <Card className="text-[13px] text-muted">{t('common.loading')}</Card>
  if (req.isError) return <Alert>{errorOf(req.error)}</Alert>
  const r = req.data.data
  return (
    <Card className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2">
        <h2 className="min-w-0 flex-1 font-semibold">{r.subject}</h2>
        {r.priority === 'urgent' && <Badge tone="danger">{t('inbox.urgent')}</Badge>}
        <SupportStatusBadge status={r.status} />
      </div>
      <Conversation messages={r.messages} side="hotel" />
      <form
        onSubmit={(e) => {
          e.preventDefault()
          answer.mutate(body.trim(), { onSuccess: () => setBody('') })
        }}
        className="flex flex-col gap-2"
      >
        {answer.error && <Alert>{errorOf(answer.error)}</Alert>}
        <TextArea label={t('help.answer')} value={body} onChange={setBody} />
        <div>
          <Button type="submit" disabled={answer.isPending || !body.trim()}>
            {t('help.send')}
          </Button>
        </div>
      </form>
    </Card>
  )
}

export function TextArea({ label, value, onChange, rows = 4 }: { label: string; value: string; onChange: (v: string) => void; rows?: number }) {
  return (
    <label className="flex flex-col gap-1.5">
      <span className="text-[13px] font-medium text-text">{label}</span>
      <textarea
        value={value}
        rows={rows}
        maxLength={5000}
        onChange={(e) => onChange(e.target.value)}
        className="rounded-lg border-0 bg-surface px-3 py-2 text-base text-text shadow-sm ring-1 ring-ring-strong transition duration-75 placeholder:text-icon focus:ring-2 focus:ring-accent focus:outline-none sm:text-sm"
      />
    </label>
  )
}
