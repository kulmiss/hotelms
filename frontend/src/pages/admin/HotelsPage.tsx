import { useState } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { MagnifyingGlassIcon } from '@heroicons/react/24/outline'
import { PlusIcon } from '@heroicons/react/20/solid'
import { Badge } from '@/components/ui/Badge'
import { buttonClass } from '@/components/ui/Button'
import { DataTable } from '@/components/ui/DataTable'
import { PageHeader } from '@/components/ui/PageHeader'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { useHotels, type Hotel } from '@/lib/admin'
import { cn } from '@/lib/cn'
import { date } from '@/lib/format'

const FILTERS = ['', 'active', 'trial', 'grace', 'suspended', 'closed'] as const

export function HotelsPage() {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState<string>('')
  const hotels = useHotels({ search, status })
  const counts = hotels.data?.counts ?? {}
  const total = Object.values(counts).reduce((a, b) => a + b, 0)

  return (
    <div className="mx-auto flex max-w-6xl flex-col gap-6">
      <PageHeader
        title={t('nav.hotels')}
        actions={
          <Link
            to="/admin/hotels/new"
            className={buttonClass('primary')}
          >
            <PlusIcon aria-hidden className="size-4" />
            {t('admin.hotels.new')}
          </Link>
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <label className="flex min-h-touch max-w-sm flex-1 items-center gap-2 rounded-lg bg-surface px-3 shadow-sm ring-1 ring-ring-strong focus-within:ring-2 focus-within:ring-accent">
          <MagnifyingGlassIcon aria-hidden className="size-5 shrink-0 text-icon" />
          <span className="sr-only">{t('admin.hotels.search')}</span>
          <input
            type="search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t('admin.hotels.search')}
            className="w-full bg-transparent text-base text-text placeholder:text-muted focus:outline-none"
          />
        </label>
        <div role="group" aria-label={t('admin.hotels.filter')} className="flex flex-wrap gap-2">
          {FILTERS.map((f) => (
            <button
              key={f || 'all'}
              type="button"
              aria-pressed={status === f}
              onClick={() => setStatus(f)}
              className={cn(
                'min-h-touch rounded-lg px-3 text-sm font-medium transition duration-75',
                status === f ? 'bg-surface text-accent-text shadow-sm ring-1 ring-ring' : 'text-muted hover:bg-sunken hover:text-text',
              )}
            >
              {f ? t(`hotelStatus.${f}`) : t('admin.hotels.all')} ({f ? (counts[f] ?? 0) : total})
            </button>
          ))}
        </div>
      </div>

      <DataTable<Hotel>
        id="admin-hotels"
        rows={hotels.data?.data ?? []}
        rowKey={(h) => h.id}
        loading={hotels.isPending}
        error={hotels.error}
        onRetry={() => hotels.refetch()}
        csvName="hotels"
        canExport
        minWidth="640px"
        empty={t('admin.hotels.empty')}
        columns={[
          {
            key: 'hotel', label: t('admin.hotels.hotel'), hideable: false, sortValue: (h) => h.name, csv: (h) => h.name,
            render: (h) => (
              <Link to={`/admin/hotels/${h.id}`} className="flex min-h-touch flex-col justify-center font-semibold text-text">
                <span className="flex items-center gap-2">
                  {h.name}
                  {h.is_demo && <Badge tone="info">{t('demo.badge')}</Badge>}
                </span>
                <span className="text-xs font-normal text-muted">{h.city ?? ''}</span>
              </Link>
            ),
          },
          { key: 'city', label: t('table.city'), defaultHidden: true, sortValue: (h) => h.city ?? '', render: (h) => h.city ?? '—' },
          { key: 'plan', label: t('admin.hotels.plan'), sortValue: (h) => h.plan?.name ?? '', render: (h) => h.plan?.name ?? '—' },
          { key: 'status', label: t('admin.hotels.status'), sortValue: (h) => h.status, render: (h) => <StatusBadge status={h.status} /> },
          {
            key: 'paid', label: t('admin.hotels.paidUntil'), sortValue: (h) => (h.status === 'trial' ? h.trial_ends_at : h.subscription_ends_at) ?? '',
            render: (h) => (h.status === 'trial' ? t('admin.hotels.trialUntil', { date: date(h.trial_ends_at) }) : date(h.subscription_ends_at)),
          },
          { key: 'branches', label: t('admin.hotels.branches'), align: 'right', sortValue: (h) => h.branches_count ?? 0, render: (h) => h.branches_count ?? '—' },
        ]}
      />
    </div>
  )
}
