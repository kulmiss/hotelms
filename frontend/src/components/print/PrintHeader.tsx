import { cn } from '@/lib/cn'
import type { PrintHotel } from '@/lib/desk'

/** Hotel name, address, TIN and logo at the top of a printed page. */
export function PrintHotelBlock({ h, small }: { h: PrintHotel; small?: boolean }) {
  return (
    <div className={cn('flex gap-3', small && 'flex-col items-center')}>
      {h.logo_url && <img src={h.logo_url} alt="" className={small ? 'h-10' : 'h-14 w-auto'} />}
      <div className="flex flex-col">
        <strong className="text-[14px]">{h.legal_name || h.name}</strong>
        {h.legal_name && <span>{h.name}</span>}
        <span>{[h.address, h.city].filter(Boolean).join(', ')}</span>
        <span>{[h.phone, h.email].filter(Boolean).join(' · ')}</span>
        {h.tin && (
          <span>
            TIN: {h.tin}
            {h.vat_number && ` · VAT: ${h.vat_number}`}
          </span>
        )}
      </div>
    </div>
  )
}
