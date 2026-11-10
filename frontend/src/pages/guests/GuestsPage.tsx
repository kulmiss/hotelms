import { useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { DataTable, type SortState } from '@/components/ui/DataTable'
import { DateRangeFilter } from '@/components/ui/DateRangeFilter'
import { rangeChip, type DateRange } from '@/lib/dateRange'
import { ToolbarSearch } from '@/components/ui/CollapsibleToolbar'
import { IdPhoto } from '@/components/guests/IdPhoto'
import { PlusIcon, SearchIcon, UsersIcon } from 'lucide-react'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button, buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { LoadFailed } from '@/components/ui/LoadFailed'
import { Tabs } from '@/components/ui/Tabs'
import { ApiError } from '@/lib/api'
import { date, money } from '@/lib/format'
import { listFileUrl, localDate, STATUS_TONES, useBlacklist, useGuest, useGuests, useLookups, type Guest } from '@/lib/front'
import { useCan } from '@/lib/hotel'
import { useDebounced } from '@/lib/useDebounced'
import { GuestForm } from './GuestForm'
import { inputClass } from '@/components/ui/formStyles'
import { cn } from '@/lib/cn'

export function GuestsPage() {
  const { t } = useTranslation()
  const can = useCan()
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const search = useDebounced(q, 250)
  const [params, setParams] = useSearchParams()
  const missing = params.get('show') === 'missing'
  const [stayed, setStayed] = useState<DateRange>({})
  const [perPage, setPerPage] = useState(25)
  const [sort, setSort] = useState<SortState>({ by: 'newest', dir: 'desc' })
  const guests = useGuests(search, page, true, missing, stayed, { sort: sort.by, dir: sort.dir, per_page: perPage })

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={t('nav.guests')}
        subtitle={t('guests.subtitle')}
        actions={
          can('guests.create_edit') && (
            <Link to="/guests/new" className={buttonClass()}>
              <PlusIcon aria-hidden className="size-4" />
              {t('guests.add')}
            </Link>
          )
        }
      />
      <div className="max-w-sm">
        <Tabs
          label={t('nav.guests')}
          active={missing ? 'missing' : 'all'}
          onChange={(k) => {
            setParams(k === 'missing' ? { show: 'missing' } : {})
            setPage(1)
          }}
          items={[
            { key: 'all', label: t('guests.filters.all') },
            { key: 'missing', label: t('guests.filters.missing') },
          ]}
        />
      </div>
      <DataTable<Guest>
        id="guests"
        search={
          <div className="relative w-full lg:max-w-md">
            <SearchIcon aria-hidden className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-icon" />
            <input
              type="search"
              aria-label={t('guests.search')}
              placeholder={t('guests.search')}
              value={q}
              onChange={(e) => {
                setQ(e.target.value)
                setPage(1)
              }}
              className={cn(inputClass, 'w-full ps-9')}
            />
          </div>
        }
        filters={
          <div className="flex flex-wrap items-end gap-3">
            <ToolbarSearch />
            <DateRangeFilter
              label={t('dates.stayed')}
              value={stayed}
              onChange={(r) => {
                setStayed(r)
                setPage(1)
              }}
            />
          </div>
        }
        activeFilters={rangeChip(t, 'stayed', t('dates.stayed'), stayed, () => (setStayed({}), setPage(1)))}
        onClearFilters={() => {
          setStayed({})
          setPage(1)
        }}
        filtersGap="gap-6"
        rows={guests.data?.data ?? []}
        rowKey={(g) => g.id}
        loading={guests.isLoading}
        error={guests.error}
        onRetry={() => guests.refetch()}
        sort={sort}
        onSort={(x) => {
          setSort(x)
          setPage(1)
        }}
        paging={{ page, last: guests.data?.last_page ?? 1, total: guests.data?.total, perPage, onPage: setPage, onPerPage: (n) => (setPerPage(n), setPage(1)) }}
        exportUrl={can('guests.export') ? listFileUrl('/guests', { q: search, missing: missing ? 1 : undefined, stayed_from: stayed.from, stayed_to: stayed.to, sort: sort.by, dir: sort.dir }) : null}
        empty={
          <div className="flex flex-col items-center gap-3 py-6">
            <span className="flex size-12 items-center justify-center rounded-full bg-sunken">
              <UsersIcon aria-hidden className="size-6 text-icon" />
            </span>
            <p>{search ? t('guests.noMatch') : t('guests.empty')}</p>
          </div>
        }
        columns={[
          {
            key: 'name', label: t('guests.name'), sort: 'name', hideable: false,
            render: (g) => (
              <Link to={`/guests/${g.id}`} className="flex min-h-touch items-center gap-2 font-medium">
                {g.full_name}
                {g.is_blacklisted && <Badge tone="danger">{t('guests.blacklisted')}</Badge>}
                {g.details_missing && <Badge tone="warn">{t('guests.detailsMissing')}</Badge>}
              </Link>
            ),
          },
          { key: 'phone', label: t('guests.phone'), sort: 'phone', render: (g) => <span className="text-muted">{g.phone ?? '—'}</span> },
          { key: 'id', label: t('guests.idNumber'), render: (g) => <span className="text-muted">{g.id_number ? `${t(`guests.idTypes.${g.id_type ?? 'national_id'}`)} · ${g.id_number}` : '—'}</span> },
          { key: 'nationality', label: t('guests.nationality'), sort: 'nationality', defaultHidden: true, render: (g) => g.nationality ?? '—' },
          { key: 'type', label: t('guests.guestType'), render: (g) => <span className="text-muted">{g.guest_type?.name ?? '—'}</span> },
          { key: 'stays', label: t('guests.stays'), sort: 'stays', align: 'right', render: (g) => g.reservations_count ?? 0 },
        ]}
      />
    </div>
  )
}

export function Pager({ page, last, onChange }: { page: number; last: number; onChange: (p: number) => void }) {
  const { t } = useTranslation()
  return (
    <div className="flex items-center justify-end gap-2 text-sm text-muted">
      <Button variant="secondary" disabled={page <= 1} onClick={() => onChange(page - 1)}>
        {t('common.previous')}
      </Button>
      <span>{t('common.pageOf', { page, last })}</span>
      <Button variant="secondary" disabled={page >= last} onClick={() => onChange(page + 1)}>
        {t('common.next')}
      </Button>
    </div>
  )
}

/** New guest, or a guest's profile: details, stays and blacklist. */
export function GuestPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const navigate = useNavigate()
  const can = useCan()
  const guestId = Number(id ?? 0)
  const q = useGuest(guestId)
  const lookups = useLookups()

  if (!guestId) {
    return (
      <div className="flex flex-col gap-6">
        <PageHeader title={t('guests.add')} breadcrumbs={[{ label: t('nav.guests'), to: '/guests' }, { label: t('guests.add') }]} />
        <Card>
          <GuestForm onSaved={(g) => navigate(`/guests/${g.id}`, { replace: true })} onCancel={() => navigate('/guests')} />
        </Card>
      </div>
    )
  }
  if (q.isError) return <LoadFailed error={q.error} onRetry={() => q.refetch()} back={{ to: '/guests', label: t('loadFailed.toGuests') }} />
  if (!q.data) return null
  const g = q.data.data

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={g.full_name}
        subtitle={g.is_blacklisted ? <Badge tone="danger">{t('guests.blacklistedBecause', { reason: g.blacklist_reason ?? '' })}</Badge> : g.phone}
        breadcrumbs={[{ label: t('nav.guests'), to: '/guests' }, { label: g.full_name }]}
        actions={
          can('reservations.create') &&
          !g.is_blacklisted && (
            <Link to={`/reservations/new?guest=${g.id}`} className={buttonClass()}>
              {t('reservations.new')}
            </Link>
          )
        }
      />
      {g.details_missing && <Alert tone="info">{t('guests.detailsMissingHelp')}</Alert>}
      <div className="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <Card>{can('guests.create_edit') ? <GuestForm key={g.id} guest={g} onSaved={() => undefined} /> : renderReadOnly()}</Card>
        <div className="flex flex-col gap-4">
          {(lookups.data?.id_photo?.enabled || q.data.id_photo) && (
            <Card>
              <IdPhoto guestId={g.id} photo={q.data.id_photo ?? null} canEdit={can('guests.create_edit') && !!lookups.data?.id_photo?.enabled} />
            </Card>
          )}
          <Card className="flex flex-col gap-2">
            <h2 className="text-sm font-semibold">{t('guests.stayHistory')}</h2>
            {q.data.stays.length === 0 && <p className="text-sm text-muted">{t('guests.noStays')}</p>}
            <ul className="flex flex-col divide-y divide-border text-sm">
              {q.data.stays.map((s) => (
                <li key={s.id}>
                  <Link to={`/reservations/${s.id}`} className="flex min-h-touch items-center justify-between gap-2 py-1.5 hover:text-accent-text">
                    <span className="flex flex-col">
                      <span className="font-medium">{s.code}</span>
                      <span className="text-xs text-muted">
                        {date(localDate(s.check_in_at))} → {date(localDate(s.check_out_at))} · {s.branch}
                      </span>
                    </span>
                    <span className="flex flex-col items-end gap-1">
                      <Badge tone={STATUS_TONES[s.status]}>{t(`reservations.statuses.${s.status}`)}</Badge>
                      <span className="text-xs text-muted">{money(s.total, s.currency)}</span>
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          </Card>
          {can('guests.blacklist') && <BlacklistCard id={g.id} blacklisted={g.is_blacklisted} />}
        </div>
      </div>
    </div>
  )

  function renderReadOnly() {
    const rows: [string, string | null][] = [
      [t('guests.phone'), g.phone],
      [t('guests.email'), g.email],
      [t('guests.idNumber'), g.id_number],
      [t('guests.nationality'), g.nationality],
      [t('guests.guestType'), g.guest_type?.name ?? null],
      [t('guests.notes'), g.notes],
    ]
    return (
      <dl className="grid gap-3 text-sm sm:grid-cols-2">
        {rows.map(([k, v]) => (
          <div key={k}>
            <dt className="text-muted">{k}</dt>
            <dd className="font-medium">{v || '—'}</dd>
          </div>
        ))}
      </dl>
    )
  }
}

function BlacklistCard({ id, blacklisted }: { id: number; blacklisted: boolean }) {
  const { t } = useTranslation()
  const mutate = useBlacklist(id)
  const [reason, setReason] = useState('')
  const error = mutate.error instanceof ApiError ? mutate.error : null
  return (
    <Card className="flex flex-col gap-3">
      <h2 className="text-sm font-semibold">{t('guests.blacklist')}</h2>
      <p className="text-sm text-muted">{blacklisted ? t('guests.unblacklistHelp') : t('guests.blacklistHelp')}</p>
      {!blacklisted && <Field label={t('guests.reason')} value={reason} onChange={(e) => setReason(e.target.value)} error={error?.field('reason')} />}
      {mutate.error && !error && <Alert>{t('common.serverUnreachable')}</Alert>}
      <div>
        <Button variant={blacklisted ? 'secondary' : 'danger'} disabled={mutate.isPending || (!blacklisted && !reason)} onClick={() => mutate.mutate({ is_blacklisted: !blacklisted, reason })}>
          {blacklisted ? t('guests.unblacklist') : t('guests.addToBlacklist')}
        </Button>
      </div>
    </Card>
  )
}
