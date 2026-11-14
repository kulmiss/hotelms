import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { DataTable } from '@/components/ui/DataTable'
import { Field } from '@/components/ui/Field'
import { Select } from '@/components/ui/Select'
import { api } from '@/lib/api'
import { dateTime } from '@/lib/format'
import { useDebounced } from '@/lib/useDebounced'

export type SecurityEvent = { id: number; kind: string; label: string; who: string | null; email: string | null; ip: string | null; device: string | null; detail: string | null; hotel: string | null; at: string }
type Page = { data: SecurityEvent[]; current_page: number; last_page: number; total: number; kinds: Record<string, string> }

/**
 * The security event log (SECURITY_REPORT M9): sign-ins, wrong passwords and PINs, pauses, password / email /
 * two-step changes, refusals, voids and refunds. "hotel" = this hotel's people (Activity log); "platform" = all (Watchdog).
 */
export function SecurityEvents({ scope }: { scope: 'hotel' | 'platform' }) {
  const { t } = useTranslation()
  const [kind, setKind] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(50)
  const term = useDebounced(search.trim(), 300)
  const path = scope === 'hotel' ? '/security-events' : '/admin/security-events'
  const q = useQuery({
    queryKey: ['security-events', scope, kind, term, page, perPage],
    queryFn: () => api<Page>(`${path}?${new URLSearchParams({ ...(kind ? { kind } : {}), ...(term ? { search: term } : {}), page: String(page), per_page: String(perPage) })}`),
    placeholderData: keepPreviousData,
  })

  return (
    <section className="flex flex-col gap-3" aria-labelledby="security-events">
      <div>
        <h2 id="security-events" className="text-base font-semibold">
          {t('securityEvents.title')}
        </h2>
        <p className="text-sm text-muted">{t('securityEvents.subtitle')}</p>
      </div>
      <DataTable<SecurityEvent>
        id={`security-events-${scope}`}
        filters={
          <div className="flex flex-wrap items-end gap-3">
            <Select label={t('securityEvents.kind')} value={kind} onChange={(e) => (setKind(e.target.value), setPage(1))}>
              <option value="">{t('securityEvents.allKinds')}</option>
              {Object.entries(q.data?.kinds ?? {}).map(([k, label]) => (
                <option key={k} value={k}>
                  {label}
                </option>
              ))}
            </Select>
            <Field label={t('securityEvents.search')} type="search" value={search} placeholder={t('securityEvents.searchHint')} onChange={(e) => (setSearch(e.target.value), setPage(1))} />
          </div>
        }
        rows={q.data?.data ?? []}
        rowKey={(e) => e.id}
        loading={q.isLoading}
        error={q.error}
        onRetry={() => q.refetch()}
        paging={{ page, last: q.data?.last_page ?? 1, total: q.data?.total, perPage, onPage: setPage, onPerPage: (n) => (setPerPage(n), setPage(1)) }}
        minWidth="720px"
        empty={t('securityEvents.empty')}
        columns={[
          { key: 'at', label: t('team.activity.when'), hideable: false, render: (e) => <span className="whitespace-nowrap text-muted">{dateTime(e.at)}</span> },
          { key: 'what', label: t('team.activity.what'), hideable: false, render: (e) => <span className="font-medium">{e.label}</span> },
          { key: 'who', label: t('team.activity.who'), render: (e) => <span className="[overflow-wrap:anywhere]">{e.who ?? e.email ?? '—'}</span> },
          ...(scope === 'platform' ? [{ key: 'hotel', label: t('securityEvents.hotel'), render: (e: SecurityEvent) => e.hotel ?? t('securityEvents.team') }] : []),
          { key: 'from', label: t('securityEvents.from'), render: (e) => <span className="text-muted">{[e.ip, e.device].filter(Boolean).join(' · ') || '—'}</span> },
          { key: 'detail', label: t('securityEvents.detail'), render: (e) => <span className="text-muted [overflow-wrap:anywhere]">{e.detail ?? ''}</span> },
        ]}
      />
    </section>
  )
}
