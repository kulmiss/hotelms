import { useMemo, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { ArrowDown, ArrowUp, ChevronsUpDown, Download, SlidersHorizontal } from 'lucide-react'
import { DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/shadcn/dropdown-menu'
import { Button, buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { LoadFailed } from '@/components/ui/LoadFailed'
import { CollapsibleToolbar, ToolbarSearch, type ActiveFilter } from '@/components/ui/CollapsibleToolbar'
import { useCompactToolbar } from '@/lib/compactToolbar'
import { cn } from '@/lib/cn'
import { downloadText, toCsv } from '@/lib/csv'
import { useMediaQuery } from '@/lib/useMediaQuery'
import { selectClass } from './formStyles'

/**
 * One table for every list (owner, 2026-11-07): sort by a column, 25 / 50 / 100 rows, hide or show
 * columns (remembered per person and browser), Excel download, and cards on phones. The header scrolls with the
 * rows (owner: no floating header).
 *
 * Server lists pass `sort` + `onSort`, `paging` and `exportUrl` (the API sorts, pages and makes the file).
 * Small lists pass only `rows`: the table sorts and pages them itself, and `csvName` makes the download.
 *
 * Filters (P19): pass `filters` (laid out as on desktop, with <ToolbarSearch /> where the search box goes), `search`,
 * `activeFilters` and `onClearFilters`. Below 1024px they fold behind one "Filters" button with the Excel and
 * Columns buttons (CollapsibleToolbar); from 1024px they show above the table as before.
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
  /** The rows could not be loaded: says so (with Try again) instead of "nothing here". */
  error?: unknown
  onRetry?: () => void
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
  /** Filter and sort controls above the table (desktop layout). */
  filters?: ReactNode
  /** The search box: stays in view on phones and tablets. */
  search?: ReactNode
  /** Filters that are on: the count and chips on phones and tablets. */
  activeFilters?: ActiveFilter[]
  onClearFilters?: () => void
  /** Space between the filters and the table (default gap-4, as most pages had). */
  filtersGap?: string
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
  id, columns, rows, rowKey, loading, error, onRetry, empty, onRowClick, rowClassName, sort, onSort, paging, exportUrl, csvName, canExport, filters, search, activeFilters, onClearFilters, filtersGap = 'gap-4', minWidth = '720px',
}: Props<T>) {
  const { t } = useTranslation()
  const phone = useMediaQuery('(max-width: 639px)')
  const compact = useCompactToolbar()
  const hasFilters = filters !== undefined || search !== undefined
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
        <a href={exportUrl} download className={buttonClass('secondary', 'md', 'min-h-8 px-3 pointer-coarse:min-h-11')}>
          <Download aria-hidden />
          {t('table.excel')}
        </a>
      )}
      {!exportUrl && csvName && canExport && rows.length > 0 && (
        <Button variant="secondary" onClick={download} className="min-h-8 px-3 pointer-coarse:min-h-11">
          <Download aria-hidden />
          {t('table.excel')}
        </Button>
      )}
      {columns.some((c) => c.hideable !== false) && (
        // The template's "View" menu: tick the columns to show; it stays open while ticking.
        <DropdownMenu modal={false}>
          <DropdownMenuTrigger asChild>
            <Button variant="secondary" className="min-h-8 px-3 pointer-coarse:min-h-11">
              <SlidersHorizontal aria-hidden />
              {t('table.columns')}
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-52">
            <DropdownMenuLabel>{t('table.columns')}</DropdownMenuLabel>
            <DropdownMenuSeparator />
            {columns
              .filter((c) => c.hideable !== false)
              .map((c) => (
                <DropdownMenuCheckboxItem key={c.key} checked={!hidden.includes(c.key)} onCheckedChange={() => toggle(c.key)} onSelect={(e) => e.preventDefault()}>
                  {c.label}
                </DropdownMenuCheckboxItem>
              ))}
          </DropdownMenuContent>
        </DropdownMenu>
      )}
    </div>
  )

  const table = (
    <div className="flex flex-col gap-2">
      {/* Phones and tablets with filters: Excel and Columns fold away with them. */}
      {!(compact && hasFilters) && <div className="flex flex-wrap items-end justify-end gap-2">{tools}</div>}

      {phone ? (
        <div className="flex flex-col gap-2">
          {visible.map((r) => (
            <Card key={rowKey(r)} className={cn('flex flex-col gap-1 p-4 text-sm', onRowClick && 'cursor-pointer', rowClassName?.(r))} onClick={onRowClick ? () => onRowClick(r) : undefined}>
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
        // The template's table: rounded border, 40px header, rows that light up on hover.
        <div data-slot="table-container" className="relative w-full overflow-x-auto rounded-md border bg-surface">
          <table className="w-full caption-bottom text-left text-sm" style={{ minWidth }}>
            <thead className="[&_tr]:border-b">
              <tr className="border-b">
                {shown.map((c) => {
                  const on = activeSort?.by === (sortable(c) ? sortKey(c) : '')
                  return (
                    <th key={c.key} scope="col" aria-sort={on ? (activeSort!.dir === 'asc' ? 'ascending' : 'descending') : undefined} className={cn('h-10 px-2 align-middle font-medium whitespace-nowrap text-text first:ps-4 last:pe-4', c.align === 'right' && 'text-right', c.className)}>
                      {sortable(c) ? (
                        <button
                          type="button"
                          onClick={() => clickSort(c)}
                          className={cn(
                            'inline-flex h-8 items-center gap-2 rounded-md px-3 outline-none transition-colors hover:bg-sunken focus-visible:ring-[3px] focus-visible:ring-focus/50 pointer-coarse:h-11',
                            c.align === 'right' ? '-me-3 flex-row-reverse' : '-ms-3',
                          )}
                        >
                          {c.label}
                          {on ? activeSort!.dir === 'asc' ? <ArrowUp aria-hidden className="size-4" /> : <ArrowDown aria-hidden className="size-4" /> : <ChevronsUpDown aria-hidden className="size-4 text-muted" />}
                        </button>
                      ) : (
                        c.label
                      )}
                    </th>
                  )
                })}
              </tr>
            </thead>
            <tbody className="[&_tr:last-child]:border-0">
              {visible.map((r) => (
                <tr key={rowKey(r)} onClick={onRowClick ? () => onRowClick(r) : undefined} className={cn('border-b transition-colors hover:bg-sunken/50', onRowClick && 'cursor-pointer', rowClassName?.(r))}>
                  {shown.map((c) => (
                    <td key={c.key} className={cn('p-2 align-middle first:ps-4 last:pe-4', c.align === 'right' && 'text-right tabular-nums', c.className)}>
                      {c.render(r)}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
          {loading && rows.length === 0 && <p className="p-6 text-center text-muted">{t('common.loading')}</p>}
          {!loading && rows.length === 0 && (error ? <LoadFailed error={error} onRetry={onRetry} /> : <div className="h-24 p-6 text-center text-muted">{empty ?? t('table.empty')}</div>)}
        </div>
      )}
      {phone && !loading && rows.length === 0 && (error ? <LoadFailed error={error} onRetry={onRetry} /> : <div className="p-6 text-center text-muted">{empty ?? t('table.empty')}</div>)}

      {pager && (pager.last > 1 || (pager.total ?? 0) > 25) && (
        <div className="flex flex-wrap items-center justify-end gap-x-6 gap-y-2 px-2 text-sm">
          <label className="flex items-center gap-2 font-medium">
            {t('table.perPage')}
            <select className={cn(selectClass, 'min-h-8 w-[4.5rem] ps-2 pe-2 pointer-coarse:min-h-11')} value={pager.perPage} onChange={(e) => pager.onPerPage(Number(e.target.value))}>
              {PER_PAGE.map((n) => (
                <option key={n} value={n}>
                  {n}
                </option>
              ))}
            </select>
          </label>
          <span className="font-medium">{t('common.pageOf', { page: pager.page, last: pager.last })}</span>
          <Button variant="secondary" disabled={pager.page <= 1} onClick={() => pager.onPage(pager.page - 1)} className="min-h-8 px-3 pointer-coarse:min-h-11">
            {t('common.previous')}
          </Button>
          <Button variant="secondary" disabled={pager.page >= pager.last} onClick={() => pager.onPage(pager.page + 1)} className="min-h-8 px-3 pointer-coarse:min-h-11">
            {t('common.next')}
          </Button>
        </div>
      )}
    </div>
  )

  if (!hasFilters) return table
  return (
    <div className={cn('flex flex-col', filtersGap)}>
      <CollapsibleToolbar id={id} search={search} active={activeFilters} onClearAll={onClearFilters} tools={tools}>
        {filters ?? <ToolbarSearch />}
      </CollapsibleToolbar>
      {table}
    </div>
  )
}
