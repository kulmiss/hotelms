import { useState } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/ui/Alert'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { LoadFailed } from '@/components/ui/LoadFailed'
import { QuickCharge } from '@/components/billing/QuickCharge'
import { useMe } from '@/lib/auth'
import { useCurrentBranch } from '@/lib/branch'
import { cn } from '@/lib/cn'
import { useQuickCharges } from '@/lib/desk'
import { useCan } from '@/lib/hotel'

/**
 * "Charge a room" (owner, 2026-11-07): type or tap the room number, then tap Breakfast, Laundry, Taxi…
 * For reception and the Restaurant / Bar role (they see only room, first name and the buttons).
 */
export function ChargeRoomPage() {
  const { t } = useTranslation()
  const me = useMe()
  const can = useCan()
  const { current } = useCurrentBranch(me.data)
  const data = useQuickCharges(current?.id ?? null)
  const [q, setQ] = useState('')
  const [roomId, setRoomId] = useState<number | null>(null)
  const rooms = data.data?.rooms ?? []
  const shown = rooms.filter((r) => !q.trim() || r.number.toLowerCase().startsWith(q.trim().toLowerCase()) || r.guest.toLowerCase().includes(q.trim().toLowerCase()))
  // Typing the full number of one room chooses it.
  const exact = rooms.find((r) => r.number.toLowerCase() === q.trim().toLowerCase())
  const room = rooms.find((r) => r.room_id === (roomId ?? exact?.room_id))

  return (
    <div className="flex flex-col gap-6">
      <PageHeader title={t('quickCharge.title')} subtitle={t('quickCharge.intro')} />
      <Card className="flex flex-col gap-4">
        <div className="max-w-xs">
          <Field
            label={t('quickCharge.roomNumber')}
            inputMode="search"
            autoFocus
            value={q}
            onChange={(e) => {
              setQ(e.target.value)
              setRoomId(null)
            }}
          />
        </div>
        <div className="grid grid-cols-[repeat(auto-fill,minmax(7.5rem,1fr))] gap-2">
          {shown.map((r) => (
            <button
              key={r.room_id}
              type="button"
              aria-pressed={room?.room_id === r.room_id}
              onClick={() => setRoomId(r.room_id)}
              className={cn(
                'flex min-h-16 flex-col items-start justify-center rounded-xl border px-3 py-2 text-left shadow-xs transition-colors',
                room?.room_id === r.room_id ? 'border-primary bg-accent-soft ring-1 ring-primary' : 'bg-surface hover:bg-sunken',
              )}
            >
              <span className="text-base font-semibold">{r.number}</span>
              <span className="truncate text-xs text-muted">{r.guest}</span>
            </button>
          ))}
        </div>
        {data.isError && !data.data && <LoadFailed error={data.error} onRetry={() => data.refetch()} />}
        {data.data && rooms.length === 0 && <p className="text-sm text-muted">{t('quickCharge.noGuests')}</p>}
        {data.data && rooms.length > 0 && shown.length === 0 && <p className="text-sm text-muted">{t('quickCharge.noMatch')}</p>}
      </Card>

      {room && (
        <Card className="flex flex-col gap-3 animate-page-in">
          <h2 className="text-base font-semibold">
            {t('reservations.room')} {room.number} · <span className="font-normal text-muted">{room.guest}</span>
          </h2>
          {data.data?.buttons.length === 0 && (
            <Alert tone="info">
              {t('quickCharge.noButtons')}{' '}
              {can('settings.charges') && (
                <Link to="/settings/charges" className="font-medium underline">
                  {t('quickCharge.setUp')}
                </Link>
              )}
            </Alert>
          )}
          <QuickCharge key={room.room_id} roomId={room.room_id} roomNumber={room.number} buttons={data.data?.buttons ?? []} />
        </Card>
      )}
    </div>
  )
}
