import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'

/** The template's alert (rounded-lg, border, 14px); tinted so a problem stands out at the desk. */
export function Alert({ tone = 'danger', children }: { tone?: 'danger' | 'info' | 'ok'; children: ReactNode }) {
  const tones = {
    danger: 'border-danger-text/20 bg-danger-bg text-danger-text',
    info: 'border-info-text/20 bg-info-bg text-info-text',
    ok: 'border-ok-text/20 bg-ok-bg text-ok-text',
  }
  return (
    <div data-slot="alert" role={tone === 'danger' ? 'alert' : 'status'} className={cn('relative w-full rounded-lg border px-4 py-3 text-sm', tones[tone])}>
      {children}
    </div>
  )
}
