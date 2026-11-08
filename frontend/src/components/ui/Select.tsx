import { useId, type SelectHTMLAttributes } from 'react'
import { ChevronDown } from 'lucide-react'
import { cn } from '@/lib/cn'
import { errorClass, hintClass, labelClass, selectClass } from './formStyles'

type SelectProps = SelectHTMLAttributes<HTMLSelectElement> & { label: string; hint?: string; error?: string }

/** Native select (the phone's own picker) that looks like the template's select trigger. */
export function Select({ label, hint, error, className, id, children, ...props }: SelectProps) {
  const autoId = useId()
  const selectId = id ?? autoId
  return (
    <div data-slot="form-item" className="flex min-w-0 flex-col gap-2">
      <label htmlFor={selectId} className={cn(labelClass, error && 'text-danger-text')}>
        {label}
      </label>
      <div className="relative">
        <select id={selectId} aria-invalid={error ? true : undefined} className={cn(selectClass, className)} {...props}>
          {children}
        </select>
        <ChevronDown aria-hidden className="pointer-events-none absolute end-3 top-1/2 size-4 -translate-y-1/2 opacity-50" />
      </div>
      {error && <p className={errorClass}>{error}</p>}
      {hint && !error && <p className={hintClass}>{hint}</p>}
    </div>
  )
}

/** A select without its own label (toolbars, filters): give it `aria-label`. Same look as Select. */
export function SelectBox({ className, wrapperClassName, children, ...props }: SelectHTMLAttributes<HTMLSelectElement> & { wrapperClassName?: string }) {
  return (
    <div className={cn('relative min-w-0', wrapperClassName)}>
      <select className={cn(selectClass, className)} {...props}>
        {children}
      </select>
      <ChevronDown aria-hidden className="pointer-events-none absolute end-3 top-1/2 size-4 -translate-y-1/2 opacity-50" />
    </div>
  )
}
