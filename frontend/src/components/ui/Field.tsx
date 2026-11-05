import { useId, type InputHTMLAttributes } from 'react'
import { cn } from '@/lib/cn'

type FieldProps = InputHTMLAttributes<HTMLInputElement> & { label: string; hint?: string }

export function Field({ label, hint, className, id, ...props }: FieldProps) {
  const autoId = useId()
  const inputId = id ?? autoId
  const hintId = hint ? `${inputId}-hint` : undefined
  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={inputId} className="text-sm font-medium text-muted">
        {label}
      </label>
      <input
        id={inputId}
        aria-describedby={hintId}
        className={cn(
          'min-h-touch rounded-lg border border-border bg-bg px-3 text-base text-text placeholder:text-muted',
          'focus:border-accent focus:outline-none',
          className,
        )}
        {...props}
      />
      {hint && (
        <p id={hintId} className="text-xs text-muted">
          {hint}
        </p>
      )}
    </div>
  )
}
