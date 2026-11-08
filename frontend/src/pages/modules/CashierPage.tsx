import { useState } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { SmartHints } from '@/components/ui/SmartHints'
import { useSmart } from '@/lib/smart'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { ApiError } from '@/lib/api'
import { subMoney } from '@/lib/decimal'
import { cn } from '@/lib/cn'
import { date, money } from '@/lib/format'
import { listFileUrl, localDate, localTime, useLookups } from '@/lib/front'
import { useCan } from '@/lib/hotel'
import { DataTable, type SortState } from '@/components/ui/DataTable'
import { DateRangeFilter } from '@/components/ui/DateRangeFilter'
import { rangeChip, type DateRange } from '@/lib/dateRange'
import { useCompactToolbar } from '@/lib/compactToolbar'
import { useApproveShift, useCloseShift, useCurrentShift, useOpenShift, useShifts, type CashierSession } from '@/lib/modules'

const err = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : null)
const TONES = { open: 'info', closed: 'warn', approved: 'ok' } as const

/** Cashier shift close (W10): open with starting cash, take cash, count, close, manager approves. */
export function CashierPage() {
  const { t } = useTranslation()
  const can = useCan()
  const current = useCurrentShift()
  const shift = current.data?.data
  const smart = useSmart('cashier', {}, Boolean(shift) && can('cashier.own_shift'))

  return (
    <div className="flex flex-col gap-6">
      <PageHeader title={t('nav.cashier')} subtitle={t('cashier.subtitle')} />
      {shift && <SmartHints hints={smart.data?.data} />}
      {can('cashier.own_shift') && current.data && (shift ? <MyShift shift={shift} /> : <OpenShift />)}
      <ShiftList />
    </div>
  )
}

function OpenShift() {
  const { t } = useTranslation()
  const lookups = useLookups()
  const open = useOpenShift()
  const currencies = lookups.data?.currencies.map((c) => c.code) ?? ['ETB']
  const [opening, setOpening] = useState<Record<string, string>>({})

  return (
    <Card className="flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <h2 className="text-sm font-semibold">{t('cashier.openTitle')}</h2>
        <p className="text-sm text-muted">{t('cashier.openHelp')}</p>
      </div>
      <div className="grid gap-3 sm:grid-cols-3">
        {currencies.map((c) => (
          <Field key={c} label={t('cashier.startingCash', { currency: c })} inputMode="decimal" value={opening[c] ?? ''} placeholder="0" onChange={(e) => setOpening({ ...opening, [c]: e.target.value.replace(/[^0-9.]/g, '') })} />
        ))}
      </div>
      {open.error && <Alert>{err(open.error)}</Alert>}
      <div>
        <Button size="lg" disabled={open.isPending} onClick={() => open.mutate(Object.fromEntries(currencies.map((c) => [c, opening[c] || '0'])))}>
          {t('cashier.open')}
        </Button>
      </div>
    </Card>
  )
}

function MyShift({ shift }: { shift: CashierSession }) {
  const { t } = useTranslation()
  const close = useCloseShift()
  const [counted, setCounted] = useState<Record<string, string>>({})
  const [notes, setNotes] = useState('')
  const [closing, setClosing] = useState(false)
  const expected = Object.entries(shift.expected ?? {})
  const diff = (c: string, e: string) => (counted[c] !== undefined && counted[c] !== '' ? Number(subMoney(counted[c], e)) : null)
  const anyDiff = expected.some(([c, r]) => (diff(c, r.expected) ?? 0) !== 0)

  return (
    <div className="grid items-start grid-cols-[minmax(0,1fr)] gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
      <Card className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-sm font-semibold">{t('cashier.myShift')}</h2>
          <span className="text-xs text-muted">{t('cashier.openedAt', { time: `${date(localDate(shift.opened_at))} ${localTime(shift.opened_at)}` })}</span>
        </div>
        <table className="w-full text-left text-sm">
          <thead className="text-muted">
            <tr>
              <th className="py-1.5 font-medium">{t('reservations.currency')}</th>
              <th className="py-1.5 text-right font-medium">{t('cashier.opening')}</th>
              <th className="py-1.5 text-right font-medium">{t('cashier.cashIn')}</th>
              <th className="py-1.5 text-right font-medium">{t('cashier.cashOut')}</th>
              <th className="py-1.5 text-right font-medium">{t('cashier.inDrawer')}</th>
            </tr>
          </thead>
          <tbody>
            {expected.map(([c, r]) => (
              <tr key={c} className="border-t border-border">
                <td className="py-1.5 font-medium">{c}</td>
                <td className="py-1.5 text-right tabular-nums">{money(r.opening, c)}</td>
                <td className="py-1.5 text-right tabular-nums">{money(r.cash_in, c)}</td>
                <td className="py-1.5 text-right tabular-nums">{money(r.cash_out, c)}</td>
                <td className="py-1.5 text-right font-semibold tabular-nums">{money(r.expected, c)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <h3 className="pt-2 text-xs font-semibold text-muted">{t('cashier.cashTaken')}</h3>
        {shift.payments?.length === 0 && <p className="text-sm text-muted">{t('cashier.noCash')}</p>}
        <ul className="flex flex-col divide-y divide-border text-sm">
          {shift.payments?.map((p) => (
            <li key={p.id} className="flex justify-between gap-3 py-1.5">
              <span>
                {localTime(p.paid_at)} · {t(`reservations.paymentTypes.${p.type}`)}
                {p.reservation && (
                  <Link to={`/reservations/${p.reservation.id}`} className="tap-link text-accent-text">
                    {' '}
                    · {p.reservation.code}
                  </Link>
                )}
              </span>
              <span className={cn('tabular-nums', p.type === 'refund' && 'text-danger-text')}>
                {p.type === 'refund' ? '− ' : ''}
                {money(p.amount, p.currency)}
              </span>
            </li>
          ))}
        </ul>
      </Card>

      <Card className="flex flex-col gap-3">
        <h2 className="text-sm font-semibold">{t('cashier.closeTitle')}</h2>
        {!closing ? (
          <>
            <p className="text-sm text-muted">{t('cashier.closeHelp')}</p>
            <Button variant="secondary" onClick={() => setClosing(true)}>
              {t('cashier.countDrawer')}
            </Button>
          </>
        ) : (
          <>
            {expected.map(([c, r]) => {
              const d = diff(c, r.expected)
              return (
                <div key={c} className="flex flex-col gap-1">
                  <Field label={t('cashier.counted', { currency: c })} inputMode="decimal" value={counted[c] ?? ''} onChange={(e) => setCounted({ ...counted, [c]: e.target.value.replace(/[^0-9.]/g, '') })} />
                  {d !== null && d !== 0 && <span className={cn('text-xs font-medium', d < 0 ? 'text-danger-text' : 'text-warn-text')}>{d < 0 ? t('cashier.short', { amount: money(Math.abs(d).toFixed(2), c) }) : t('cashier.over', { amount: money(d.toFixed(2), c) })}</span>}
                  {d === 0 && <span className="text-xs font-medium text-ok-text">{t('cashier.matches')}</span>}
                </div>
              )
            })}
            {anyDiff && <Field label={t('cashier.why')} value={notes} onChange={(e) => setNotes(e.target.value)} />}
            {close.error && <Alert>{err(close.error)}</Alert>}
            <Button size="lg" disabled={close.isPending || expected.some(([c]) => !counted[c]) || (anyDiff && !notes)} onClick={() => close.mutate({ counted, notes })}>
              {t('cashier.close')}
            </Button>
          </>
        )}
      </Card>
    </div>
  )
}

function ShiftList() {
  const { t } = useTranslation()
  const can = useCan()
  const [short, setShort] = useState(false)
  const [range, setRange] = useState<DateRange>({})
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)
  const [sort, setSort] = useState<SortState>({ by: 'opened', dir: 'desc' })
  const list = useShifts({ short, ...range, page, per_page: perPage, sort: sort.by, dir: sort.dir })
  const approve = useApproveShift()
  const compact = useCompactToolbar()
  const controls = (
    <div className="flex flex-wrap items-end gap-3">
      <DateRangeFilter value={range} onChange={setRange} />
      <label className="flex min-h-touch items-center gap-2 text-sm">
        <input type="checkbox" checked={short} onChange={(e) => setShort(e.target.checked)} />
        {t('cashier.onlyShort')}
      </label>
    </div>
  )

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-sm font-semibold">{can('cashier.view_all') ? t('cashier.allShifts') : t('cashier.pastShifts')}</h2>
        {!compact && controls}
      </div>
      {approve.error && <Alert>{err(approve.error)}</Alert>}
      <DataTable<CashierSession>
        id="cashier-shifts"
        // Phones and tablets (P19): the dates and "only short" fold behind "Filters" by the table.
        filters={compact ? controls : undefined}
        activeFilters={[
          ...rangeChip(t, 'dates', t('dates.label'), range, () => setRange({})),
          ...(short ? [{ key: 'short', label: t('cashier.onlyShort'), onRemove: () => setShort(false) }] : []),
        ]}
        onClearFilters={() => (setRange({}), setShort(false))}
        rows={list.data?.data ?? []}
        rowKey={(s) => s.id}
        loading={list.isLoading}
        error={list.error}
        onRetry={() => list.refetch()}
        sort={sort}
        onSort={(x) => {
          setSort(x)
          setPage(1)
        }}
        paging={{ page, last: list.data?.last_page ?? 1, total: list.data?.total, perPage, onPage: setPage, onPerPage: (n) => (setPerPage(n), setPage(1)) }}
        exportUrl={can('reports.export') ? listFileUrl('/cashier/sessions', { short: short ? 1 : undefined, ...range, sort: sort.by, dir: sort.dir }) : null}
        empty={t('cashier.noShifts')}
        columns={[
          {
            key: 'person', label: t('cashier.person'), sort: 'person', hideable: false,
            render: (s) => (
              <span className="font-medium">
                {s.user?.name}
                <span className="block text-xs font-normal text-muted">{s.branch?.name}</span>
              </span>
            ),
          },
          {
            key: 'when', label: t('cashier.when'), sort: 'opened',
            render: (s) => (
              <span className="text-muted">
                {date(localDate(s.opened_at))} {localTime(s.opened_at)}
                {s.closed_at && ` – ${localTime(s.closed_at)}`}
                {s.notes && <span className="block text-xs">“{s.notes}”</span>}
              </span>
            ),
          },
          {
            key: 'status', label: t('reservations.status'), sort: 'status',
            render: (s) => (
              <>
                <Badge tone={TONES[s.status]}>{t(`cashier.statuses.${s.status}`)}</Badge>
                {s.approved_by && <span className="block text-xs text-muted">{s.approved_by}</span>}
              </>
            ),
          },
          {
            key: 'difference', label: t('cashier.difference'), align: 'right',
            render: (s) =>
              s.balances
                .filter((b) => b.difference !== null)
                .map((b) => (
                  <span key={b.currency} className={cn('block', Number(b.difference) < 0 ? 'text-danger-text' : Number(b.difference) > 0 ? 'text-warn-text' : 'text-muted')}>
                    {money(b.difference ?? '0', b.currency)}
                  </span>
                )),
          },
          {
            key: 'actions', label: '', hideable: false, align: 'right',
            render: (s) =>
              s.status === 'closed' && can('cashier.approve') ? (
                <Button variant="secondary" disabled={approve.isPending} onClick={() => approve.mutate(s.id)}>
                  {t('cashier.approve')}
                </Button>
              ) : null,
          },
        ]}
      />
    </div>
  )
}
