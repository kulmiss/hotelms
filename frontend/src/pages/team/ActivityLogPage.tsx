import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { MagnifyingGlassIcon } from '@heroicons/react/24/outline'
import { PageHeader } from '@/components/ui/PageHeader'
import { useActivityLog, type ActivityEntry } from '@/lib/hotel'
import { DataTable, type SortState } from '@/components/ui/DataTable'
import { DateRangeFilter } from '@/components/ui/DateRangeFilter'
import { listFileUrl } from '@/lib/front'
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
  const [perPage, setPerPage] = useState(50)
  const [sort, setSort] = useState<SortState>({ by: 'when', dir: 'desc' })
  const log = useActivityLog(search, page, range, { sort: sort.by, dir: sort.dir, per_page: perPage })

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
      <DataTable<ActivityEntry>
        id="activity"
        rows={log.data?.data ?? []}
        rowKey={(e) => e.id}
        loading={log.isLoading}
        error={log.error}
        onRetry={() => log.refetch()}
        sort={sort}
        onSort={(x) => {
          setSort(x)
          setPage(1)
        }}
        paging={{ page, last: log.data?.last_page ?? 1, total: log.data?.total, perPage, onPage: setPage, onPerPage: (n) => (setPerPage(n), setPage(1)) }}
        exportUrl={listFileUrl('/activity-log', { search, ...range, sort: sort.by, dir: sort.dir })}
        minWidth="640px"
        empty={t('team.activity.empty')}
        columns={[
          { key: 'when', label: t('team.activity.when'), sort: 'when', hideable: false, render: (e) => <span className="whitespace-nowrap text-muted">{when(e.created_at)}</span> },
          { key: 'who', label: t('team.activity.who'), sort: 'who', render: (e) => e.user?.name ?? t('team.activity.system') },
          {
            key: 'what', label: t('team.activity.what'), hideable: false,
            render: (e) => (
              <>
                {e.description}
                <Changes entry={e} />
              </>
            ),
          },
        ]}
      />
    </div>
  )
}
