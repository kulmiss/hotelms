import type { ButtonHTMLAttributes } from 'react'
import { cn } from '@/lib/cn'

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger'
type Size = 'md' | 'lg'

const variants: Record<Variant, string> = {
  primary: 'bg-accent text-on-accent shadow-soft hover:bg-accent-hover',
  secondary: 'border border-border bg-surface text-text hover:bg-sunken',
  ghost: 'text-muted hover:bg-sunken hover:text-text',
  danger: 'bg-danger text-white hover:opacity-90',
}

// Touch rule: md is 44px tall, lg is 52px (for main reception actions).
const sizes: Record<Size, string> = {
  md: 'min-h-touch px-4 text-sm',
  lg: 'min-h-13 px-6 text-base',
}

export type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: Variant
  size?: Size
}

export function Button({ variant = 'primary', size = 'md', className, type = 'button', ...props }: ButtonProps) {
  return (
    <button
      type={type}
      className={cn(
        'inline-flex min-w-touch items-center justify-center gap-2 rounded-xl font-semibold transition-[background-color,transform,box-shadow] duration-150 active:scale-[0.98]',
        'disabled:cursor-not-allowed disabled:opacity-50',
        variants[variant],
        sizes[size],
        className,
      )}
      {...props}
    />
  )
}
