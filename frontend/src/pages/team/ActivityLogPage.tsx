import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { MagnifyingGlassIcon } from '@heroicons/react/24/outline'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { PageHeader } from '@/components/ui/PageHeader'
import { useActivityLog, type ActivityEntry } from '@/lib/hotel'
import { DateRangeFilter } from '@/components/ui/DateRangeFilter'
import type { DateRange } from '@/lib/dateRange'

const when = (iso: string) =>
  new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })

function Changes({ entry }: { entry: ActivityEntry }) {
  const { old = {}, new: next = {} } = entry.properties ?? {}
  const keys = Object.keys(next)
  if (!keys.length || entry.event !== 'updated') return null
  return (
    <ul className="mt-1 flex flex-col gap-0.5 text-xs text-muted">
      {keys.map((k) => (
        <li key={k}>
          <span className="font-medium text-text">{k}</span>: {String(old[k] ?? '—')} → {String(next[k] ?? '—')}
        </li>
      ))}
    </ul>
  )
}

export function ActivityLogPage() {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [range, setRange] = useState<DateRange>({})
  const log = useActivityLog(search, page, range)

  return (
    <div className="flex flex-col gap-8">
      <PageHeader title={t('nav.activityLog')} subtitle={t('team.activity.subtitle')} />
      <div className="flex flex-wrap items-end gap-3">
      <label className="flex min-h-touch w-full max-w-sm items-center gap-2 rounded-lg bg-surface px-3 shadow-sm ring-1 ring-ring-strong focus-within:ring-2 focus-within:ring-accent">
        <MagnifyingGlassIcon aria-hidden className="size-5 shrink-0 text-icon" />
        <span className="sr-only">{t('team.activity.search')}</span>
        <input
          type="search"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value)
            setPage(1)
          }}
          placeholder={t('team.activity.search')}
          className="w-full bg-transparent text-base text-text placeholder:text-icon focus:outline-none sm:text-sm"
        />
      </label>
        <DateRangeFilter
          value={range}
          onChange={(r) => {
            setRange(r)
            setPage(1)
          }}
        />
      </div>
      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[640px] text-left text-sm">
          <thead className="bg-sunken text-text">
            <tr>
              <th className="px-4 py-3 font-semibold">{t('team.activity.when')}</th>
              <th className="px-4 py-3 font-semibold">{t('team.activity.who')}</th>
              <th className="px-4 py-3 font-semibold">{t('team.activity.what')}</th>
            </tr>
          </thead>
          <tbody>
            {log.data?.data.map((e) => (
              <tr key={e.id} className="border-t border-border align-top">
                <td className="px-4 py-3 whitespace-nowrap text-muted">{when(e.created_at)}</td>
                <td className="px-4 py-3">{e.user?.name ?? t('team.activity.system')}</td>
                <td className="px-4 py-3">
                  {e.description}
                  <Changes entry={e} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {log.data && log.data.data.length === 0 && <p className="p-6 text-center text-sm text-muted">{t('team.activity.empty')}</p>}
      </Card>
      {log.data && log.data.last_page > 1 && (
        <div className="flex items-center justify-between">
          <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            {t('common.previous')}
          </Button>
          <span className="text-sm text-muted">{t('team.activity.page', { page, of: log.data.last_page })}</span>
          <Button variant="secondary" disabled={page >= log.data.last_page} onClick={() => setPage(page + 1)}>
            {t('common.next')}
          </Button>
        </div>
      )}
    </div>
  )
}
