import type { ComponentProps } from 'react'
import { Slot } from '@radix-ui/react-slot'
import { cva, type VariantProps } from 'class-variance-authority'
import { cn } from '@/lib/cn'

/** shadcn button (template sizes; 44px on touch screens). */
const buttonVariants = cva(
  "inline-flex shrink-0 items-center justify-center gap-2 rounded-md text-sm font-medium whitespace-nowrap outline-none transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4 focus-visible:border-focus focus-visible:ring-[3px] focus-visible:ring-focus/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40",
  {
    variants: {
      variant: {
        default: 'bg-primary text-primary-foreground shadow-xs hover:bg-primary/90',
        destructive: 'bg-destructive text-white shadow-xs hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:bg-destructive/60 dark:focus-visible:ring-destructive/40',
        outline: 'border bg-background shadow-xs hover:bg-sunken hover:text-text dark:border-input dark:bg-input/30 dark:hover:bg-input/50',
        secondary: 'bg-sunken text-text shadow-xs hover:bg-sunken/80',
        ghost: 'hover:bg-sunken hover:text-text dark:hover:bg-sunken/50',
        link: 'text-primary underline-offset-4 hover:underline',
      },
      size: {
        default: 'h-9 px-4 py-2 has-[>svg]:px-3 pointer-coarse:h-11',
        sm: 'h-8 gap-1.5 rounded-md px-3 has-[>svg]:px-2.5 pointer-coarse:h-11',
        lg: 'h-10 rounded-md px-6 has-[>svg]:px-4 pointer-coarse:h-11',
        icon: 'size-9 pointer-coarse:size-11',
      },
    },
    defaultVariants: { variant: 'default', size: 'default' },
  },
)

function Button({ className, variant, size, asChild = false, type, ...props }: ComponentProps<'button'> & VariantProps<typeof buttonVariants> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : 'button'
  return <Comp data-slot="button" type={asChild ? type : (type ?? 'button')} className={cn(buttonVariants({ variant, size, className }))} {...props} />
}

// eslint-disable-next-line react-refresh/only-export-components
export { Button, buttonVariants }
