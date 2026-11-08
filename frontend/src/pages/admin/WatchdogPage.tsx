import { useState } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { ChevronDownIcon, RefreshCwIcon } from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { PageHeader } from '@/components/ui/PageHeader'
import { Tabs } from '@/components/ui/Tabs'
import { cn } from '@/lib/cn'
import { dateTime } from '@/lib/format'
import { useWatchdog, useWatchdogMute, useWatchdogResolve, useWatchdogRun, type WatchFinding } from '@/lib/platform'

const TONE = { critical: 'danger', warning: 'warn', info: 'info' } as const
const RING = { critical: 'border-danger-text/40', warning: 'border-warn-text/30', info: 'border-border' } as const

/** Super Admin → Watchdog: security problems, bugs and data leaks, each with where it is and what to do. */
export function WatchdogPage() {
  const { t } = useTranslation()
  const [show, setShow] = useState<'open' | 'fixed'>('open')
  const q = useWatchdog(show)
  const run = useWatchdogRun()
  const counts = q.data?.counts

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={t('watchdog.title')}
        subtitle={t('watchdog.subtitle')}
        actions={
          <Button variant="secondary" disabled={run.isPending} onClick={() => run.mutate()}>
            <RefreshCwIcon aria-hidden className={cn('size-4', run.isPending && 'animate-spin')} />
            {run.isPending ? t('watchdog.checking') : t('watchdog.checkNow')}
          </Button>
        }
      />

      {counts && (
        <div className="grid gap-3 sm:grid-cols-3">
          {(['critical', 'warning', 'info'] as const).map((s) => (
            <div key={s} className={cn('panel flex flex-col gap-1 p-4', counts[s] > 0 && s === 'critical' && 'ring-2 ring-danger-text/50')}>
              <span className="text-sm font-medium text-muted">{t(`watchdog.severity.${s}`)}</span>
              <span className={cn('text-2xl font-semibold tabular-nums', counts[s] > 0 && s === 'critical' && 'text-danger-text')}>{counts[s]}</span>
            </div>
          ))}
        </div>
      )}
      {q.data && <p className="-mt-3 text-xs text-muted">{t('watchdog.lastRun', { time: q.data.last_run ? dateTime(q.data.last_run) : '—' })}</p>}

      <div className="max-w-xs">
        <Tabs label={t('watchdog.title')} active={show} onChange={(k) => setShow(k as 'open' | 'fixed')} items={[{ key: 'open', label: t('watchdog.open') }, { key: 'fixed', label: t('watchdog.fixed') }]} />
      </div>

      {q.data && q.data.data.length === 0 && (
        <Card className="text-center text-sm text-muted">{show === 'open' ? t('watchdog.allGood') : t('watchdog.noneFixed')}</Card>
      )}
      <ul className="flex flex-col gap-3">
        {q.data?.data.map((f) => (
          <li key={f.id}>
            <FindingCard f={f} />
          </li>
        ))}
      </ul>
    </div>
  )
}

function FindingCard({ f }: { f: WatchFinding }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(f.severity === 'critical' && !f.resolved_at)
  const resolve = useWatchdogResolve()
  const mute = useWatchdogMute()
  const muted = f.muted_until && Date.parse(f.muted_until) > Date.now()

  return (
    <Card className={cn('flex flex-col gap-3', RING[f.severity], muted && 'opacity-70')}>
      <button type="button" aria-expanded={open} onClick={() => setOpen(!open)} className="flex items-start justify-between gap-3 text-left">
        <span className="flex flex-col gap-1">
          <span className="flex flex-wrap items-center gap-2">
            <Badge tone={TONE[f.severity]}>{t(`watchdog.severity.${f.severity}`)}</Badge>
            <span className="text-xs text-muted">{t(`watchdog.checks.${f.check}`, { defaultValue: f.check })}</span>
            {f.hotel && <span className="text-xs text-muted">· {f.hotel.name}</span>}
            {muted && <Badge tone="grey">{t('watchdog.mutedUntil', { time: dateTime(f.muted_until) })}</Badge>}
          </span>
          <span className="text-sm font-semibold">{f.title}</span>
          <span className="text-xs text-muted">
            {t('watchdog.seen', { first: dateTime(f.first_seen_at), last: dateTime(f.last_seen_at), count: f.times })}
            {f.resolved_at && ` · ${t('watchdog.fixedBy', { time: dateTime(f.resolved_at), by: f.resolved_by ?? '' })}`}
          </span>
        </span>
        <ChevronDownIcon aria-hidden className={cn('mt-1 size-5 shrink-0 text-icon transition duration-150', open && 'rotate-180')} />
      </button>

      {open && (
        <div className="flex flex-col gap-3 animate-page-in">
          {f.details.length > 0 && (
            <dl className="grid grid-cols-[minmax(0,12rem)_minmax(0,1fr)] gap-x-4 gap-y-1.5 rounded-lg bg-sunken p-3 text-sm">
              {f.details.map(([label, value], i) => (
                <div key={i} className="contents">
                  <dt className="text-muted">{label}</dt>
                  <dd className="font-medium break-words">{value}</dd>
                </div>
              ))}
            </dl>
          )}
          {f.advice && (
            <p className="text-sm">
              <span className="font-semibold">{t('watchdog.whatToDo')} </span>
              {f.advice}
            </p>
          )}
          <div className="flex flex-wrap gap-2">
            {f.link && (
              <Link to={f.link} className="inline-flex min-h-touch items-center rounded-lg px-3 text-sm font-medium text-accent-text hover:bg-sunken">
                {t('watchdog.openPlace')}
              </Link>
            )}
            {!f.resolved_at && (
              <>
                <Button variant="secondary" disabled={resolve.isPending} onClick={() => resolve.mutate(f.id)}>
                  {t('watchdog.markFixed')}
                </Button>
                <Button variant="ghost" disabled={mute.isPending} onClick={() => mute.mutate({ id: f.id, days: 7 })}>
                  {t('watchdog.mute')}
                </Button>
              </>
            )}
          </div>
        </div>
      )}
    </Card>
  )
}
