import type { HTMLAttributes } from 'react'
import { cn } from '@/lib/cn'

/** Filament "section": white panel with a fine ring and a small shadow. */
export function Card({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('panel p-5', className)} {...props} />
}
