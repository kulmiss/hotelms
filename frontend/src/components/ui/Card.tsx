import type { HTMLAttributes } from 'react'
import { cn } from '@/lib/cn'

/** The template's card: rounded-xl, border, card colour, small shadow, 24px inside. */
export function Card({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div data-slot="card" className={cn('panel p-6 text-text', className)} {...props} />
}
