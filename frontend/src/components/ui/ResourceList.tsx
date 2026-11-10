import { useState, type FormEvent, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, SquarePen, Trash2 } from 'lucide-react'
import { ApiError } from '@/lib/api'
import { useSettingsDelete, useSettingsList, useSettingsSave } from '@/lib/settings'
import { Alert } from './Alert'
import { Button } from './Button'
import { Card } from './Card'
import { DataTable, type Column } from './DataTable'
import type { ActiveFilter } from './CollapsibleToolbar'
import { useCompactToolbar } from '@/lib/compactToolbar'
import { Field } from './Field'
import { Select } from './Select'

type Value = string | number | boolean | null | string[] | number[]
type Row = { id?: number } & Record<string, unknown>

export type FieldDef = {
  name: string
  label: string
  type?: 'text' | 'number' | 'decimal' | 'time' | 'date' | 'select' | 'checkbox' | 'tags' | 'days'
  options?: { value: string; label: string }[]
  hint?: string
  /** Empty input is sent as null. */
  nullable?: boolean
  wide?: boolean
  /** Hide the field (and send null) unless this returns true. */
  showIf?: (values: Record<string, Value>) => boolean
}

/** `value`: what to sort and download by (default: the shown text when it is plain text). */
export type ColumnDef = { label: string; render: (row: Row) => ReactNode; value?: (row: Row) => string | number | null | undefined }

/**
 * A Filament-style "resource" for simple settings lists: a table with
 * add / edit (inline form) and delete.
 */
export function ResourceList({
  endpoint,
  title,
  description,
  addLabel,
  columns,
  fields,
  defaults,
  canDelete = () => true,
  header,
  filters,
  activeFilters,
  query = '',
}: {
  endpoint: string
  title: string
  description?: string
  addLabel: string
  columns: ColumnDef[]
  fields: FieldDef[]
  defaults: Record<string, Value>
  canDelete?: (row: Row) => boolean
  header?: ReactNode
  /**
   * List filters (e.g. the branch). Desktop: shown above `header`. Phones and tablets: they fold behind the
   * table's "Filters" button with Excel and Columns (P19); `activeFilters` are the chips shown meanwhile.
   */
  filters?: ReactNode
  activeFilters?: ActiveFilter[]
  /** Extra list filter, e.g. "?branch_id=2" (saving still uses the plain endpoint). */
  query?: string
}) {
  const { t } = useTranslation()
  const compact = useCompactToolbar()
  const list = useSettingsList<Row>(endpoint + query)
  const remove = useSettingsDelete(endpoint)
  const [editing, setEditing] = useState<Row | null>(null)
  const deleteError = remove.error instanceof ApiError ? Object.values(remove.error.errors)[0]?.[0] ?? remove.error.message : null

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h2 className="text-lg font-medium">{title}</h2>
          {description && <p className="max-w-prose text-sm text-muted">{description}</p>}
        </div>
        {!editing && (
          <Button onClick={() => setEditing({ ...defaults })}>
            <Plus aria-hidden />
            {addLabel}
          </Button>
        )}
      </div>
      {!compact && filters}
      {header}
      {deleteError && <Alert>{deleteError}</Alert>}
      {editing && <RecordForm key={editing.id ?? 'new'} endpoint={endpoint} fields={fields} record={editing} onDone={() => setEditing(null)} />}

      <DataTable<Row>
        id={`settings${endpoint}`}
        filters={compact && filters ? filters : undefined}
        activeFilters={activeFilters}
        rows={list.data?.data ?? []}
        rowKey={(row) => String(row.id)}
        loading={list.isPending}
        error={list.error}
        onRetry={() => list.refetch()}
        empty={t('common.nothingYet')}
        csvName={title}
        canExport
        minWidth="520px"
        columns={[
          ...columns.map((c, i) => {
            // Sort and download by the column's text (or its own value).
            const value = c.value ?? ((row: Row) => {
              const v = c.render(row)
              return typeof v === 'string' || typeof v === 'number' ? v : undefined
            })
            return { key: `c${i}`, label: c.label, render: c.render, sortValue: value, csv: value, hideable: i > 0 } satisfies Column<Row>
          }),
          {
            key: 'actions', label: '', hideable: false, align: 'right' as const,
            render: (row: Row) => (
              <div className="flex justify-end">
                <button
                  type="button"
                  aria-label={t('common.edit')}
                  onClick={() => setEditing(row)}
                  className="flex size-touch items-center justify-center rounded-md text-muted transition-colors hover:bg-sunken hover:text-text"
                >
                  <SquarePen aria-hidden className="size-4" />
                </button>
                {canDelete(row) && (
                  <button
                    type="button"
                    aria-label={t('common.delete')}
                    disabled={remove.isPending}
                    onClick={() => row.id && remove.mutate(row.id)}
                    className="flex size-touch items-center justify-center rounded-md text-muted transition-colors hover:bg-danger-bg hover:text-danger-text"
                  >
                    <Trash2 aria-hidden className="size-4" />
                  </button>
                )}
              </div>
            ),
          },
        ]}
      />
    </div>
  )
}

function RecordForm({ endpoint, fields, record, onDone }: { endpoint: string; fields: FieldDef[]; record: Row; onDone: () => void }) {
  const { t } = useTranslation()
  const save = useSettingsSave<Row>(endpoint)
  const [values, setValues] = useState<Record<string, Value>>(() =>
    Object.fromEntries(fields.map((f) => [f.name, normalise(record[f.name] as Value, f)])),
  )
  const error = save.error instanceof ApiError ? save.error : null
  const set = (name: string, v: Value) => setValues((cur) => ({ ...cur, [name]: v }))

  function submit(e: FormEvent) {
    e.preventDefault()
    const out: Row = { id: record.id }
    for (const f of fields) {
      const v = values[f.name]
      if (f.showIf && !f.showIf(values)) {
        out[f.name] = null
        continue
      }
      if (f.type === 'tags') {
        out[f.name] = String(v ?? '').split(',').map((x) => x.trim()).filter(Boolean)
        continue
      }
      out[f.name] = f.nullable && (v === '' || v === undefined) ? null : f.type === 'number' && v !== '' && v !== null ? Number(v) : v
    }
    save.mutate(out, { onSuccess: onDone })
  }

  return (
    <Card className="animate-page-in">
      <form onSubmit={submit} noValidate className="grid gap-4 sm:grid-cols-2">
        {save.error && !error && <Alert>{t('common.serverUnreachable')}</Alert>}
        {fields.map((f) => {
          const err = error?.field(f.name)
          const cls = f.wide ? 'sm:col-span-2' : ''
          if (f.showIf && !f.showIf(values)) return null
          if (f.type === 'days') {
            const picked = (values[f.name] as number[] | '') || []
            return (
              <fieldset key={f.name} className={`flex flex-col gap-1.5 ${cls}`}>
                <legend className="mb-2 text-sm leading-none font-medium">{f.label}</legend>
                <div className="flex flex-wrap gap-1.5">
                  {[1, 2, 3, 4, 5, 6, 7].map((d) => {
                    const on = picked.includes(d)
                    return (
                      <button
                        key={d}
                        type="button"
                        aria-pressed={on}
                        onClick={() => set(f.name, on ? picked.filter((x) => x !== d) : [...picked, d].sort())}
                        className={`min-h-touch min-w-12 rounded-md border px-2 text-sm font-medium shadow-xs transition-colors ${on ? 'border-primary bg-primary text-primary-foreground' : 'bg-background text-muted hover:bg-sunken hover:text-text dark:bg-input/30'}`}
                      >
                        {t(`common.weekdays.${d}`)}
                      </button>
                    )
                  })}
                </div>
                {err && <p className="text-sm text-danger-text">{err}</p>}
              </fieldset>
            )
          }
          if (f.type === 'checkbox') {
            return (
              <label key={f.name} className={`flex min-h-touch items-start gap-3 text-sm ${cls}`}>
                <input type="checkbox" className="mt-0.5" checked={!!values[f.name]} onChange={(e) => set(f.name, e.target.checked)} />
                <span className="flex flex-col">
                  <span className="font-medium">{f.label}</span>
                  {f.hint && <span className="text-muted">{f.hint}</span>}
                </span>
              </label>
            )
          }
          if (f.type === 'select') {
            return (
              <div key={f.name} className={cls}>
                <Select label={f.label} value={String(values[f.name] ?? '')} hint={f.hint} error={err} onChange={(e) => set(f.name, e.target.value)}>
                  {f.options?.map((o) => (
                    <option key={o.value} value={o.value}>
                      {o.label}
                    </option>
                  ))}
                </Select>
              </div>
            )
          }
          return (
            <div key={f.name} className={cls}>
              <Field
                label={f.label}
                type={f.type === 'time' || f.type === 'date' ? f.type : f.type === 'number' ? 'number' : 'text'}
                inputMode={f.type === 'decimal' ? 'decimal' : f.type === 'number' ? 'numeric' : undefined}
                value={String(values[f.name] ?? '')}
                hint={f.hint}
                error={err}
                onChange={(e) => set(f.name, e.target.value)}
              />
            </div>
          )
        })}
        <div className="flex justify-end gap-2 sm:col-span-2">
          <Button variant="ghost" onClick={onDone}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={save.isPending}>
            {t('common.save')}
          </Button>
        </div>
      </form>
    </Card>
  )
}

function normalise(v: Value | undefined, f: FieldDef): Value {
  if (v === undefined || v === null) return f.type === 'checkbox' ? false : f.type === 'days' ? [] : ''
  if (f.type === 'time' && typeof v === 'string') return v.slice(0, 5)
  if (f.type === 'date' && typeof v === 'string') return v.slice(0, 10)
  if (f.type === 'tags' && Array.isArray(v)) return v.join(', ')
  return v
}
