import { useState } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { ClockIcon } from '@heroicons/react/24/outline'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button, buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Select } from '@/components/ui/Select'
import { ApiError } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { date } from '@/lib/format'
import { addDays, localDate, localTime, today } from '@/lib/front'
import { useCan, useStaff } from '@/lib/hotel'
import { useAttendanceReport, useAttendanceStatus, useClock, useManualAttendance, useSetPin } from '@/lib/modules'

const err = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : null)
const STATUS_TONES = { present: 'ok', late: 'warn', absent: 'danger', leave: 'grey' } as const

/** Attendance (optional module): clock in / out, my kiosk PIN, manual entry and the report. */
export function AttendancePage() {
  const { t } = useTranslation()
  const can = useCan()
  const status = useAttendanceStatus()
  const methods = status.data?.methods ?? []

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={t('nav.attendance')}
        subtitle={t('attendance.subtitle')}
        actions={
          methods.includes('kiosk_pin') && (
            <Link to="/kiosk" className={buttonClass('secondary')}>
              {t('attendance.openKiosk')}
            </Link>
          )
        }
      />
      <div className="grid items-start gap-6 lg:grid-cols-2">
        {methods.includes('self') && <ClockCard />}
        {methods.includes('kiosk_pin') && <PinCard hasPin={status.data?.has_pin ?? false} />}
      </div>
      {can('attendance.manual') && methods.includes('manual') && <ManualEntry />}
      {can('attendance.report') && <Report />}
    </div>
  )
}

function ClockCard() {
  const { t } = useTranslation()
  const status = useAttendanceStatus()
  const clock = useClock()
  const inAt = status.data?.clocked_in_at
  return (
    <Card className="flex flex-col items-start gap-3">
      <span className="flex size-12 items-center justify-center rounded-full bg-accent-soft">
        <ClockIcon aria-hidden className="size-6 text-accent-text" />
      </span>
      <p className="text-[13px] text-muted">{inAt ? t('attendance.inSince', { time: localTime(inAt) }) : t('attendance.notIn')}</p>
      {clock.error && <Alert>{err(clock.error)}</Alert>}
      <Button size="lg" variant={inAt ? 'secondary' : 'primary'} disabled={clock.isPending} onClick={() => clock.mutate(undefined)}>
        {inAt ? t('attendance.clockOut') : t('attendance.clockIn')}
      </Button>
    </Card>
  )
}

function PinCard({ hasPin }: { hasPin: boolean }) {
  const { t } = useTranslation()
  const me = useMe()
  const setPin = useSetPin()
  const [pin, setPinValue] = useState('')
  return (
    <Card className="flex flex-col gap-3">
      <h2 className="text-sm font-semibold">{t('attendance.myPin')}</h2>
      <p className="text-[13px] text-muted">{hasPin ? t('attendance.pinSet') : t('attendance.pinHelp')}</p>
      <Field label={t('attendance.newPin')} type="password" inputMode="numeric" maxLength={6} value={pin} onChange={(e) => setPinValue(e.target.value.replace(/\D/g, ''))} />
      {setPin.error && <Alert>{err(setPin.error)}</Alert>}
      {setPin.isSuccess && <Alert tone="ok">{t('attendance.pinSaved')}</Alert>}
      <div>
        <Button variant="secondary" disabled={pin.length < 4 || setPin.isPending} onClick={() => me.data && setPin.mutate({ userId: me.data.user.id, pin }, { onSuccess: () => setPinValue('') })}>
          {t('common.save')}
        </Button>
      </div>
    </Card>
  )
}

function ManualEntry() {
  const { t } = useTranslation()
  const staff = useStaff()
  const save = useManualAttendance()
  const [f, setF] = useState({ user_id: 0, date: today(), clock_in: '', clock_out: '', status: '', notes: '' })
  return (
    <Card className="flex flex-col gap-3">
      <div className="flex flex-col gap-1">
        <h2 className="text-sm font-semibold">{t('attendance.manualTitle')}</h2>
        <p className="text-[13px] text-muted">{t('attendance.manualHelp')}</p>
      </div>
      <div className="grid gap-3 sm:grid-cols-6">
        <Select label={t('shifts.person')} value={String(f.user_id || '')} onChange={(e) => setF({ ...f, user_id: Number(e.target.value) })}>
          <option value="">—</option>
          {staff.data?.data.map((s) => (
            <option key={s.id} value={s.id}>
              {s.name}
            </option>
          ))}
        </Select>
        <Field label={t('billing.date')} type="date" max={today()} value={f.date} onChange={(e) => setF({ ...f, date: e.target.value })} />
        <Field label={t('attendance.in')} type="time" value={f.clock_in} onChange={(e) => setF({ ...f, clock_in: e.target.value })} />
        <Field label={t('attendance.out')} type="time" value={f.clock_out} onChange={(e) => setF({ ...f, clock_out: e.target.value })} />
        <Select label={t('reservations.status')} value={f.status} onChange={(e) => setF({ ...f, status: e.target.value })}>
          <option value="">{t('attendance.auto')}</option>
          {(['present', 'late', 'absent', 'leave'] as const).map((s) => (
            <option key={s} value={s}>
              {t(`attendance.statuses.${s}`)}
            </option>
          ))}
        </Select>
        <Field label={t('guests.notes')} value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} />
      </div>
      {save.error && <Alert>{err(save.error)}</Alert>}
      {save.isSuccess && <Alert tone="ok">{t('common.saved')}</Alert>}
      <div className="flex justify-end">
        <Button
          disabled={!f.user_id || save.isPending}
          onClick={() => save.mutate({ user_id: f.user_id, date: f.date, clock_in: f.clock_in || undefined, clock_out: f.clock_out || undefined, status: f.status || undefined, notes: f.notes || undefined })}
        >
          {t('common.save')}
        </Button>
      </div>
    </Card>
  )
}

function Report() {
  const { t } = useTranslation()
  const [from, setFrom] = useState(addDays(today(), -6))
  const [to, setTo] = useState(today())
  const report = useAttendanceReport(from, to, true)
  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-end gap-3">
        <h2 className="mr-auto text-sm font-semibold">{t('attendance.report')}</h2>
        <div className="w-40">
          <Field label={t('settings.rates.from')} type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        </div>
        <div className="w-40">
          <Field label={t('settings.rates.to')} type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        </div>
      </div>
      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[560px] text-left text-[13px]">
          <thead className="bg-sunken">
            <tr>
              <th className="px-4 py-2.5 font-semibold">{t('shifts.person')}</th>
              <th className="px-4 py-2.5 text-right font-semibold">{t('attendance.statuses.present')}</th>
              <th className="px-4 py-2.5 text-right font-semibold">{t('attendance.statuses.late')}</th>
              <th className="px-4 py-2.5 text-right font-semibold">{t('attendance.statuses.absent')}</th>
              <th className="px-4 py-2.5 text-right font-semibold">{t('attendance.statuses.leave')}</th>
              <th className="px-4 py-2.5 text-right font-semibold">{t('attendance.hours')}</th>
            </tr>
          </thead>
          <tbody>
            {report.data?.summary.map((s) => (
              <tr key={s.user?.id} className="border-t border-border">
                <td className="px-4 py-2 font-medium">{s.user?.name}</td>
                <td className="px-4 py-2 text-right tabular-nums">{s.present}</td>
                <td className="px-4 py-2 text-right tabular-nums">{s.late}</td>
                <td className="px-4 py-2 text-right tabular-nums">{s.absent}</td>
                <td className="px-4 py-2 text-right tabular-nums">{s.leave}</td>
                <td className="px-4 py-2 text-right tabular-nums">{s.hours}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {report.data?.summary.length === 0 && <p className="p-6 text-center text-muted">{t('attendance.noRecords')}</p>}
      </Card>
      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[560px] text-left text-[13px]">
          <tbody>
            {report.data?.rows.map((r) => (
              <tr key={r.id} className="border-t border-border first:border-t-0">
                <td className="px-4 py-2">{date(r.date)}</td>
                <td className="px-4 py-2 font-medium">{r.user?.name}</td>
                <td className="px-4 py-2 tabular-nums">
                  {r.clock_in_at ? localTime(r.clock_in_at) : '—'} – {r.clock_out_at ? `${localDate(r.clock_out_at) !== r.date ? '+1 ' : ''}${localTime(r.clock_out_at)}` : '…'}
                </td>
                <td className="px-4 py-2">
                  <Badge tone={STATUS_TONES[r.status as keyof typeof STATUS_TONES] ?? 'grey'}>{t(`attendance.statuses.${r.status}`)}</Badge>
                </td>
                <td className="px-4 py-2 text-muted">{t(`attendance.methods.${r.method}`)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
    </div>
  )
}
