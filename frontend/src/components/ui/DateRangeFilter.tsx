import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Field } from '@/components/ui/Field'
import { Select } from '@/components/ui/Select'
import { DATE_PRESETS, presetOf, presetRange, type DatePreset, type DateRange } from '@/lib/dateRange'

/**
 * The same date filter on every list (owner, 2026-11-07): Any time, Today, Yesterday, This week,
 * This month, Last month, or From–To.
 */
export function DateRangeFilter({ value, onChange, label }: { value: DateRange; onChange: (r: DateRange) => void; label?: string }) {
  const { t } = useTranslation()
  const [custom, setCustom] = useState(presetOf(value) === 'custom')
  const preset: DatePreset = custom ? 'custom' : presetOf(value)

  return (
    <div className="flex flex-wrap items-end gap-2">
      <div className="w-40">
        <Select
          label={label ?? t('dates.label')}
          value={preset}
          onChange={(e) => {
            const p = e.target.value as DatePreset
            setCustom(p === 'custom')
            if (p !== 'custom') onChange(presetRange(p))
          }}
        >
          {DATE_PRESETS.map((p) => (
            <option key={p} value={p}>
              {t(`dates.${p}`)}
            </option>
          ))}
        </Select>
      </div>
      {preset === 'custom' && (
        <>
          <div className="w-40">
            <Field label={t('dates.from')} type="date" value={value.from ?? ''} max={value.to} onChange={(e) => onChange({ ...value, from: e.target.value || undefined })} />
          </div>
          <div className="w-40">
            <Field label={t('dates.to')} type="date" value={value.to ?? ''} min={value.from} onChange={(e) => onChange({ ...value, to: e.target.value || undefined })} />
          </div>
        </>
      )}
    </div>
  )
}
