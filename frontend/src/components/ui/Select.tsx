import { useId, type SelectHTMLAttributes } from 'react'
import { cn } from '@/lib/cn'

type SelectProps = SelectHTMLAttributes<HTMLSelectElement> & { label: string; hint?: string; error?: string }

export function Select({ label, hint, error, className, id, children, ...props }: SelectProps) {
  const autoId = useId()
  const selectId = id ?? autoId
  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={selectId} className="text-sm font-medium text-text">
        {label}
      </label>
      <select
        id={selectId}
        aria-invalid={error ? true : undefined}
        className={cn(
          'min-h-touch rounded-lg border-0 bg-surface px-3 text-base text-text shadow-sm ring-1 ring-ring-strong sm:text-sm transition duration-75 focus:ring-2 focus:ring-accent focus:outline-none',
          error && 'ring-danger',
          className,
        )}
        {...props}
      >
        {children}
      </select>
      {error && <p className="text-sm text-danger-text">{error}</p>}
      {hint && !error && <p className="text-xs text-muted">{hint}</p>}
    </div>
  )
}
