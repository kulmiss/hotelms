import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { SILENT } from '@/lib/toast'
import { DownloadIcon, UploadIcon } from 'lucide-react'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button, buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Select } from '@/components/ui/Select'
import { api, ApiError, apiUrl, post, upload } from '@/lib/api'
import { cn } from '@/lib/cn'
import { date } from '@/lib/format'
import { inputClass } from '@/components/ui/formStyles'

type Parsed = { file_name: string; headers: string[]; rows: string[][]; columns: string[] }
type Row = Record<string, string>
type Report = { errors: Record<string, Record<string, string>>; general: string[]; summary: { rooms: number; new_types: string[] } }
type ImportRecord = { id: number; file_name: string | null; rooms: number; room_types: number; by: string | null; created_at: string; undone_at: string | null; can_undo: boolean }

const norm = (s: string) => s.toLowerCase().replace(/[^a-z0-9]+/g, '')

/** Guess which file column fills each template column (by name or translated label). */
function autoMap(columns: string[], headers: string[], label: (c: string) => string): Record<string, number | undefined> {
  const map: Record<string, number | undefined> = {}
  columns.forEach((c) => {
    const i = headers.findIndex((h) => norm(h) === norm(c) || norm(h) === norm(label(c)))
    if (i !== -1) map[c] = i
  })
  return map
}

/** Import room types and rooms from Excel / CSV: upload → match columns → preview and fix → import. */
export function ImportRooms({ onDone }: { onDone?: () => void }) {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const input = useRef<HTMLInputElement>(null)
  const [parsed, setParsed] = useState<Parsed | null>(null)
  const [map, setMap] = useState<Record<string, number | undefined>>({})
  const [rows, setRows] = useState<Row[] | null>(null)
  const label = (c: string) => t(`import.columns.${c}`)
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)
  const [checked, setChecked] = useState<Row[] | null>(null)

  const parse = useMutation({
    meta: SILENT,
    mutationFn: (file: File) => upload<Parsed>('/settings/import/parse', 'file', file),
    onSuccess: (p) => {
      setParsed(p)
      const m = autoMap(p.columns, p.headers, label)
      setMap(m)
      setRows(null)
      // Our own template: every column found → straight to the preview.
      if (p.columns.every((c) => m[c] !== undefined)) changeRows(build(p, m), true)
    },
  })
  const check = useMutation({ meta: SILENT, mutationFn: (r: Row[]) => post<{ data: Report }>('/settings/import/check', { rows: r }) })
  const save = useMutation({
    mutationFn: (r: Row[]) => post<{ data: ImportRecord }>('/settings/import', { rows: r, file_name: parsed?.file_name }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['settings'] })
      void qc.invalidateQueries({ queryKey: ['imports'] })
      void qc.invalidateQueries({ queryKey: ['setup'] })
    },
  })

  function build(p: Parsed, m: Record<string, number | undefined>): Row[] {
    return p.rows.map((r) => Object.fromEntries(p.columns.map((c) => {
      const i = m[c]
      return [c, i !== undefined ? (r[i] ?? '') : '']
    })))
  }

  /** Update the rows and check them again (right away, or shortly after typing stops). */
  function changeRows(next: Row[], now = false) {
    setRows(next)
    clearTimeout(timer.current)
    timer.current = setTimeout(() => check.mutate(next, { onSuccess: () => setChecked(next) }), now ? 0 : 400)
  }

  const report = check.data?.data
  const errorCount = report ? Object.keys(report.errors).length + report.general.length : 0
  const parseError = parse.error instanceof ApiError ? (parse.error.field('file') ?? parse.error.message) : null

  function reset() {
    clearTimeout(timer.current)
    setParsed(null)
    setRows(null)
    setChecked(null)
    check.reset()
    save.reset()
    parse.reset()
  }


  if (save.data) {
    return (
      <Card className="flex flex-col gap-3">
        <Alert tone="ok">{t('import.done', { rooms: save.data.data.rooms, types: save.data.data.room_types })}</Alert>
        <p className="text-sm text-muted">{t('import.undoHint')}</p>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={reset}>
            {t('import.another')}
          </Button>
          {onDone && <Button onClick={onDone}>{t('common.close')}</Button>}
        </div>
      </Card>
    )
  }

  return (
    <Card className="flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <h3 className="text-sm font-semibold">{t('import.title')}</h3>
        <p className="text-sm text-muted">{t('import.help')}</p>
      </div>

      {!parsed && (
        <div className="flex flex-wrap items-center gap-2">
          <a href={apiUrl('/settings/import/template')} className={buttonClass('secondary')} download>
            <DownloadIcon aria-hidden className="size-4" />
            {t('import.templateExcel')}
          </a>
          <a href={apiUrl('/settings/import/template?format=csv')} className={buttonClass('ghost')} download>
            {t('import.templateCsv')}
          </a>
          <input
            ref={input}
            type="file"
            accept=".xlsx,.csv"
            className="sr-only"
            aria-label={t('import.upload')}
            onChange={(e) => {
              const f = e.target.files?.[0]
              if (f) parse.mutate(f)
              e.target.value = ''
            }}
          />
          <Button disabled={parse.isPending} onClick={() => input.current?.click()}>
            <UploadIcon aria-hidden className="size-4" />
            {parse.isPending ? t('import.reading') : t('import.upload')}
          </Button>
        </div>
      )}
      {parseError && <Alert>{parseError}</Alert>}

      {/* Match columns (for files not made from the template) */}
      {parsed && !rows && (
        <div className="flex flex-col gap-3">
          <p className="text-sm text-muted">{t('import.matchHelp', { file: parsed.file_name, count: parsed.rows.length })}</p>
          <div className="grid gap-3 sm:grid-cols-3">
            {parsed.columns.map((c) => (
              <Select key={c} label={label(c)} value={String(map[c] ?? '')} onChange={(e) => setMap({ ...map, [c]: e.target.value === '' ? undefined : Number(e.target.value) })}>
                <option value="">{t('import.notInFile')}</option>
                {parsed.headers.map((h, i) => (
                  <option key={i} value={i}>
                    {h || t('import.columnN', { n: i + 1 })}
                  </option>
                ))}
              </Select>
            ))}
          </div>
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={reset}>
              {t('common.cancel')}
            </Button>
            <Button
              disabled={map.room_type === undefined}
              onClick={() => changeRows(build(parsed, map), true)}
            >
              {t('import.toPreview')}
            </Button>
          </div>
        </div>
      )}

      {/* Preview: every cell can be fixed here */}
      {parsed && rows && (
        <div className="flex flex-col gap-3">
          <div className="flex flex-wrap items-center gap-2 text-sm">
            <Badge tone="grey">{parsed.file_name}</Badge>
            {report && <Badge tone="info">{t('import.summaryRooms', { count: report.summary.rooms })}</Badge>}
            {report && report.summary.new_types.length > 0 && <Badge tone="accent">{t('import.summaryTypes', { list: report.summary.new_types.join(', ') })}</Badge>}
            {report && (errorCount ? <Badge tone="danger">{t('import.errors', { count: errorCount })}</Badge> : <Badge tone="ok">{t('import.noErrors')}</Badge>)}
          </div>
          {report?.general.map((g) => (
            <Alert key={g}>{g}</Alert>
          ))}
          <div className="max-h-[28rem] overflow-auto rounded-lg border">
            <table className="w-full min-w-[900px] text-left text-xs">
              <thead className="sticky top-0 z-10 bg-sunken">
                <tr>
                  <th className="px-2 py-2 font-medium">#</th>
                  {parsed.columns.map((c) => (
                    <th key={c} className="px-2 py-2 font-medium whitespace-nowrap">
                      {label(c)}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {rows.map((r, i) => {
                  const errs = report?.errors[String(i + 1)] ?? {}
                  return (
                    <tr key={i} className={cn('border-t border-border', Object.keys(errs).length > 0 && 'bg-danger-bg/40')}>
                      <td className="px-2 py-1 text-muted">{i + 1}</td>
                      {parsed.columns.map((c) => (
                        <td key={c} className="px-1 py-1 align-top">
                          <input
                            aria-label={`${label(c)} ${i + 1}`}
                            aria-invalid={errs[c] ? true : undefined}
                            title={errs[c]}
                            value={r[c] ?? ''}
                            onChange={(e) => changeRows(rows.map((x, j) => (j === i ? { ...x, [c]: e.target.value } : x)))}
                            className={cn(inputClass, 'h-8 min-h-8 w-full min-w-20 px-1.5', errs[c] && 'border-destructive')}
                          />
                          {errs[c] && <p className="mt-0.5 max-w-48 text-xs text-danger-text">{errs[c]}</p>}
                        </td>
                      ))}
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
          {save.error instanceof ApiError && <Alert>{save.error.message}</Alert>}
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={reset}>
              {t('common.cancel')}
            </Button>
            <Button disabled={!report || errorCount > 0 || check.isPending || save.isPending || rows !== checked} onClick={() => save.mutate(rows)}>
              {t('import.importN', { count: report?.summary.rooms ?? 0 })}
            </Button>
          </div>
        </div>
      )}
    </Card>
  )
}

/** Settings → Import: the importer plus the last imports with Undo (24 hours). */
export function ImportSettings() {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const list = useQuery({ queryKey: ['imports'], queryFn: () => api<{ data: ImportRecord[] }>('/settings/imports') })
  const undo = useMutation({
    mutationFn: (id: number) => post(`/settings/imports/${id}/undo`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['imports'] })
      void qc.invalidateQueries({ queryKey: ['settings'] })
    },
  })
  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h2 className="text-base font-semibold">{t('settings.sections.import')}</h2>
        <p className="max-w-prose text-sm text-muted">{t('import.sectionHelp')}</p>
      </div>
      <ImportRooms />
      <div className="flex flex-col gap-2">
        <h3 className="text-sm font-semibold">{t('import.history')}</h3>
        {undo.error instanceof ApiError && <Alert>{undo.error.field('import') ?? undo.error.message}</Alert>}
        <Card className="overflow-x-auto p-0">
          <table className="w-full min-w-[520px] text-left text-sm">
            <tbody>
              {list.data?.data.map((i) => (
                <tr key={i.id} className="border-t border-border first:border-t-0">
                  <td className="px-4 py-2 font-medium">{i.file_name ?? '—'}</td>
                  <td className="px-4 py-2 text-muted">{t('import.historyRow', { rooms: i.rooms, types: i.room_types })}</td>
                  <td className="px-4 py-2 text-muted">
                    {date(i.created_at)} · {i.by}
                  </td>
                  <td className="px-4 py-2 text-right">
                    {i.undone_at ? (
                      <Badge tone="grey">{t('import.undone')}</Badge>
                    ) : i.can_undo ? (
                      <Button variant="ghost" disabled={undo.isPending} onClick={() => undo.mutate(i.id)}>
                        {t('import.undo')}
                      </Button>
                    ) : null}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {list.data?.data.length === 0 && <p className="p-6 text-center text-muted">{t('import.noHistory')}</p>}
        </Card>
      </div>
    </div>
  )
}
