import type { ButtonHTMLAttributes } from 'react'
import { cn } from '@/lib/cn'

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger'
type Size = 'md' | 'lg'

/*
 * The template's buttons (shadcn): primary = brand colour ("default"), secondary = "outline",
 * ghost, danger = "destructive". Same names as before, so no screen changes its code.
 */
const base =
  "inline-flex min-w-touch shrink-0 items-center justify-center gap-2 rounded-md text-sm font-medium whitespace-nowrap outline-none transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4 focus-visible:border-focus focus-visible:ring-[3px] focus-visible:ring-focus/50"

const variants: Record<Variant, string> = {
  primary: 'bg-primary text-primary-foreground shadow-xs hover:bg-primary/90',
  secondary: 'border bg-background text-text shadow-xs hover:bg-sunken dark:border-input dark:bg-input/30 dark:hover:bg-input/50',
  ghost: 'text-text hover:bg-sunken dark:hover:bg-sunken/50',
  danger: 'bg-destructive text-white shadow-xs hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:bg-destructive/60 dark:focus-visible:ring-destructive/40',
}

// Touch rule: md is 36px with a mouse and 44px on touch; lg (main reception actions) is 40px / 52px on touch.
const sizes: Record<Size, string> = {
  md: 'min-h-touch px-4 py-2 has-[>svg]:px-3',
  lg: 'min-h-10 px-6 has-[>svg]:px-4 pointer-coarse:min-h-13',
}

export type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: Variant
  size?: Size
}

export function Button({ variant = 'primary', size = 'md', className, type = 'button', ...props }: ButtonProps) {
  return <button type={type} data-slot="button" className={cn(base, variants[variant], sizes[size], className)} {...props} />
}

/** Same look as Button, for links (react-router Link / a). */
export const buttonClass = (variant: Variant = 'primary', size: Size = 'md', className?: string) => cn(base, variants[variant], sizes[size], className)
