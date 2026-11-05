import type { ButtonHTMLAttributes } from 'react'
import { cn } from '@/lib/cn'

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger'
type Size = 'md' | 'lg'

// Filament buttons: rounded-lg, semibold, small shadow; secondary is white with a ring.
const variants: Record<Variant, string> = {
  primary: 'bg-accent text-on-accent shadow-sm hover:bg-accent-hover focus-visible:ring-2 focus-visible:ring-accent/50',
  secondary: 'bg-surface text-text shadow-sm ring-1 ring-ring-strong hover:bg-sunken',
  ghost: 'text-muted hover:bg-sunken hover:text-text',
  danger: 'bg-danger text-white shadow-sm hover:opacity-90',
}

// Touch rule: md is 44px tall, lg is 52px (for main reception actions).
const sizes: Record<Size, string> = {
  md: 'min-h-touch px-3.5 text-sm',
  lg: 'min-h-13 px-5 text-base',
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
        'inline-flex min-w-touch items-center justify-center gap-1.5 rounded-lg font-semibold outline-none transition duration-75',
        'disabled:pointer-events-none disabled:opacity-70',
        variants[variant],
        sizes[size],
        className,
      )}
      {...props}
    />
  )
}

/** Same look as Button, for links (react-router Link / a). */
export const buttonClass = (variant: Variant = 'primary', size: Size = 'md', className?: string) =>
  cn(
    'inline-flex min-w-touch items-center justify-center gap-1.5 rounded-lg font-semibold outline-none transition duration-75',
    variants[variant],
    sizes[size],
    className,
  )
