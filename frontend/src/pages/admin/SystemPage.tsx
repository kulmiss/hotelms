import { dateTime, megabytes } from '@/lib/format'
import { useTranslation } from 'react-i18next'
import { CircleCheckIcon, TriangleAlertIcon } from 'lucide-react'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { PageHeader } from '@/components/ui/PageHeader'
import { ApiError } from '@/lib/api'
import { useSystemHealth } from '@/lib/platform'

const when = (v: string | null | undefined) => dateTime(v)

/** Super Admin → System health (area 8). Refreshes every minute. */
export function SystemPage() {
  const { t } = useTranslation()
  const health = useSystemHealth()
  if (health.isPending) return <p className="text-sm text-muted">{t('common.loading')}</p>
  if (health.isError) return <Alert>{health.error instanceof ApiError ? health.error.message : t('common.serverUnreachable')}</Alert>
  const { checks: c, errors, versions, checked_at } = health.data.data

  const rows: { key: string; ok: boolean; detail: string; problem?: string | null }[] = [
    { key: 'database', ok: c.database.ok, detail: c.database.ms !== undefined ? t('system.ms', { ms: c.database.ms }) : '', problem: c.database.problem },
    { key: 'cache', ok: c.cache.ok, detail: c.cache.driver, problem: c.cache.problem },
    { key: 'scheduler', ok: c.scheduler.ok, detail: t('system.lastRun', { when: when(c.scheduler.last_run) }), problem: c.scheduler.problem },
    { key: 'jobs', ok: c.jobs.ok, detail: t('system.jobsLine', { waiting: c.jobs.waiting, failed: c.jobs.failed }) },
    { key: 'backups', ok: c.backups.ok, detail: c.backups.daily ? `${t('system.backupLine', { when: when(c.backups.daily.at), kb: c.backups.daily.size_kb, count: c.backups.daily.count })} · ${t(c.backups.daily.encrypted ? 'system.backupEncrypted' : 'system.backupPlain')}` : '', problem: c.backups.problem },
    { key: 'disk', ok: c.disk.ok, detail: c.disk.free_mb !== null ? t('system.freeSpace', { size: megabytes(c.disk.free_mb) }) : '' },
    ...(c.settings ? [{ key: 'settings', ok: c.settings.ok, detail: c.settings.ok ? t('system.settingsOk') : c.settings.problems.slice(1).join(' '), problem: c.settings.problem }] : []),
  ]

  return (
    <div className="mx-auto flex max-w-6xl flex-col gap-6">
      <PageHeader
        title={t('nav.system')}
        subtitle={t('system.checked', { when: when(checked_at) })}
        actions={
          <Button variant="secondary" onClick={() => health.refetch()} disabled={health.isFetching}>
            {t('system.checkNow')}
          </Button>
        }
      />
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {rows.map((r) => (
          <Card key={r.key} className="flex items-start gap-3">
            {r.ok ? <CircleCheckIcon aria-hidden className="size-5 shrink-0 text-ok-text" /> : <TriangleAlertIcon aria-hidden className="size-5 shrink-0 text-danger-text" />}
            <div className="min-w-0">
              <h2 className="text-sm font-semibold">{t(`system.checks.${r.key}`)}</h2>
              <p className="text-xs text-muted">{r.ok ? t('system.ok') : t('system.problem')}</p>
              {r.detail && <p className="mt-1 text-xs text-muted">{r.detail}</p>}
              {!r.ok && r.problem && <p className="mt-1 text-xs text-danger-text">{r.problem}</p>}
            </div>
          </Card>
        ))}
      </div>

      {c.jobs.recent_failures.length > 0 && (
        <Card className="flex flex-col gap-2">
          <h2 className="text-sm font-semibold">{t('system.failedJobs')}</h2>
          <ul className="flex flex-col gap-1.5 text-xs">
            {c.jobs.recent_failures.map((f) => (
              <li key={f.id} className="rounded-lg bg-sunken px-3 py-2">
                <span className="text-muted">
                  {when(f.at)} · {f.queue}
                </span>
                <p className="break-words font-mono">{f.error}</p>
              </li>
            ))}
          </ul>
        </Card>
      )}

      <Card className="flex flex-col gap-2">
        <h2 className="text-sm font-semibold">{t('system.errors')}</h2>
        {errors.length === 0 ? (
          <p className="text-sm text-muted">{t('system.noErrors')}</p>
        ) : (
          <ul className="flex flex-col gap-1.5 text-xs">
            {errors.map((e, i) => (
              <li key={i} className="rounded-lg bg-sunken px-3 py-2">
                <span className="text-muted">
                  {e.at} · {e.level}
                </span>
                <p className="break-words font-mono">{e.message}</p>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <p className="text-xs text-muted">{t('system.versions', versions)}</p>
    </div>
  )
}
