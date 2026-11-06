import { useState } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { MagnifyingGlassIcon } from '@heroicons/react/24/outline'
import { PlusIcon } from '@heroicons/react/20/solid'
import { Badge } from '@/components/ui/Badge'
import { buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { PageHeader } from '@/components/ui/PageHeader'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { useHotels } from '@/lib/admin'
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

      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[640px] text-left text-sm">
          <thead className="bg-sunken text-text">
            <tr>
              <th className="px-4 py-3 font-semibold">{t('admin.hotels.hotel')}</th>
              <th className="px-4 py-3 font-semibold">{t('admin.hotels.plan')}</th>
              <th className="px-4 py-3 font-semibold">{t('admin.hotels.status')}</th>
              <th className="px-4 py-3 font-semibold">{t('admin.hotels.paidUntil')}</th>
              <th className="px-4 py-3 font-semibold">{t('admin.hotels.branches')}</th>
            </tr>
          </thead>
          <tbody>
            {hotels.data?.data.map((h) => (
              <tr key={h.id} className="border-t border-border transition duration-75 hover:bg-sunken">
                <td className="px-4 py-2">
                  <Link to={`/admin/hotels/${h.id}`} className="flex min-h-touch flex-col justify-center font-semibold text-text">
                    <span className="flex items-center gap-2">
                      {h.name}
                      {h.is_demo && <Badge tone="info">{t('demo.badge')}</Badge>}
                    </span>
                    <span className="text-xs font-normal text-muted">{h.city ?? ''}</span>
                  </Link>
                </td>
                <td className="px-4 py-2">{h.plan?.name ?? '—'}</td>
                <td className="px-4 py-2">
                  <StatusBadge status={h.status} />
                </td>
                <td className="px-4 py-2">
                  {h.status === 'trial' ? t('admin.hotels.trialUntil', { date: date(h.trial_ends_at) }) : date(h.subscription_ends_at)}
                </td>
                <td className="px-4 py-2">{h.branches_count ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {hotels.data && hotels.data.data.length === 0 && (
          <p className="p-6 text-center text-muted">{t('admin.hotels.empty')}</p>
        )}
        {hotels.isPending && <p className="p-6 text-center text-muted">{t('common.loading')}</p>}
      </Card>
    </div>
  )
}
