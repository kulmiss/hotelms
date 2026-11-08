import { useState } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { Bell, Check } from 'lucide-react'
import { Button } from '@/components/shadcn/button'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/shadcn/popover'
import { useMe } from '@/lib/auth'
import { useAckAlert, useAlertEvents, useNotifications, useReadNotifications } from '@/lib/automation'
import { cn } from '@/lib/cn'
import { date } from '@/lib/format'
import { localDate, localTime } from '@/lib/front'

/** Header bell: notifications from automations and smart alerts for this person (polls every minute, no WebSocket). */
export function AlertBell() {
  const { t } = useTranslation()
  const me = useMe()
  const inHotel = Boolean(me.data?.hotel) && !me.data?.user.is_super_admin
  const q = useAlertEvents(inHotel)
  const ack = useAckAlert()
  const notes = useNotifications()
  const read = useReadNotifications()
  const [open, setOpen] = useState(false)

  const unread = (q.data?.unread ?? 0) + (notes.data?.unread ?? 0)
  const list = notes.data?.data ?? []

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button variant="ghost" size="icon" aria-label={unread ? t('alerts.bellUnread', { count: unread }) : t('topbar.notifications')} className="relative scale-95 rounded-full pointer-coarse:scale-100">
          <Bell aria-hidden className="size-[1.2rem]" />
          {unread > 0 && (
            <span aria-hidden className="absolute top-1 right-1 flex min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] leading-4 font-semibold text-white">
              {unread > 9 ? '9+' : unread}
            </span>
          )}
        </Button>
      </PopoverTrigger>
      <PopoverContent align="end" className="flex w-80 max-w-[calc(100vw-2rem)] flex-col p-1.5">
        <div className="flex items-center justify-between gap-2 px-2 py-1">
          <p className="text-xs font-semibold text-muted">{t('notifications.title')}</p>
          {(notes.data?.unread ?? 0) > 0 && (
            <Button variant="ghost" size="sm" onClick={() => read.mutate(undefined)} className="h-7 px-2 text-xs text-accent-text">
              {t('notifications.readAll')}
            </Button>
          )}
        </div>
        {list.length === 0 && (q.data?.data.length ?? 0) === 0 && <p className="px-2 py-4 text-center text-sm text-muted">{t('alerts.none')}</p>}
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
                  className={cn('flex flex-col rounded-md px-2 py-2 hover:bg-sunken pointer-coarse:min-h-11', !n.read && 'bg-accent-soft/50')}
                >
                  <span className={cn('text-sm', n.read ? 'text-muted' : 'font-medium', n.urgent && !n.read && 'text-danger-text')}>{n.title}</span>
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
                  <Link to={e.link} onClick={() => setOpen(false)} className="text-sm font-medium hover:text-accent-text">
                    {e.title}
                  </Link>
                ) : (
                  <span className="text-sm font-medium">{e.title}</span>
                )}
                <span className="text-xs text-muted">
                  {date(localDate(e.fired_at))} {localTime(e.fired_at)}
                </span>
              </div>
              <Button variant="ghost" size="icon" aria-label={t('alerts.markDone')} title={t('alerts.markDone')} disabled={ack.isPending} onClick={() => ack.mutate(e.id)} className="size-8 text-muted hover:text-ok-text">
                <Check aria-hidden />
              </Button>
            </li>
          ))}
        </ul>
      </PopoverContent>
    </Popover>
  )
}
