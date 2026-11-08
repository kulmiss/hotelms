import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { useTranslation } from 'react-i18next'
import { BanknoteIcon, LayoutGridIcon, LogInIcon, LogOutIcon, ShoppingBagIcon, UserPlusIcon, XIcon } from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { SelectBox } from '@/components/ui/Select'
import { SwipeRow } from '@/components/ui/SwipeRow'
import { useMe } from '@/lib/auth'
import { useBrand } from '@/lib/brand'
import { useCurrentBranch } from '@/lib/branch'
import { cn } from '@/lib/cn'
import { useToday, type TodayRow } from '@/lib/desk'
import { money } from '@/lib/format'
import { localTime } from '@/lib/front'
import { useFrontDeskMode } from '@/lib/frontDeskMode'
import { useCan } from '@/lib/hotel'

/** Front desk mode (decisions.md W5): full-screen reception view with big tiles. Turned on per receptionist. */
export function FrontDeskModePage() {
  const { t } = useTranslation()
  const me = useMe()
  const can = useCan()
  const brand = useBrand()
  const navigate = useNavigate()
  const [, setMode] = useFrontDeskMode(me.data?.user.id)
  const { branches, current, select } = useCurrentBranch(me.data)
  const today = useToday(current?.id ?? null)
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    const id = window.setInterval(() => setNow(new Date()), 30_000)
    return () => window.clearInterval(id)
  }, [])

  const arrivals = today.data?.arrivals ?? []
  const departures = today.data?.departures ?? []
  const tiles = [
    { key: 'checkIn', icon: LogInIcon, to: '#arrivals', count: arrivals.length, show: can('frontdesk.check_in') },
    { key: 'checkOut', icon: LogOutIcon, to: '#departures', count: departures.length, show: can('frontdesk.check_out') },
    { key: 'walkIn', icon: UserPlusIcon, to: '/rooms/walk-in', show: can('frontdesk.walk_in') },
    { key: 'takePayment', icon: BanknoteIcon, to: '/billing', show: can('payments.take') },
    { key: 'chargeRoom', icon: ShoppingBagIcon, to: '/charge', show: can('charges.room') },
    { key: 'roomBoard', icon: LayoutGridIcon, to: '/rooms', show: can('frontdesk.view') },
  ].filter((x) => x.show)

  const exit = () => {
    setMode(false)
    navigate('/', { replace: true })
  }

  return (
    <div className="flex min-h-full flex-col bg-bg">
      <header className="flex flex-wrap items-center gap-3 border-b border-border bg-surface px-4 py-3 md:px-6">
        <div className="min-w-0 flex-1">
          <h1 className="truncate text-base font-semibold">{t('frontDesk.title')}</h1>
          <p className="truncate text-xs text-muted">
            {me.data?.hotel?.name ?? brand.name} · {now.toLocaleString([], { weekday: 'long', hour: '2-digit', minute: '2-digit' })}
          </p>
        </div>
        {branches.length > 1 && (
          <SelectBox aria-label={t('frontDesk.branch')} value={current?.id ?? ''} onChange={(e) => select(Number(e.target.value))} className="w-auto">
            {branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </SelectBox>
        )}
        <Button variant="secondary" onClick={exit}>
          <XIcon aria-hidden />
          {t('frontDesk.exit')}
        </Button>
      </header>

      <main className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <nav aria-label={t('frontDesk.title')} className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          {tiles.map(({ key, icon: Icon, to, count }) => {
            const body = (
              <>
                <Icon aria-hidden className="size-8 text-primary" />
                <span className="text-base font-semibold">{t(`frontDesk.tiles.${key}`)}</span>
                {count !== undefined && <span className="text-xs text-muted">{t('frontDesk.today', { count })}</span>}
              </>
            )
            const cls = 'panel flex min-h-32 flex-col items-center justify-center gap-2 p-4 text-center transition-colors hover:bg-sunken/50 active:scale-[0.98]'
            return to.startsWith('#') ? (
              <a key={key} href={to} className={cls}>
                {body}
              </a>
            ) : (
              <Link key={key} to={to} className={cls}>
                {body}
              </Link>
            )
          })}
        </nav>

        <div className="grid gap-6 lg:grid-cols-2">
          <TodayColumn id="arrivals" title={t('desk.tabs.arrivals')} rows={arrivals} loading={today.isPending} kind="in" enabled={can('frontdesk.check_in')} />
          <TodayColumn id="departures" title={t('desk.tabs.departures')} rows={departures} loading={today.isPending} kind="out" enabled={can('frontdesk.check_out')} />
        </div>
        <p className="text-center text-xs text-muted">{t('frontDesk.swipeHint')}</p>
      </main>
    </div>
  )
}

function TodayColumn({ id, title, rows, loading, kind, enabled }: { id: string; title: string; rows: TodayRow[]; loading: boolean; kind: 'in' | 'out'; enabled: boolean }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const action = kind === 'in' ? t('desk.checkIn') : t('desk.checkOut')
  const go = (r: TodayRow) => navigate(`/reservations/${r.id}?action=${kind === 'in' ? 'check-in' : 'check-out'}`)
  return (
    <section id={id} className="flex scroll-mt-4 flex-col gap-2">
      <h2 className="text-sm font-semibold">
        {title} <span className="text-muted">({rows.length})</span>
      </h2>
      {loading && <p className="text-sm text-muted">{t('common.loading')}</p>}
      {!loading && rows.length === 0 && <p className="panel p-4 text-center text-sm text-muted">{t(`desk.empty.${kind === 'in' ? 'arrivals' : 'departures'}`)}</p>}
      <ul className="flex flex-col gap-2">
        {rows.map((r) => {
          const content = (
            <div className={cn('panel flex min-h-16 items-center gap-3 px-4 py-2', (r.late || r.overdue) && 'border-warn-text/40')}>
              <Link to={`/reservations/${r.id}`} className="flex min-w-0 flex-1 flex-col">
                <span className="truncate text-sm font-medium">{r.guest?.name}</span>
                <span className="truncate text-xs text-muted">
                  {localTime(kind === 'in' ? r.check_in_at : r.check_out_at)} · {r.rooms.map((x) => x.room_number ?? x.room_type).join(', ')} · {r.code}
                </span>
              </Link>
              {r.late && <Badge tone="warn">{t('desk.late')}</Badge>}
              {r.overdue && <Badge tone="danger">{t('desk.overdue')}</Badge>}
              <span className="hidden text-sm tabular-nums sm:inline">{money(r.balance, r.currency)}</span>
              {enabled && (
                <Button size="lg" variant={kind === 'in' ? 'primary' : 'secondary'} onClick={() => go(r)}>
                  {action}
                </Button>
              )}
            </div>
          )
          return (
            <li key={r.id}>
              {enabled ? (
                <SwipeRow action={action} onAction={() => go(r)} tone={kind === 'out' ? 'warn' : 'accent'}>
                  {content}
                </SwipeRow>
              ) : (
                content
              )}
            </li>
          )
        })}
      </ul>
    </section>
  )
}
