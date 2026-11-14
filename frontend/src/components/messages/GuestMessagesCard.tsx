import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { MailIcon, MessageCircleIcon, MessageSquareIcon, SendIcon } from 'lucide-react'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Select } from '@/components/ui/Select'
import { api, ApiError, post, put } from '@/lib/api'
import { dateTime } from '@/lib/format'
import { useCan } from '@/lib/hotel'

type Log = { id: number; key: string; channel: 'email' | 'sms' | 'whatsapp' | 'sms_app'; language: string; to: string; status: 'sent' | 'failed' | 'opened' | 'skipped'; error: string | null; by: string | null; at: string }
type Data = {
  data: Log[]
  messages: { key: string; allowed: boolean }[]
  channels: { email: boolean; phone: boolean; sms: boolean }
  opt_out: boolean
  feedback_consent: boolean
  language: 'en' | 'am' | null
}

/**
 * A booking's guest messages (owner, 14 Nov 2026): preview, send now (email, SMS) or by a tap (WhatsApp / SMS app open
 * with the text ready – free), the guest's yes to the thank-you, and the log.
 */
export function GuestMessagesCard({ reservationId }: { reservationId: number }) {
  const { t } = useTranslation()
  const can = useCan()
  const qc = useQueryClient()
  const key = ['reservation-messages', reservationId]
  const q = useQuery({ queryKey: key, queryFn: () => api<Data>(`/reservations/${reservationId}/messages`) })
  const [msg, setMsg] = useState('')
  const [lang, setLang] = useState<'' | 'en' | 'am'>('')
  const data = q.data
  const current = msg || data?.messages.find((m) => m.allowed)?.key || 'confirmation'
  const preview = useQuery({
    queryKey: ['reservation-message-preview', reservationId, current, lang],
    queryFn: () => api<{ data: { subject: string; body: string; language: string } }>(`/reservations/${reservationId}/messages/preview?key=${current}${lang ? `&language=${lang}` : ''}`),
    enabled: !!data,
  })
  const send = useMutation({
    mutationFn: (channel: Log['channel']) => post<{ data: { status: string; link: string | null; error: string | null } }>(`/reservations/${reservationId}/messages`, { key: current, channel, language: lang || null }),
    onSuccess: (res) => {
      if (res.data.link) window.open(res.data.link, '_blank', 'noopener')
      void qc.invalidateQueries({ queryKey: key })
    },
  })
  const consent = useMutation({
    mutationFn: (v: boolean) => put(`/reservations/${reservationId}/feedback-consent`, { feedback_consent: v }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: key }),
  })
  if (!data) return null
  const allowed = data.messages.find((m) => m.key === current)?.allowed ?? false
  const mayEdit = can('reservations.edit')
  const err = send.error instanceof ApiError ? (Object.values(send.error.errors)[0]?.[0] ?? send.error.message) : null

  return (
    <Card className="flex flex-col gap-3">
      <h2 className="text-sm font-semibold">{t('messages.title')}</h2>
      {data.opt_out && <Alert tone="info">{t('messages.guestOptedOut')}</Alert>}
      {mayEdit && (
        <label className="flex min-h-touch items-start gap-2 text-sm">
          <input type="checkbox" className="mt-0.5 size-4" checked={data.feedback_consent} disabled={consent.isPending} onChange={(e) => consent.mutate(e.target.checked)} />
          <span className="flex flex-col">
            {t('messages.consent')}
            <span className="text-xs text-muted">{t('messages.consentHint')}</span>
          </span>
        </label>
      )}
      {mayEdit && !data.opt_out && (
        <>
          <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_10rem]">
            <Select label={t('messages.which')} value={current} onChange={(e) => setMsg(e.target.value)}>
              {data.messages.map((m) => (
                <option key={m.key} value={m.key}>
                  {t(`messages.keys.${m.key}`)}
                  {!m.allowed ? ` (${t('messages.needsYes')})` : ''}
                </option>
              ))}
            </Select>
            <Select label={t('messages.language')} value={lang} onChange={(e) => setLang(e.target.value as '' | 'en' | 'am')}>
              <option value="">{t('messages.guestDefault')}</option>
              <option value="en">English</option>
              <option value="am">አማርኛ</option>
            </Select>
          </div>
          {preview.data && (
            <div className="rounded-lg bg-sunken p-3 text-sm" lang={preview.data.data.language}>
              <p className="font-medium">{preview.data.data.subject}</p>
              <p className="mt-1 whitespace-pre-line text-muted">{preview.data.data.body}</p>
            </div>
          )}
          <div className="flex flex-wrap gap-2">
            <Button variant="secondary" disabled={!allowed || !data.channels.phone || send.isPending} onClick={() => send.mutate('whatsapp')}>
              <MessageCircleIcon aria-hidden className="size-4" />
              {t('messages.channels.whatsapp')}
            </Button>
            <Button variant="secondary" disabled={!allowed || !data.channels.phone || send.isPending} onClick={() => send.mutate('sms_app')}>
              <MessageSquareIcon aria-hidden className="size-4" />
              {t('messages.channels.sms_app')}
            </Button>
            <Button variant="secondary" disabled={!allowed || !data.channels.email || send.isPending} onClick={() => send.mutate('email')}>
              <MailIcon aria-hidden className="size-4" />
              {t('messages.channels.email')}
            </Button>
            {data.channels.sms && (
              <Button variant="secondary" disabled={!allowed || send.isPending} onClick={() => send.mutate('sms')}>
                <SendIcon aria-hidden className="size-4" />
                {t('messages.channels.sms')}
              </Button>
            )}
          </div>
          <p className="text-xs text-muted">{t('messages.tapHint')}</p>
          {err && <Alert>{err}</Alert>}
        </>
      )}
      {data.data.length === 0 ? (
        <p className="text-sm text-muted">{t('messages.none')}</p>
      ) : (
        <ul className="flex flex-col divide-y divide-border text-sm">
          {data.data.map((m) => (
            <li key={m.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <span className="flex flex-col">
                <span className="font-medium">
                  {t(`messages.keys.${m.key}`)} · {t(`messages.channels.${m.channel}`)}
                </span>
                <span className="text-xs text-muted">
                  {dateTime(m.at)} · {m.to} · {m.by ?? t('messages.automatic')}
                  {m.error && ` · ${m.error}`}
                </span>
              </span>
              <Badge tone={m.status === 'sent' ? 'ok' : m.status === 'failed' ? 'danger' : 'grey'}>{t(`messages.status.${m.status}`)}</Badge>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
