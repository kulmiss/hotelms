import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CopyIcon, RefreshCwIcon, TrashIcon } from 'lucide-react'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { Select } from '@/components/ui/Select'
import { api, ApiError, post } from '@/lib/api'
import { date, dateTime } from '@/lib/format'

type Conflict = { key: string; starts_on: string; ends_on: string; code: string | null; text: string }
type Feed = {
  id: number
  name: string
  room_id: number | null
  room_type_id: number | null
  room: string | null
  room_type: string | null
  is_active: boolean
  host: string | null
  last_synced_at: string | null
  last_status: 'ok' | 'error' | null
  last_error: string | null
  events_count: number
  conflicts: Conflict[]
}
type SyncRoom = { id: number; number: string; room_type_id: number; room_type: string | null; export_url: string | null }
type SyncData = { data: Feed[]; rooms: SyncRoom[]; limit: { used: number; max: number | null }; every_minutes: number }
type SyncResult = { ok: boolean; events: number; conflicts: number; error: string | null }

const KEY = ['settings', '/settings/calendar-sync']

/** Settings → Calendar sync (owner, 14 Nov 2026): Airbnb / Booking.com links in, each room's secret link out. */
export function CalendarSyncSettings() {
  const { t } = useTranslation()
  const q = useQuery({ queryKey: KEY, queryFn: () => api<SyncData>('/settings/calendar-sync') })
  const data = q.data
  const noPlan = data?.limit.max === 0

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h2 className="text-lg font-semibold">{t('settings.sections.calendar-sync')}</h2>
        <p className="max-w-3xl text-sm text-muted">{t('calendarSync.help', { minutes: data?.every_minutes ?? 15 })}</p>
        <p className="max-w-3xl text-sm text-muted">{t('calendarSync.bookingCom')}</p>
        {data && data.limit.max !== null && data.limit.max > 0 && <p className="text-sm text-muted">{t('calendarSync.limit', { used: data.limit.used, max: data.limit.max })}</p>}
      </div>
      {noPlan && <Alert tone="info">{t('calendarSync.notInPlan')}</Alert>}
      {data && !noPlan && <AddFeed rooms={data.rooms} />}
      {data && data.data.length > 0 && (
        <Card className="flex flex-col divide-y divide-border p-0">
          {data.data.map((f) => (
            <FeedRow key={f.id} f={f} />
          ))}
        </Card>
      )}
      {data && !noPlan && <ExportLinks rooms={data.rooms} />}
    </div>
  )
}

function AddFeed({ rooms }: { rooms: SyncRoom[] }) {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const [name, setName] = useState('Airbnb')
  const [url, setUrl] = useState('')
  const [target, setTarget] = useState('')
  const types = [...new Map(rooms.map((r) => [r.room_type_id, r.room_type ?? ''])).entries()]
  const add = useMutation({
    mutationFn: () => {
      const [kind, id] = target.split(':')
      return post<{ sync: SyncResult }>('/settings/calendar-sync', { name, url, room_id: kind === 'r' ? Number(id) : null, room_type_id: kind === 't' ? Number(id) : null })
    },
    onSuccess: () => {
      setUrl('')
      void qc.invalidateQueries({ queryKey: KEY })
    },
  })
  const err = add.error instanceof ApiError ? add.error : null
  const result = add.data?.sync

  return (
    <Card className="flex flex-col gap-4">
      <h3 className="text-sm font-semibold">{t('calendarSync.add')}</h3>
      <div className="grid gap-4 md:grid-cols-[10rem_minmax(0,1fr)_14rem]">
        <Field label={t('calendarSync.name')} value={name} onChange={(e) => setName(e.target.value)} error={err?.field('name')} />
        <Field label={t('calendarSync.url')} placeholder="https://www.airbnb.com/calendar/ical/….ics" inputMode="url" value={url} onChange={(e) => setUrl(e.target.value.trim())} hint={t('calendarSync.urlHint')} error={err?.field('url')} />
        <Select label={t('calendarSync.for')} value={target} onChange={(e) => setTarget(e.target.value)} error={err?.field('room_id') ?? err?.field('room_type_id')}>
          <option value="">—</option>
          <optgroup label={t('calendarSync.rooms')}>
            {rooms.map((r) => (
              <option key={r.id} value={`r:${r.id}`}>
                {r.number} · {r.room_type}
              </option>
            ))}
          </optgroup>
          <optgroup label={t('calendarSync.roomTypes')}>
            {types.map(([id, n]) => (
              <option key={id} value={`t:${id}`}>
                {t('calendarSync.anyRoomOf', { type: n })}
              </option>
            ))}
          </optgroup>
        </Select>
      </div>
      {result && (result.ok ? <Alert tone="ok">{t('calendarSync.added', { count: result.events })}</Alert> : <Alert>{result.error}</Alert>)}
      <div>
        <Button disabled={!url || !target || !name || add.isPending} onClick={() => add.mutate()}>
          {t('calendarSync.addButton')}
        </Button>
      </div>
    </Card>
  )
}

function FeedRow({ f }: { f: Feed }) {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const refresh = () => void qc.invalidateQueries({ queryKey: KEY })
  const sync = useMutation({ mutationFn: () => post<{ sync: SyncResult }>(`/settings/calendar-sync/${f.id}/sync`, {}), onSettled: refresh })
  const remove = useMutation({ mutationFn: () => api(`/settings/calendar-sync/${f.id}`, { method: 'DELETE' }), onSuccess: refresh })
  const toggle = useMutation({ mutationFn: () => api(`/settings/calendar-sync/${f.id}`, { method: 'PUT', body: JSON.stringify({ name: f.name, room_id: f.room_id, room_type_id: f.room_type_id, is_active: !f.is_active }) }), onSuccess: refresh })
  const err = sync.error instanceof ApiError ? (Object.values(sync.error.errors)[0]?.[0] ?? sync.error.message) : null

  return (
    <div className="flex flex-col gap-2 p-4">
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-medium">{f.name}</span>
        <span className="text-sm text-muted">→ {f.room ? t('calendarSync.room', { number: f.room }) : t('calendarSync.anyRoomOf', { type: f.room_type })}</span>
        {!f.is_active ? <Badge tone="grey">{t('common.off')}</Badge> : f.last_status === 'error' ? <Badge tone="danger">{t('calendarSync.status.error')}</Badge> : f.last_status === 'ok' ? <Badge tone="ok">{t('calendarSync.status.ok')}</Badge> : <Badge tone="grey">{t('calendarSync.status.never')}</Badge>}
        {f.conflicts.length > 0 && <Badge tone="warn">{t('calendarSync.clashes', { count: f.conflicts.length })}</Badge>}
        <span className="ml-auto flex flex-wrap gap-2">
          <Button variant="secondary" disabled={sync.isPending || !f.is_active} onClick={() => sync.mutate()}>
            <RefreshCwIcon aria-hidden className="size-4" />
            {t('calendarSync.syncNow')}
          </Button>
          <Button variant="ghost" onClick={() => toggle.mutate()}>
            {f.is_active ? t('calendarSync.pause') : t('calendarSync.resume')}
          </Button>
          <Button variant="ghost" aria-label={t('calendarSync.remove', { name: f.name })} onClick={() => window.confirm(t('calendarSync.removeConfirm')) && remove.mutate()}>
            <TrashIcon aria-hidden className="size-4" />
          </Button>
        </span>
      </div>
      <p className="text-xs text-muted">
        {f.host} · {f.last_synced_at ? t('calendarSync.lastSync', { time: dateTime(f.last_synced_at), count: f.events_count }) : t('calendarSync.status.never')}
      </p>
      {f.last_status === 'error' && f.last_error && <p className="text-sm text-danger-text">{f.last_error}</p>}
      {err && <p className="text-sm text-danger-text">{err}</p>}
      {f.conflicts.length > 0 && (
        <ul className="flex flex-col gap-1 rounded-lg bg-warn-bg p-3 text-sm text-warn-text">
          {f.conflicts.map((c) => (
            <li key={c.key}>
              {date(c.starts_on)} – {date(c.ends_on)}: {c.text}
            </li>
          ))}
          <li className="text-xs">{t('calendarSync.clashHelp')}</li>
        </ul>
      )}
    </div>
  )
}

function ExportLinks({ rooms }: { rooms: SyncRoom[] }) {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const [copied, setCopied] = useState<number | null>(null)
  const make = useMutation({ mutationFn: (id: number) => post(`/settings/calendar-sync/rooms/${id}/export`, {}), onSuccess: () => void qc.invalidateQueries({ queryKey: KEY }) })
  const stop = useMutation({ mutationFn: (id: number) => api(`/settings/calendar-sync/rooms/${id}/export`, { method: 'DELETE' }), onSuccess: () => void qc.invalidateQueries({ queryKey: KEY }) })
  const err = make.error instanceof ApiError ? (Object.values(make.error.errors)[0]?.[0] ?? make.error.message) : null

  return (
    <Card className="flex flex-col gap-3">
      <div className="flex flex-col gap-1">
        <h3 className="text-sm font-semibold">{t('calendarSync.export')}</h3>
        <p className="text-sm text-muted">{t('calendarSync.exportHelp')}</p>
      </div>
      {err && <Alert>{err}</Alert>}
      <ul className="flex flex-col divide-y divide-border">
        {rooms.map((r) => (
          <li key={r.id} className="flex flex-wrap items-center gap-2 py-2">
            <span className="w-20 font-medium">{r.number}</span>
            {r.export_url ? (
              <>
                <code className="min-w-0 flex-1 truncate rounded bg-sunken px-2 py-1 text-xs">{r.export_url}</code>
                <Button variant="secondary" onClick={() => void navigator.clipboard?.writeText(r.export_url ?? '').then(() => setCopied(r.id))}>
                  <CopyIcon aria-hidden className="size-4" />
                  {copied === r.id ? t('calendarSync.copied') : t('calendarSync.copy')}
                </Button>
                <Button variant="ghost" onClick={() => window.confirm(t('calendarSync.resetConfirm')) && make.mutate(r.id)}>
                  {t('calendarSync.reset')}
                </Button>
                <Button variant="ghost" onClick={() => stop.mutate(r.id)}>
                  {t('calendarSync.stop')}
                </Button>
              </>
            ) : (
              <Button variant="secondary" disabled={make.isPending} onClick={() => make.mutate(r.id)}>
                {t('calendarSync.makeLink')}
              </Button>
            )}
          </li>
        ))}
      </ul>
    </Card>
  )
}
