import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'

export function Alert({ tone = 'danger', children }: { tone?: 'danger' | 'info' | 'ok'; children: ReactNode }) {
  const tones = {
    danger: 'bg-danger-bg text-danger-text',
    info: 'bg-info-bg text-info-text',
    ok: 'bg-ok-bg text-ok-text',
  }
  return (
    <div role={tone === 'danger' ? 'alert' : 'status'} className={cn('rounded-xl px-3 py-2.5 text-sm', tones[tone])}>
      {children}
    </div>
  )
}
