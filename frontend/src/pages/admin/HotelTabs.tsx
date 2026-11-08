import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { BedDoubleIcon, DownloadIcon, EyeIcon, UploadIcon, UsersIcon } from 'lucide-react'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { Select } from '@/components/ui/Select'
import { StatTile } from '@/components/ui/StatTile'
import { ApiError } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { date, money, dateTime } from '@/lib/format'
import { useAddNote, useHotelLive, useHotelOverview, useRemoveNote, type HotelLive } from '@/lib/platform'
import { TextArea } from '@/pages/help/HelpPage'

const errorOf = (e: unknown) => (e instanceof ApiError ? e.message : e ? String(e) : null)
const when = (v: string | null) => (v ? dateTime(v) : '—')

/** Plan usage bars (rooms, branches, staff against the plan or gift limit). */
export function PlanUsage({ hotelId }: { hotelId: number }) {
  const { t } = useTranslation()
  const o = useHotelOverview(hotelId)
  if (!o.data) return null
  return (
    <Card className="flex flex-col gap-3">
      <h2 className="font-semibold">{t('hotelTabs.usage')}</h2>
      <ul className="grid gap-4 sm:grid-cols-3">
        {(['rooms', 'branches', 'staff'] as const).map((k) => {
          const { used, limit } = o.data.data.usage[k]
          const pct = limit ? Math.min(100, Math.round((used * 100) / limit)) : 0
          return (
            <li key={k} className="flex flex-col gap-1.5 text-sm">
              <span className="flex justify-between">
                <span>{t(`switches.keys.limit.${k}`)}</span>
                <span className="font-medium">{limit ? `${used} / ${limit}` : `${used} · ${t('admin.plans.unlimited')}`}</span>
              </span>
              <span className="h-1.5 overflow-hidden rounded-full bg-sunken" aria-hidden>
                <span className={`block h-full rounded-full ${pct >= 90 ? 'bg-danger' : 'bg-accent'}`} style={{ width: `${limit ? pct : 0}%` }} />
              </span>
            </li>
          )
        })}
      </ul>
    </Card>
  )
}

/** Live data: asks why first (logged, also in the hotel's own activity log). */
export function LiveData({ hotelId, currency }: { hotelId: number; currency: string }) {
  const { t } = useTranslation()
  const live = useHotelLive(hotelId)
  const [reason, setReason] = useState('')
  const [data, setData] = useState<HotelLive | null>(null)
  const load = (e?: FormEvent) => {
    e?.preventDefault()
    live.mutate(reason.trim(), { onSuccess: (r) => setData(r.data) })
  }

  if (!data)
    return (
      <Card>
        <form onSubmit={load} className="flex max-w-lg flex-col gap-3">
          <p className="text-sm text-muted">{t('hotelTabs.liveWhy')}</p>
          {live.error && <Alert>{errorOf(live.error)}</Alert>}
          <Field label={t('hotelTabs.reason')} value={reason} minLength={3} maxLength={200} required onChange={(e) => setReason(e.target.value)} />
          <div>
            <Button type="submit" disabled={live.isPending || reason.trim().length < 3}>
              <EyeIcon aria-hidden className="size-4" />
              {t('hotelTabs.showLive')}
            </Button>
          </div>
        </form>
      </Card>
    )

  const d = data.today
  const pay = Object.entries(d.payments)
  return (
    <div className="flex flex-col gap-4">
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatTile label={t('hotelTabs.occupancy')} value={d.occupancy} icon={BedDoubleIcon} footer={t('hotelTabs.roomsLine', { occupied: d.occupied, rooms: d.rooms })} />
        <StatTile label={t('hotelTabs.arrivals')} value={d.arrivals} icon={DownloadIcon} tone="info" />
        <StatTile label={t('hotelTabs.departures')} value={d.departures} icon={UploadIcon} tone="warn" />
        <StatTile label={t('hotelTabs.staffOnline')} value={d.staff_online} icon={UsersIcon} tone="ok" />
      </div>
      <Card className="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm">
        <span>
          {t('hotelTabs.bookingsMade')}: <strong>{d.bookings_made}</strong>
        </span>
        <span>
          {t('hotelTabs.paymentsToday')}: <strong>{pay.length ? pay.map(([c, v]) => money(v, c)).join(' · ') : money('0', currency)}</strong>
        </span>
        <Button variant="ghost" className="ml-auto" onClick={() => load()} disabled={live.isPending}>
          {t('system.checkNow')}
        </Button>
      </Card>
      <Card className="flex flex-col gap-2">
        <h2 className="text-sm font-semibold">{t('hotelTabs.latestActivity')}</h2>
        {data.activity.length === 0 ? (
          <p className="text-sm text-muted">{t('common.nothingYet')}</p>
        ) : (
          <ul className="flex flex-col divide-y divide-border text-sm">
            {data.activity.map((a) => (
              <li key={a.id} className="flex flex-wrap justify-between gap-2 py-2">
                <span>
                  {a.description}
                  {a.user && <span className="text-muted"> · {a.user}</span>}
                </span>
                <span className="text-xs text-muted">{when(a.at)}</span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}

export function StaffAndDevices({ hotelId }: { hotelId: number }) {
  const { t } = useTranslation()
  const o = useHotelOverview(hotelId)
  if (o.isPending) return <p className="text-sm text-muted">{t('common.loading')}</p>
  if (!o.data) return <Alert>{errorOf(o.error)}</Alert>
  const { staff, devices } = o.data.data
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card className="flex flex-col gap-2">
        <h2 className="text-sm font-semibold">{t('hotelTabs.staff', { count: staff.length })}</h2>
        <ul className="flex flex-col divide-y divide-border text-sm">
          {staff.map((s) => (
            <li key={s.id} className="flex items-center justify-between gap-2 py-2">
              <span className="min-w-0">
                <span className="block truncate font-medium">{s.name}</span>
                <span className="block truncate text-xs text-muted">
                  {s.role ?? '—'} · {s.last_login_at ? t('admin.hotel.lastLogin', { date: date(s.last_login_at) }) : t('admin.hotel.notSignedInYet')}
                </span>
              </span>
              <span className="flex shrink-0 gap-1">
                {s.status !== 'active' && <Badge tone="grey">{t('common.inactive')}</Badge>}
                {s.two_factor && <Badge tone="ok">{t('platformTeam.twoStep')}</Badge>}
              </span>
            </li>
          ))}
        </ul>
      </Card>
      <Card className="flex flex-col gap-2">
        <h2 className="text-sm font-semibold">{t('hotelTabs.devices', { count: devices.length })}</h2>
        {devices.length === 0 ? (
          <p className="text-sm text-muted">{t('common.nothingYet')}</p>
        ) : (
          <ul className="flex flex-col divide-y divide-border text-sm">
            {devices.map((d) => (
              <li key={d.id} className="flex items-center justify-between gap-2 py-2">
                <span className="min-w-0">
                  <span className="block truncate font-medium">{d.name}</span>
                  <span className="block truncate text-xs text-muted">
                    {d.user} · {when(d.last_seen_at)}
                  </span>
                </span>
                <Badge tone={d.status === 'active' ? 'ok' : d.status === 'pending' ? 'warn' : 'grey'}>{t(`security.statuses.${d.status}`)}</Badge>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}

export function NotesAndAccess({ hotelId }: { hotelId: number }) {
  const { t } = useTranslation()
  const o = useHotelOverview(hotelId)
  const add = useAddNote(hotelId)
  const remove = useRemoveNote(hotelId)
  const me = useMe()
  const [f, setF] = useState({ body: '', channel: '' })
  if (o.isPending) return <p className="text-sm text-muted">{t('common.loading')}</p>
  if (!o.data) return <Alert>{errorOf(o.error)}</Alert>
  const { notes, access } = o.data.data
  const myName = me.data?.user.name
  const owner = me.data?.platform?.role === 'owner'

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card className="flex flex-col gap-3">
        <h2 className="text-sm font-semibold">{t('hotelTabs.notes')}</h2>
        <form
          onSubmit={(e) => {
            e.preventDefault()
            add.mutate({ body: f.body.trim(), channel: f.channel || null }, { onSuccess: () => setF({ body: '', channel: '' }) })
          }}
          className="flex flex-col gap-2"
        >
          {add.error && <Alert>{errorOf(add.error)}</Alert>}
          <TextArea label={t('hotelTabs.newNote')} value={f.body} rows={3} onChange={(body) => setF({ ...f, body })} />
          <div className="flex items-end gap-2">
            <Select label={t('hotelTabs.channel')} value={f.channel} onChange={(e) => setF({ ...f, channel: e.target.value })}>
              <option value="">—</option>
              {['phone', 'visit', 'telegram', 'email', 'other'].map((c) => (
                <option key={c} value={c}>
                  {t(`hotelTabs.channels.${c}`)}
                </option>
              ))}
            </Select>
            <Button type="submit" disabled={add.isPending || !f.body.trim()}>
              {t('hotelTabs.addNote')}
            </Button>
          </div>
        </form>
        <ul className="flex flex-col gap-2">
          {notes.map((n) => (
            <li key={n.id} className="rounded-lg bg-sunken px-3 py-2 text-sm">
              <p className="whitespace-pre-wrap">{n.body}</p>
              <p className="mt-1 flex items-center justify-between gap-2 text-xs text-muted">
                <span>
                  {n.author} · {when(n.created_at)}
                  {n.channel && ` · ${t(`hotelTabs.channels.${n.channel}`)}`}
                </span>
                {(owner || n.author === myName) && (
                  <button type="button" className="min-h-touch px-2 hover:text-danger-text" disabled={remove.isPending} onClick={() => remove.mutate(n.id)}>
                    {t('switches.remove')}
                  </button>
                )}
              </p>
            </li>
          ))}
        </ul>
      </Card>
      <Card className="flex flex-col gap-2">
        <h2 className="text-sm font-semibold">{t('hotelTabs.access')}</h2>
        <p className="text-xs text-muted">{t('hotelTabs.accessHint')}</p>
        {access.length === 0 ? (
          <p className="text-sm text-muted">{t('common.nothingYet')}</p>
        ) : (
          <ul className="flex flex-col divide-y divide-border text-sm">
            {access.map((a) => (
              <li key={a.id} className="flex flex-col py-2">
                <span>
                  <strong>{a.by}</strong> · {t(`hotelTabs.what.${a.what}`)}
                </span>
                <span className="text-xs text-muted">
                  {a.reason} · {when(a.at)}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}
