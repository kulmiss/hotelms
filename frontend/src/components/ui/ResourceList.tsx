import { useState, type FormEvent, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { PencilSquareIcon, TrashIcon } from '@heroicons/react/24/outline'
import { PlusIcon } from '@heroicons/react/20/solid'
import { ApiError } from '@/lib/api'
import { useSettingsDelete, useSettingsList, useSettingsSave } from '@/lib/settings'
import { Alert } from './Alert'
import { Button } from './Button'
import { Card } from './Card'
import { Field } from './Field'
import { Select } from './Select'

type Value = string | number | boolean | null
type Row = { id?: number } & Record<string, unknown>

export type FieldDef = {
  name: string
  label: string
  type?: 'text' | 'number' | 'decimal' | 'time' | 'select' | 'checkbox'
  options?: { value: string; label: string }[]
  hint?: string
  /** Empty input is sent as null. */
  nullable?: boolean
  wide?: boolean
}

export type ColumnDef = { label: string; render: (row: Row) => ReactNode }

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
}) {
  const { t } = useTranslation()
  const list = useSettingsList<Row>(endpoint)
  const remove = useSettingsDelete(endpoint)
  const [editing, setEditing] = useState<Row | null>(null)
  const deleteError = remove.error instanceof ApiError ? Object.values(remove.error.errors)[0]?.[0] ?? remove.error.message : null

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h2 className="text-base font-semibold">{title}</h2>
          {description && <p className="max-w-prose text-[13px] text-muted">{description}</p>}
        </div>
        {!editing && (
          <Button onClick={() => setEditing({ ...defaults })}>
            <PlusIcon aria-hidden className="size-4" />
            {addLabel}
          </Button>
        )}
      </div>
      {header}
      {deleteError && <Alert>{deleteError}</Alert>}
      {editing && <RecordForm key={editing.id ?? 'new'} endpoint={endpoint} fields={fields} record={editing} onDone={() => setEditing(null)} />}

      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[520px] text-left text-[13px]">
          <thead className="bg-sunken text-text">
            <tr>
              {columns.map((c) => (
                <th key={c.label} className="px-4 py-2.5 font-semibold">
                  {c.label}
                </th>
              ))}
              <th className="w-24 px-4 py-2.5">
                <span className="sr-only">{t('common.actions')}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {list.data?.data.map((row) => (
              <tr key={row.id} className="border-t border-border transition duration-75 hover:bg-sunken">
                {columns.map((c) => (
                  <td key={c.label} className="px-4 py-2">
                    {c.render(row)}
                  </td>
                ))}
                <td className="px-2 py-1">
                  <div className="flex justify-end">
                    <button
                      type="button"
                      aria-label={t('common.edit')}
                      onClick={() => setEditing(row)}
                      className="flex size-touch items-center justify-center rounded-lg text-icon hover:bg-sunken hover:text-text"
                    >
                      <PencilSquareIcon aria-hidden className="size-4" />
                    </button>
                    {canDelete(row) && (
                      <button
                        type="button"
                        aria-label={t('common.delete')}
                        disabled={remove.isPending}
                        onClick={() => row.id && remove.mutate(row.id)}
                        className="flex size-touch items-center justify-center rounded-lg text-icon hover:bg-danger-bg hover:text-danger-text"
                      >
                        <TrashIcon aria-hidden className="size-4" />
                      </button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {list.isPending && <p className="p-6 text-center text-muted">{t('common.loading')}</p>}
        {list.data?.data.length === 0 && <p className="p-6 text-center text-muted">{t('common.nothingYet')}</p>}
      </Card>
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
          if (f.type === 'checkbox') {
            return (
              <label key={f.name} className={`flex min-h-touch items-start gap-3 text-[13px] ${cls}`}>
                <input type="checkbox" className="mt-0.5 size-4 rounded accent-accent" checked={!!values[f.name]} onChange={(e) => set(f.name, e.target.checked)} />
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
                type={f.type === 'time' ? 'time' : f.type === 'number' ? 'number' : 'text'}
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
  if (v === undefined || v === null) return f.type === 'checkbox' ? false : ''
  if (f.type === 'time' && typeof v === 'string') return v.slice(0, 5)
  return v
}
