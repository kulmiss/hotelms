import { useState, type ChangeEvent, type InputHTMLAttributes } from 'react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/cn'
import { EC_MONTHS, EC_MONTHS_AM, ecDaysInMonth, fromEthiopian, toEthiopian } from '@/lib/ethiopian'
import { gregorianDate } from '@/lib/format'
import { inputClass } from './formStyles'

type Props = Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> & { inputId: string; describedBy?: string }

/**
 * A date typed in the Ethiopian calendar: day, month (incl. Pagume) and year. The value stays a Gregorian
 * "YYYY-MM-DD" (what the server stores); the Gregorian date is shown under it so nobody is unsure.
 */
export function EcDateInput({ inputId, describedBy, value, onChange, min, max, required, disabled, className, name }: Props) {
  const { t, i18n } = useTranslation()
  const iso = typeof value === 'string' && /^\d{4}-\d{2}-\d{2}/.test(value) ? value.slice(0, 10) : ''
  const start = iso ? toEthiopian(iso) : null
  const [parts, setParts] = useState(() => ({ day: start?.day ?? 0, month: start?.month ?? 0, year: start?.year ?? 0 }))
  // Follow a value changed from outside (e.g. "check-out = check-in + 1").
  const shown = start && fromEthiopian(parts) !== iso ? start : parts
  const months = i18n.language === 'am' ? EC_MONTHS_AM : EC_MONTHS
  const days = shown.year && shown.month ? ecDaysInMonth(shown.year, shown.month) : 30

  function update(next: Partial<typeof parts>) {
    const p = { ...shown, ...next }
    const yearDone = p.year >= 1000 // still typing the year: don't shorten the day yet
    if (yearDone && p.month && p.day > ecDaysInMonth(p.year, p.month)) p.day = ecDaysInMonth(p.year, p.month)
    setParts(p)
    const out = p.day && p.month && yearDone ? fromEthiopian(p) : null
    onChange?.({ target: { value: out ?? '', name: name ?? '' } } as unknown as ChangeEvent<HTMLInputElement>)
  }
  const outOfRange = iso && ((typeof min === 'string' && min && iso < min) || (typeof max === 'string' && max && iso > max))

  return (
    <div className="flex flex-col gap-1">
      <div className="grid grid-cols-[minmax(0,4.5rem)_minmax(0,1fr)_minmax(0,5.5rem)] gap-2" role="group" aria-describedby={describedBy}>
        <select id={inputId} aria-label={t('calendar.day')} className={cn(inputClass, className)} value={shown.day || ''} required={required} disabled={disabled} onChange={(e) => update({ day: Number(e.target.value) })}>
          <option value="">–</option>
          {Array.from({ length: days }, (_, i) => i + 1).map((d) => <option key={d} value={d}>{d}</option>)}
        </select>
        <select aria-label={t('calendar.month')} className={cn(inputClass, className)} value={shown.month || ''} required={required} disabled={disabled} onChange={(e) => update({ month: Number(e.target.value) })}>
          <option value="">–</option>
          {months.map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
        </select>
        <input aria-label={t('calendar.year')} type="number" inputMode="numeric" min={1900} max={2100} className={cn(inputClass, className)} value={shown.year || ''} required={required} disabled={disabled} onChange={(e) => update({ year: Number(e.target.value) || 0 })} />
      </div>
      <p className={cn('text-xs', outOfRange ? 'text-danger-text' : 'text-muted')}>
        {iso ? `= ${gregorianDate(iso)}` : t('calendar.ecHint')}
        {outOfRange && ` · ${t('calendar.outOfRange')}`}
      </p>
    </div>
  )
}
