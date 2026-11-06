import { dateTime } from '@/lib/format'
import { useState, type FormEvent } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { PhoneIcon } from '@heroicons/react/20/solid'
import { Conversation, SupportStatusBadge } from '@/components/support/Conversation'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Select } from '@/components/ui/Select'
import { Tabs } from '@/components/ui/Tabs'
import { useHotels } from '@/lib/admin'
import { ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { useInbox, useInboxMessage, useInboxRequest, useInboxUpdate, useLogCall, type SupportStatus } from '@/lib/platform'
import { TextArea } from '@/pages/help/HelpPage'

const errorOf = (e: unknown) => (e instanceof ApiError ? e.message : e ? String(e) : null)
const when = (v: string | null) => (v ? dateTime(v) : '')

/** Super Admin → Support (area 7): requests from the app, Telegram and phone calls. */
export function SupportInboxPage() {
  const { t } = useTranslation()
  const [status, setStatus] = useState<SupportStatus | 'mine'>('open')
  const [open, setOpen] = useState<number | 'call' | null>(null)
  const inbox = useInbox(status === 'mine' ? { mine: 1 } : { status })
  const rows = inbox.data?.data ?? []
  const counts = inbox.data?.counts

  return (
    <div className="mx-auto flex max-w-7xl flex-col gap-6">
      <PageHeader
        title={t('nav.support')}
        subtitle={t('inbox.subtitle')}
        actions={
          <Button variant="secondary" onClick={() => setOpen('call')}>
            <PhoneIcon aria-hidden className="size-4 text-icon" />
            {t('inbox.logCall')}
          </Button>
        }
      />
      <Tabs
        label={t('nav.support')}
        active={status}
        onChange={(k) => setStatus(k as SupportStatus | 'mine')}
        items={[
          ...(['open', 'waiting', 'solved'] as const).map((s) => ({ key: s, label: `${t(`inbox.status.${s}`)}${counts ? ` (${counts[s]})` : ''}` })),
          { key: 'mine', label: t('inbox.mine') },
        ]}
      />
      <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
        <Card className="flex flex-col p-1.5">
          {inbox.isPending && <p className="p-3 text-[13px] text-muted">{t('common.loading')}</p>}
          {!inbox.isPending && rows.length === 0 && <p className="p-3 text-[13px] text-muted">{t('inbox.empty')}</p>}
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
                    {r.priority === 'urgent' && <Badge tone="danger">{t('inbox.urgent')}</Badge>}
                  </span>
                  <span className="text-xs text-muted">
                    {r.hotel?.name} · {t(`inbox.channels.${r.channel}`)} · {when(r.last_message_at)}
                    {r.assigned_to && ` · ${r.assigned_to.name}`}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        </Card>
        {open === 'call' ? <LogCall onDone={(id) => setOpen(id)} /> : open ? <InboxRequest key={open} id={open} /> : <Card className="text-[13px] text-muted">{t('help.pick')}</Card>}
      </div>
    </div>
  )
}

function InboxRequest({ id }: { id: number }) {
  const { t } = useTranslation()
  const req = useInboxRequest(id)
  const send = useInboxMessage(id)
  const update = useInboxUpdate(id)
  const [body, setBody] = useState('')
  if (req.isPending) return <Card className="text-[13px] text-muted">{t('common.loading')}</Card>
  if (req.isError) return <Alert>{errorOf(req.error)}</Alert>
  const r = req.data.data

  const submit = (kind: 'reply' | 'note', solve = false) => send.mutate({ body: body.trim(), kind, solve }, { onSuccess: () => setBody('') })

  return (
    <Card className="flex flex-col gap-4">
      <div className="flex flex-wrap items-start gap-2">
        <div className="min-w-0 flex-1">
          <h2 className="font-semibold">{r.subject}</h2>
          <p className="text-xs text-muted">
            {r.hotel && (
              <Link to={`/admin/hotels/${r.hotel.id}`} className="font-medium text-accent-text hover:underline">
                {r.hotel.name}
              </Link>
            )}
            {r.hotel_context && ` · ${[r.hotel_context.plan, r.hotel_context.city, r.hotel_context.status].filter(Boolean).join(' · ')}`}
          </p>
          {r.contact && (
            <p className="text-xs text-muted">
              {r.contact.name} · {r.contact.email}
              {r.contact.phone && ` · ${r.contact.phone}`}
            </p>
          )}
        </div>
        <SupportStatusBadge status={r.status} />
      </div>
      <div className="grid gap-3 sm:grid-cols-3">
        <Select label={t('inbox.statusLabel')} value={r.status} onChange={(e) => update.mutate({ status: e.target.value as SupportStatus })}>
          {(['open', 'waiting', 'solved'] as const).map((s) => (
            <option key={s} value={s}>
              {t(`inbox.status.${s}`)}
            </option>
          ))}
        </Select>
        <Select label={t('inbox.priority')} value={r.priority} onChange={(e) => update.mutate({ priority: e.target.value as 'normal' | 'urgent' })}>
          <option value="normal">{t('inbox.normal')}</option>
          <option value="urgent">{t('inbox.urgent')}</option>
        </Select>
        <Select label={t('inbox.assignee')} value={r.assigned_to?.id ?? ''} onChange={(e) => update.mutate({ assigned_to: e.target.value ? Number(e.target.value) : null })}>
          <option value="">{t('inbox.nobody')}</option>
          {r.team?.map((m) => (
            <option key={m.id} value={m.id}>
              {m.name}
            </option>
          ))}
        </Select>
      </div>
      {update.error && <Alert>{errorOf(update.error)}</Alert>}
      <Conversation messages={r.messages} side="hotelms" />
      <div className="flex flex-col gap-2">
        {send.error && <Alert>{errorOf(send.error)}</Alert>}
        <TextArea label={t('inbox.answer')} value={body} onChange={setBody} />
        <div className="flex flex-wrap gap-2">
          <Button disabled={send.isPending || !body.trim()} onClick={() => submit('reply')}>
            {t('inbox.reply')}
          </Button>
          <Button variant="secondary" disabled={send.isPending || !body.trim()} onClick={() => submit('reply', true)}>
            {t('inbox.replySolve')}
          </Button>
          <Button variant="ghost" disabled={send.isPending || !body.trim()} onClick={() => submit('note')}>
            {t('inbox.addNote')}
          </Button>
        </div>
      </div>
    </Card>
  )
}

function LogCall({ onDone }: { onDone: (id: number) => void }) {
  const { t } = useTranslation()
  const log = useLogCall()
  const [search, setSearch] = useState('')
  const hotels = useHotels({ search: search || undefined })
  const [f, setF] = useState({ hotel: '', channel: 'phone' as 'phone' | 'telegram', subject: '', body: '', urgent: false })
  const submit = (e: FormEvent) => {
    e.preventDefault()
    log.mutate(
      { hotel_id: Number(f.hotel), channel: f.channel, subject: f.subject.trim(), body: f.body.trim(), priority: f.urgent ? 'urgent' : 'normal' },
      { onSuccess: (r) => onDone(r.data.id) },
    )
  }
  return (
    <Card>
      <form onSubmit={submit} className="flex flex-col gap-4">
        <h2 className="font-semibold">{t('inbox.logCall')}</h2>
        {log.error && <Alert>{errorOf(log.error)}</Alert>}
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t('switches.findHotel')} type="search" value={search} onChange={(e) => setSearch(e.target.value)} />
          <Select label={t('switches.hotel')} value={f.hotel} required onChange={(e) => setF({ ...f, hotel: e.target.value })}>
            <option value="">—</option>
            {hotels.data?.data.map((h) => (
              <option key={h.id} value={h.id}>
                {h.name}
              </option>
            ))}
          </Select>
          <Select label={t('inbox.channel')} value={f.channel} onChange={(e) => setF({ ...f, channel: e.target.value as 'phone' | 'telegram' })}>
            <option value="phone">{t('inbox.channels.phone')}</option>
            <option value="telegram">{t('inbox.channels.telegram')}</option>
          </Select>
          <Field label={t('help.subject')} value={f.subject} maxLength={150} required onChange={(e) => setF({ ...f, subject: e.target.value })} />
        </div>
        <TextArea label={t('inbox.whatTheySaid')} value={f.body} onChange={(body) => setF({ ...f, body })} />
        <label className="flex min-h-touch items-center gap-2 text-[13px]">
          <input type="checkbox" checked={f.urgent} onChange={(e) => setF({ ...f, urgent: e.target.checked })} className="size-4 accent-accent" />
          {t('help.urgent')}
        </label>
        <div>
          <Button type="submit" disabled={log.isPending || !f.hotel || !f.subject.trim() || !f.body.trim()}>
            {t('common.save')}
          </Button>
        </div>
      </form>
    </Card>
  )
}
