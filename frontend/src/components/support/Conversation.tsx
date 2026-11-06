import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/cn'
import type { SupportMessage } from '@/lib/platform'

const when = (v: string | null) => (v ? new Date(v).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '')

/** The messages of one support request, oldest first. `side` says whose messages sit on the right. */
export function Conversation({ messages, side }: { messages: SupportMessage[]; side: 'hotel' | 'hotelms' }) {
  const { t } = useTranslation()
  return (
    <ol className="flex flex-col gap-3" aria-label={t('help.conversation')}>
      {messages.map((m) => {
        if (m.kind === 'system')
          return (
            <li key={m.id} className="text-center text-xs text-muted">
              {m.body} · {when(m.created_at)}
            </li>
          )
        const ours = side === 'hotel' ? m.kind === 'message' : m.kind !== 'message'
        const author = m.kind === 'reply' && side === 'hotel' ? t('help.hotelms') : (m.author ?? t('help.hotelms'))
        return (
          <li key={m.id} className={cn('flex flex-col gap-1', ours ? 'items-end' : 'items-start')}>
            <span className="text-xs text-muted">
              {author} · {when(m.created_at)}
              {m.kind === 'note' && ` · ${t('inbox.internalNote')}`}
            </span>
            <p
              className={cn(
                'max-w-[85%] rounded-xl px-3 py-2 text-[13px] whitespace-pre-wrap break-words',
                m.kind === 'note' ? 'bg-warn-bg text-warn-text ring-1 ring-warn-text/20' : ours ? 'bg-accent-soft text-text' : 'bg-sunken text-text',
              )}
            >
              {m.body}
            </p>
          </li>
        )
      })}
    </ol>
  )
}

export function SupportStatusBadge({ status }: { status: 'open' | 'waiting' | 'solved' }) {
  const { t } = useTranslation()
  const tone = status === 'solved' ? 'bg-ok-bg text-ok-text ring-ok-text/20' : status === 'waiting' ? 'bg-warn-bg text-warn-text ring-warn-text/20' : 'bg-info-bg text-info-text ring-info-text/20'
  return <span className={cn('inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset', tone)}>{t(`inbox.status.${status}`)}</span>
}
