import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { PrinterIcon } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { QrCode } from '@/components/ui/QrCode'
import { api } from '@/lib/api'
import { useMe } from '@/lib/auth'

type Cards = {
  hotel: { name: string; logo_url: string | null; phone: string | null }
  branch: { id: number; name: string }
  wifi: { name: string; password: string | null } | null
  menu_url: string | null
  whatsapp: { number: string; url: string } | null
  breakfast: string | null
  rooms: { id: number; number: string; room_type: string | null }[]
}

/** "WIFI:T:WPA;S:…;P:…;;" – phones join the network when they scan it. */
function wifiCode(name: string, password: string | null): string {
  const esc = (v: string) => v.replace(/([\\;,:"])/g, '\\$1')
  return password ? `WIFI:T:WPA;S:${esc(name)};P:${esc(password)};;` : `WIFI:T:nopass;S:${esc(name)};;`
}

/**
 * Printable room cards (owner, 14 Nov 2026): one A6-sized card per room with QR codes for the Wi-Fi, the menu and
 * reception's WhatsApp. Black on white, four cards per A4 page.
 */
export function RoomCardsPrintPage() {
  const { t } = useTranslation()
  const [params, setParams] = useSearchParams()
  const me = useMe().data
  const branchId = params.get('branch') ?? ''
  const q = useQuery({ queryKey: ['room-cards', branchId], queryFn: () => api<{ data: Cards }>(`/settings/room-cards${branchId ? `?branch_id=${branchId}` : ''}`) })
  const d = q.data?.data
  if (!d) return null
  const nothing = !d.wifi && !d.menu_url && !d.whatsapp

  return (
    <div className="min-h-full bg-sunken py-6 print:bg-white print:py-0">
      <div className="mx-auto mb-4 flex max-w-[210mm] flex-wrap items-center justify-between gap-3 px-4 print:hidden">
        <div className="flex flex-col gap-1">
          <h1 className="text-lg font-semibold">{t('roomCards.title')}</h1>
          <p className="text-sm text-muted">{nothing ? t('roomCards.nothing') : t('roomCards.help')}</p>
        </div>
        <div className="flex items-center gap-2">
          {(me?.branches.length ?? 0) > 1 && (
            <select aria-label={t('settings.rooms.branch')} className="min-h-touch rounded-lg border bg-surface px-2 text-sm" value={String(d.branch.id)} onChange={(e) => setParams({ branch: e.target.value })}>
              {me?.branches.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.name}
                </option>
              ))}
            </select>
          )}
          <Button disabled={nothing} onClick={() => window.print()}>
            <PrinterIcon aria-hidden className="size-4" />
            {t('billing.print')}
          </Button>
        </div>
      </div>
      <div className="mx-auto grid w-[210mm] max-w-full grid-cols-2 gap-0 bg-white text-black print:w-full">
        {d.rooms.map((r) => (
          <article key={r.id} className="flex h-[148mm] break-inside-avoid flex-col gap-3 border border-dashed border-black/30 p-[8mm] text-[12px]">
            <header className="flex items-center justify-between gap-2 border-b border-black/20 pb-2">
              <div className="flex items-center gap-2">
                {d.hotel.logo_url && <img src={d.hotel.logo_url} alt="" className="h-8 w-auto" />}
                <strong className="text-[14px]">{d.hotel.name}</strong>
              </div>
              <span className="text-[18px] font-bold">{t('roomCards.room', { number: r.number })}</span>
            </header>
            <div className="grid flex-1 grid-cols-2 content-start gap-3">
              {d.wifi && (
                <figure className="m-0 flex flex-col items-center gap-1 text-center">
                  <QrCode value={wifiCode(d.wifi.name, d.wifi.password)} label={t('roomCards.wifi')} className="size-[34mm]" />
                  <figcaption>
                    <strong>{t('roomCards.wifi')}</strong>
                    <br />
                    {d.wifi.name}
                    {d.wifi.password && (
                      <>
                        <br />
                        {t('roomCards.password', { password: d.wifi.password })}
                      </>
                    )}
                  </figcaption>
                </figure>
              )}
              {d.menu_url && (
                <figure className="m-0 flex flex-col items-center gap-1 text-center">
                  <QrCode value={d.menu_url} label={t('roomCards.menu')} className="size-[34mm]" />
                  <figcaption>
                    <strong>{t('roomCards.menu')}</strong>
                  </figcaption>
                </figure>
              )}
              {d.whatsapp && (
                <figure className="m-0 flex flex-col items-center gap-1 text-center">
                  <QrCode value={d.whatsapp.url} label={t('roomCards.whatsapp')} className="size-[34mm]" />
                  <figcaption>
                    <strong>{t('roomCards.whatsapp')}</strong>
                    <br />
                    {d.whatsapp.number}
                  </figcaption>
                </figure>
              )}
            </div>
            <footer className="border-t border-black/20 pt-2 text-[11px]">
              {d.breakfast && <p className="m-0">{t('roomCards.breakfast', { time: d.breakfast })}</p>}
              {d.hotel.phone && <p className="m-0">{t('roomCards.reception', { phone: d.hotel.phone })}</p>}
            </footer>
          </article>
        ))}
      </div>
    </div>
  )
}
