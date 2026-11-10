import { useId, type InputHTMLAttributes } from 'react'
import { cn } from '@/lib/cn'
import { errorClass, hintClass, inputClass, labelClass } from './formStyles'

type FieldProps = InputHTMLAttributes<HTMLInputElement> & { label: string; hint?: string; error?: string }

/** Label + input + hint / error (template form item). */
export function Field({ label, hint, error, className, id, ...props }: FieldProps) {
  const autoId = useId()
  const inputId = id ?? autoId
  const hintId = hint ? `${inputId}-hint` : undefined
  const errorId = error ? `${inputId}-error` : undefined
  return (
    <div data-slot="form-item" className="flex min-w-0 flex-col gap-2">
      <label htmlFor={inputId} className={cn(labelClass, error && 'text-danger-text')}>
        {label}
      </label>
      <input
        id={inputId}
        aria-describedby={[hintId, errorId].filter(Boolean).join(' ') || undefined}
        aria-invalid={error ? true : undefined}
        // w-full + min-w-0: never wider than its column (a field next to a currency box overflowed its card)
        className={cn(inputClass, className)}
        {...props}
      />
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
