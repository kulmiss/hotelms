import { useEffect, useId, useRef, useState } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { BellIcon, CheckIcon } from '@heroicons/react/24/outline'
import { BellIcon as BellSolid } from '@heroicons/react/24/solid'
import { useMe } from '@/lib/auth'
import { useAckAlert, useAlertEvents, useNotifications, useReadNotifications } from '@/lib/automation'
import { cn } from '@/lib/cn'
import { date } from '@/lib/format'
import { localDate, localTime } from '@/lib/front'

const iconButton =
  'relative flex size-touch shrink-0 items-center justify-center rounded-lg text-icon outline-none transition duration-75 hover:bg-sunken hover:text-muted focus-visible:bg-sunken'

/** Top-bar bell: notifications from automations and smart alerts for this person (polls every minute, no WebSocket). */
export function AlertBell() {
  const { t } = useTranslation()
  const me = useMe()
  const inHotel = Boolean(me.data?.hotel) && !me.data?.user.is_super_admin
  const q = useAlertEvents(inHotel)
  const ack = useAckAlert()
  const notes = useNotifications()
  const read = useReadNotifications()
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)
  const panelId = useId()

  useEffect(() => {
    if (!open) return
    const onDown = (e: PointerEvent) => ref.current && !ref.current.contains(e.target as Node) && setOpen(false)
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)
    document.addEventListener('pointerdown', onDown)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('pointerdown', onDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  const unread = (q.data?.unread ?? 0) + (notes.data?.unread ?? 0)
  const list = notes.data?.data ?? []
  const Icon = open ? BellSolid : BellIcon

  return (
    <div ref={ref} className="relative">
      <button type="button" aria-label={unread ? t('alerts.bellUnread', { count: unread }) : t('topbar.notifications')} aria-expanded={open} aria-controls={panelId} onClick={() => setOpen(!open)} className={iconButton}>
        <Icon aria-hidden className="size-5" />
        {unread > 0 && (
          <span aria-hidden className="absolute top-1.5 right-1.5 flex min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] leading-4 font-semibold text-white">
            {unread > 9 ? '9+' : unread}
          </span>
        )}
      </button>
      {open && (
        <div id={panelId} className="panel absolute right-0 z-30 mt-2 flex w-80 max-w-[calc(100vw-2rem)] flex-col p-1.5">
          <div className="flex items-center justify-between gap-2 px-2 py-1">
            <p className="text-xs font-semibold text-muted">{t('notifications.title')}</p>
            {(notes.data?.unread ?? 0) > 0 && (
              <button type="button" onClick={() => read.mutate(undefined)} className="min-h-8 rounded-md px-2 text-xs font-medium text-accent-text hover:bg-sunken">
                {t('notifications.readAll')}
              </button>
            )}
          </div>
          {list.length === 0 && (q.data?.data.length ?? 0) === 0 && <p className="px-2 py-4 text-center text-[13px] text-muted">{t('alerts.none')}</p>}
          {list.length > 0 && (
            <ul className="flex max-h-80 flex-col overflow-y-auto" aria-label={t('notifications.title')}>
              {list.map((n) => (
                <li key={n.id}>
                  <Link
                    to={n.link ?? '#'}
                    onClick={(e) => {
                      if (!n.link) e.preventDefault()
                      if (!n.read) read.mutate([n.id])
                      if (n.link) setOpen(false)
                    }}
                    className={cn('flex flex-col rounded-md px-2 py-2 hover:bg-sunken', !n.read && 'bg-accent-soft/50')}
                  >
                    <span className={cn('text-[13px]', n.read ? 'text-muted' : 'font-medium', n.urgent && !n.read && 'text-danger-text')}>{n.title}</span>
                    {n.body && <span className="line-clamp-2 text-xs text-muted">{n.body}</span>}
                    <span className="text-[11px] text-muted">
                      {date(localDate(n.created_at))} {localTime(n.created_at)}
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
          {(q.data?.data.length ?? 0) > 0 && <p className="mt-1 border-t border-border px-2 pt-2 pb-1 text-xs font-semibold text-muted">{t('alerts.title')}</p>}
          <ul className="flex max-h-96 flex-col overflow-y-auto">
            {q.data?.data.map((e) => (
              <li key={e.id} className="flex items-start gap-2 rounded-md px-2 py-2 hover:bg-sunken">
                <div className="flex min-w-0 flex-1 flex-col">
                  {e.link ? (
                    <Link to={e.link} onClick={() => setOpen(false)} className="text-[13px] font-medium hover:text-accent-text">
                      {e.title}
                    </Link>
                  ) : (
                    <span className="text-[13px] font-medium">{e.title}</span>
                  )}
                  <span className="text-xs text-muted">
                    {date(localDate(e.fired_at))} {localTime(e.fired_at)}
                  </span>
                </div>
                <button type="button" aria-label={t('alerts.markDone')} title={t('alerts.markDone')} disabled={ack.isPending} onClick={() => ack.mutate(e.id)} className="flex size-8 shrink-0 items-center justify-center rounded-md text-icon hover:bg-surface hover:text-ok-text">
                  <CheckIcon aria-hidden className="size-4" />
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}
