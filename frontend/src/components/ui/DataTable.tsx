import { useMemo, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { ArrowDownTrayIcon, ChevronDownIcon, ChevronUpDownIcon, ChevronUpIcon, ViewColumnsIcon } from '@heroicons/react/20/solid'
import { Button, buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { cn } from '@/lib/cn'
import { downloadText, toCsv } from '@/lib/csv'
import { useMediaQuery } from '@/lib/useMediaQuery'

/**
 * One table for every list (owner, 2026-11-07): sort by a column, 25 / 50 / 100 rows, hide or show
 * columns (remembered per person and browser), Excel download, a header that stays visible, and cards on phones.
 *
 * Server lists pass `sort` + `onSort`, `paging` and `exportUrl` (the API sorts, pages and makes the file).
 * Small lists pass only `rows`: the table sorts and pages them itself, and `csvName` makes the download.
 */
export type Column<T> = {
  key: string
  label: string
  render: (row: T) => ReactNode
  /** Server list: the API's sort key. */
  sort?: string
  /** Small list: the value to sort by. */
  sortValue?: (row: T) => string | number | null | undefined
  /** Small list: the value in the downloaded file (default: sortValue). */
  csv?: (row: T) => string | number | null | undefined
  align?: 'right'
  /** False: always shown (e.g. the name). */
  hideable?: boolean
  defaultHidden?: boolean
  className?: string
}

export type SortState = { by: string; dir: 'asc' | 'desc' }

type Paging = { page: number; last: number; total?: number; perPage: number; onPage: (p: number) => void; onPerPage: (n: number) => void }

type Props<T> = {
  /** Remembers hidden columns (localStorage key). */
  id: string
  columns: Column<T>[]
  rows: T[]
  rowKey: (row: T) => string | number
  loading?: boolean
  empty?: ReactNode
  onRowClick?: (row: T) => void
  rowClassName?: (row: T) => string | undefined
  sort?: SortState
  onSort?: (s: SortState) => void
  paging?: Paging
  /** Server list: the same list as an Excel file (only for people allowed to export). */
  exportUrl?: string | null
  /** Small list: file name for the download (only when `canExport`). */
  csvName?: string
  canExport?: boolean
  toolbar?: ReactNode
  minWidth?: string
}

const PER_PAGE = [25, 50, 100]

function loadHidden(id: string, columns: Column<unknown>[]): string[] {
  try {
    const raw = localStorage.getItem(`hms-cols-${id}`)
    if (raw) return JSON.parse(raw) as string[]
  } catch {
    /* storage can be blocked */
  }
  return columns.filter((c) => c.defaultHidden).map((c) => c.key)
}

export function DataTable<T>({
  id, columns, rows, rowKey, loading, empty, onRowClick, rowClassName, sort, onSort, paging, exportUrl, csvName, canExport, toolbar, minWidth = '720px',
}: Props<T>) {
  const { t } = useTranslation()
  const phone = useMediaQuery('(max-width: 639px)')
  const [hidden, setHidden] = useState<string[]>(() => loadHidden(id, columns as Column<unknown>[]))
  const [localSort, setLocalSort] = useState<SortState | null>(null)
  const [localPage, setLocalPage] = useState(1)
  const [localPer, setLocalPer] = useState(25)
  const server = !!onSort
  const shown = columns.filter((c) => !hidden.includes(c.key))
  const activeSort = server ? sort : localSort

  const toggle = (key: string) => {
    const next = hidden.includes(key) ? hidden.filter((k) => k !== key) : [...hidden, key]
    setHidden(next)
    try {
      localStorage.setItem(`hms-cols-${id}`, JSON.stringify(next))
    } catch {
      /* storage can be blocked */
    }
  }

  // Small lists: sort and page here.
  const sorted = useMemo(() => {
    if (server || !localSort) return rows
    const col = columns.find((c) => c.key === localSort.by)
    if (!col?.sortValue) return rows
    const v = col.sortValue
    return [...rows].sort((a, b) => {
      const x = v(a) ?? ''
      const y = v(b) ?? ''
      const r = typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), undefined, { numeric: true, sensitivity: 'base' })
      return localSort.dir === 'asc' ? r : -r
    })
  }, [rows, columns, localSort, server])
  const lastLocal = Math.max(1, Math.ceil(sorted.length / localPer))
  const pageNow = Math.min(localPage, lastLocal)
  const visible = paging || server ? sorted : sorted.slice((pageNow - 1) * localPer, pageNow * localPer)
  const pager: Paging | null = paging ?? (server || sorted.length <= 25 ? null : { page: pageNow, last: lastLocal, total: sorted.length, perPage: localPer, onPage: setLocalPage, onPerPage: (n) => (setLocalPer(n), setLocalPage(1)) })

  const sortable = (c: Column<T>) => (server ? !!c.sort : !!c.sortValue)
  const sortKey = (c: Column<T>) => (server ? c.sort! : c.key)
  const clickSort = (c: Column<T>) => {
    const key = sortKey(c)
    const dir: 'asc' | 'desc' = activeSort?.by === key && activeSort.dir === 'asc' ? 'desc' : 'asc'
    if (server) onSort!({ by: key, dir })
    else setLocalSort({ by: key, dir })
  }

  const download = () => {
    const cols = shown.filter((c) => c.csv || c.sortValue)
    downloadText(`${csvName}.csv`, toCsv(cols.map((c) => c.label), sorted.map((r) => cols.map((c) => (c.csv ?? c.sortValue)!(r)))))
  }

  const tools = (
    <div className="flex flex-wrap items-center gap-2">
      {exportUrl && (
        <a href={exportUrl} download className={buttonClass('secondary')}>
          <ArrowDownTrayIcon aria-hidden className="size-4" />
          {t('table.excel')}
        </a>
      )}
      {!exportUrl && csvName && canExport && rows.length > 0 && (
        <Button variant="secondary" onClick={download}>
          <ArrowDownTrayIcon aria-hidden className="size-4" />
          {t('table.excel')}
        </Button>
      )}
      {columns.some((c) => c.hideable !== false) && (
        <details className="relative">
          <summary className={cn(buttonClass('secondary'), 'list-none [&::-webkit-details-marker]:hidden')}>
            <ViewColumnsIcon aria-hidden className="size-4" />
            {t('table.columns')}
          </summary>
          <div className="absolute right-0 z-20 mt-1 flex w-56 flex-col gap-1 rounded-lg bg-surface p-2 shadow-lg ring-1 ring-ring">
            {columns
              .filter((c) => c.hideable !== false)
              .map((c) => (
                <label key={c.key} className="flex min-h-touch cursor-pointer items-center gap-2 rounded px-2 text-[13px] hover:bg-sunken">
                  <input type="checkbox" className="size-4 accent-accent" checked={!hidden.includes(c.key)} onChange={() => toggle(c.key)} />
                  {c.label}
                </label>
              ))}
          </div>
        </details>
      )}
    </div>
  )

  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div className="flex flex-wrap items-end gap-2">{toolbar}</div>
        {tools}
      </div>

      {phone ? (
        <div className="flex flex-col gap-2">
          {visible.map((r) => (
            <Card key={rowKey(r)} className={cn('flex flex-col gap-1 p-3 text-[13px]', onRowClick && 'cursor-pointer', rowClassName?.(r))} onClick={onRowClick ? () => onRowClick(r) : undefined}>
              {shown.map((c, i) => (
                <div key={c.key} className={cn('flex justify-between gap-3', i === 0 && 'font-medium')}>
                  {i > 0 && <span className="text-muted">{c.label}</span>}
                  <span className={cn(i > 0 && 'text-right')}>{c.render(r)}</span>
                </div>
              ))}
            </Card>
          ))}
        </div>
      ) : (
        <Card className="max-h-[75vh] overflow-auto p-0">
          <table className="w-full text-left text-[13px]" style={{ minWidth }}>
            <thead className="sticky top-0 z-10 bg-sunken">
              <tr>
                {shown.map((c) => {
                  const on = activeSort?.by === (sortable(c) ? sortKey(c) : '')
                  return (
                    <th key={c.key} scope="col" aria-sort={on ? (activeSort!.dir === 'asc' ? 'ascending' : 'descending') : undefined} className={cn('px-4 py-2.5 font-semibold whitespace-nowrap', c.align === 'right' && 'text-right', c.className)}>
                      {sortable(c) ? (
                        <button type="button" onClick={() => clickSort(c)} className={cn('inline-flex items-center gap-1 rounded hover:text-accent-text', c.align === 'right' && 'flex-row-reverse')}>
                          {c.label}
                          {on ? activeSort!.dir === 'asc' ? <ChevronUpIcon aria-hidden className="size-4" /> : <ChevronDownIcon aria-hidden className="size-4" /> : <ChevronUpDownIcon aria-hidden className="size-4 text-icon" />}
                        </button>
                      ) : (
                        c.label
                      )}
                    </th>
                  )
                })}
              </tr>
            </thead>
            <tbody>
              {visible.map((r) => (
                <tr key={rowKey(r)} onClick={onRowClick ? () => onRowClick(r) : undefined} className={cn('border-t border-border', onRowClick && 'cursor-pointer hover:bg-sunken', rowClassName?.(r))}>
                  {shown.map((c) => (
                    <td key={c.key} className={cn('px-4 py-2.5 align-top', c.align === 'right' && 'text-right tabular-nums', c.className)}>
                      {c.render(r)}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
          {loading && rows.length === 0 && <p className="p-6 text-center text-muted">{t('common.loading')}</p>}
          {!loading && rows.length === 0 && <div className="p-6 text-center text-muted">{empty ?? t('table.empty')}</div>}
        </Card>
      )}
      {phone && !loading && rows.length === 0 && <div className="p-6 text-center text-muted">{empty ?? t('table.empty')}</div>}

      {pager && (pager.last > 1 || (pager.total ?? 0) > 25) && (
        <div className="flex flex-wrap items-center justify-end gap-2 text-[13px] text-muted">
          <label className="flex items-center gap-2">
            {t('table.perPage')}
            <select className="min-h-touch rounded-lg bg-surface px-2 ring-1 ring-ring" value={pager.perPage} onChange={(e) => pager.onPerPage(Number(e.target.value))}>
              {PER_PAGE.map((n) => (
                <option key={n} value={n}>
                  {n}
                </option>
              ))}
            </select>
          </label>
          <Button variant="secondary" disabled={pager.page <= 1} onClick={() => pager.onPage(pager.page - 1)}>
            {t('common.previous')}
          </Button>
          <span>{t('common.pageOf', { page: pager.page, last: pager.last })}</span>
          <Button variant="secondary" disabled={pager.page >= pager.last} onClick={() => pager.onPage(pager.page + 1)}>
            {t('common.next')}
          </Button>
        </div>
      )}
    </div>
  )
}
