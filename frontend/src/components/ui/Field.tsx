import { useId, useState, type InputHTMLAttributes } from 'react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/cn'
import { formatEthiopian } from '@/lib/ethiopian'
import { calendarMode } from '@/lib/format'
import { EcDateInput } from './EcDateInput'
import { errorClass, hintClass, inputClass, labelClass } from './formStyles'

type FieldProps = InputHTMLAttributes<HTMLInputElement> & { label: string; hint?: string; error?: string }

/** Label + input + hint / error (template form item). */
export function Field({ label, hint, error, className, id, ...props }: FieldProps) {
  const autoId = useId()
  const inputId = id ?? autoId
  const hintId = hint ? `${inputId}-hint` : undefined
  const errorId = error ? `${inputId}-error` : undefined
  const { t, i18n } = useTranslation()
  const mode = props.type === 'date' ? calendarMode() : 'gregorian'
  const [ec, setEc] = useState(mode === 'ethiopian')
  const describedBy = [hintId, errorId].filter(Boolean).join(' ') || undefined
  const value = typeof props.value === 'string' ? props.value.slice(0, 10) : ''
  return (
    <div data-slot="form-item" className="flex min-w-0 flex-col gap-2">
      <label htmlFor={inputId} className={cn(labelClass, error && 'text-danger-text')}>
        {label}
      </label>
      {mode !== 'gregorian' && ec ? (
        <EcDateInput inputId={inputId} describedBy={describedBy} className={className} {...props} />
      ) : (
        <input
          id={inputId}
          aria-describedby={describedBy}
          aria-invalid={error ? true : undefined}
          // w-full + min-w-0: never wider than its column (a field next to a currency box overflowed its card)
          className={cn(inputClass, className)}
          {...props}
        />
      )}
      {mode === 'both' && (
        <p className="flex flex-wrap items-center gap-x-2 text-xs text-muted">
          {!ec && /^\d{4}-\d{2}-\d{2}$/.test(value) && <span>= {formatEthiopian(value, i18n.language)}</span>}
          <button type="button" className="tap-link font-medium text-accent-text underline" onClick={() => setEc(!ec)}>
            {ec ? t('calendar.useGregorian') : t('calendar.useEthiopian')}
          </button>
        </p>
      )}
      {error && (
        <p id={errorId} className={errorClass}>
          {error}
        </p>
      )}
      {hint && (
        <p id={hintId} className={hintClass}>
          {hint}
        </p>
      )}
    </div>
  )
}
