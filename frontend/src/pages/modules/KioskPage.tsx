import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { DeleteIcon } from 'lucide-react'
import { ApiError } from '@/lib/api'
import { BrandMark } from '@/lib/brand'
import { cn } from '@/lib/cn'
import { useKiosk } from '@/lib/modules'

/** Shared attendance kiosk (screen-list.md 9.7): staff type their PIN to clock in or out. */
export function KioskPage() {
  const { t } = useTranslation()
  const kiosk = useKiosk()
  const [pin, setPin] = useState('')
  const [message, setMessage] = useState<{ tone: 'ok' | 'danger'; text: string } | null>(null)
  const [now, setNow] = useState(() => new Date())

  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 15_000)
    return () => clearInterval(id)
  }, [])
  useEffect(() => {
    if (!message) return
    const id = setTimeout(() => setMessage(null), 4000)
    return () => clearTimeout(id)
  }, [message])

  function submit(value: string) {
    kiosk.mutate(value, {
      onSuccess: (res) => setMessage({ tone: 'ok', text: t(res.action === 'in' ? 'kiosk.welcome' : 'kiosk.bye', { name: res.name }) }),
      onError: (e) => setMessage({ tone: 'danger', text: e instanceof ApiError ? (e.field('pin') ?? e.message) : t('common.serverUnreachable') }),
      onSettled: () => setPin(''),
    })
  }
  const press = (d: string) => {
    const next = (pin + d).slice(0, 6)
    setPin(next)
  }

  return (
    <div className="flex min-h-full flex-col items-center justify-center gap-6 bg-bg p-6">
      <div className="flex flex-col items-center gap-2">
        <BrandMark className="size-10" />
        <span className="text-4xl font-semibold tabular-nums">{now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
        <span className="text-sm text-muted">{t('kiosk.help')}</span>
      </div>
      <div className="flex h-6 gap-3" aria-label={t('kiosk.pin')} aria-live="polite">
        {Array.from({ length: 6 }, (_, i) => (
          <span key={i} className={cn('size-4 rounded-full border', i < pin.length ? 'bg-primary' : 'bg-surface')} />
        ))}
      </div>
      <div role="status" className={cn('min-h-7 text-base font-medium', message?.tone === 'ok' ? 'text-ok-text' : 'text-danger-text')}>
        {message?.text}
      </div>
      <div className="grid grid-cols-3 gap-3">
        {['1', '2', '3', '4', '5', '6', '7', '8', '9'].map((d) => (
          <button key={d} type="button" onClick={() => press(d)} className="size-20 rounded-2xl border bg-surface text-2xl font-semibold shadow-xs transition-colors hover:bg-sunken active:scale-95">
            {d}
          </button>
        ))}
        <button type="button" aria-label={t('kiosk.delete')} onClick={() => setPin(pin.slice(0, -1))} className="flex size-20 items-center justify-center rounded-2xl text-icon">
          <DeleteIcon aria-hidden className="size-7" />
        </button>
        <button type="button" onClick={() => press('0')} className="size-20 rounded-2xl border bg-surface text-2xl font-semibold shadow-xs transition-colors hover:bg-sunken active:scale-95">
          0
        </button>
        <button type="button" disabled={pin.length < 4 || kiosk.isPending} onClick={() => submit(pin)} className="size-20 rounded-2xl bg-primary text-base font-semibold text-primary-foreground shadow-xs transition duration-75 active:scale-95 disabled:opacity-40">
          {t('kiosk.ok')}
        </button>
      </div>
      <Link to="/attendance" className="text-sm text-muted hover:text-text">
        {t('kiosk.exit')}
      </Link>
    </div>
  )
}
