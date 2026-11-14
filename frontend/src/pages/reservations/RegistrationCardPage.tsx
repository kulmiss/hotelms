import { useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { PrinterIcon } from '@heroicons/react/24/outline'
import { PrintHotelBlock } from '@/components/print/PrintHeader'
import { Button } from '@/components/ui/Button'
import { LoadFailed } from '@/components/ui/LoadFailed'
import { api } from '@/lib/api'
import type { PrintHotel } from '@/lib/desk'
import { printDate, money } from '@/lib/format'
import { localDate, localTime, type ReservationDetail } from '@/lib/front'

type Card = {
  reservation: ReservationDetail
  guest: { name: string; phone: string | null; email: string | null; nationality: string | null; id_type: string | null; id_number: string | null; date_of_birth: string | null } | null
  hotel: PrintHotel
  printed_by: string
}

/** Registration card (decisions.md W9): the guest checks the details and signs at check-in. A4. */
export function RegistrationCardPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const q = useQuery({ queryKey: ['reservations', 'card', Number(id)], queryFn: () => api<{ data: Card }>(`/reservations/${id}/registration-card`) })
  if (q.isError) return <LoadFailed error={q.error} onRetry={() => q.refetch()} back={{ to: '/reservations', label: t('loadFailed.toReservations') }} />
  if (!q.data) return null
  const { reservation: r, guest: g, hotel: h, printed_by } = q.data.data
  const row = (label: string, value: string | null | undefined) => (
    <div className="flex flex-col border-b border-black/20 py-1.5">
      <span className="text-[10px] tracking-wide text-black/60 uppercase">{label}</span>
      <span className="min-h-5 font-medium">{value || ''}</span>
    </div>
  )
  const nights = r.stay_type === 'nightly'

  return (
    <div className="min-h-full bg-sunken py-6 print:bg-white print:py-0">
      <div className="mx-auto mb-4 flex max-w-[210mm] justify-end px-4 print:hidden">
        <Button onClick={() => window.print()}>
          <PrinterIcon aria-hidden className="size-4" />
          {t('billing.print')}
        </Button>
      </div>
      <article className="mx-auto w-[210mm] max-w-full bg-white p-[14mm] text-[12px] text-black shadow-sm print:shadow-none">
        <header className="flex items-start justify-between gap-4 border-b border-black/20 pb-3">
          <PrintHotelBlock h={h} />
          <div className="flex flex-col text-right">
            <strong className="text-[16px]">{t('regCard.title')}</strong>
            <span>{r.code}</span>
            <span>{r.branch?.name}</span>
          </div>
        </header>

        <h2 className="mt-4 mb-1 text-[13px] font-semibold">{t('regCard.guest')}</h2>
        <div className="grid grid-cols-2 gap-x-6">
          {row(t('regCard.name'), g?.name)}
          {row(t('regCard.nationality'), g?.nationality)}
          {row(t('regCard.idType'), g?.id_type ? t(`guests.idTypes.${g.id_type}`, { defaultValue: g.id_type }) : null)}
          {row(t('regCard.idNumber'), g?.id_number)}
          {row(t('regCard.phone'), g?.phone)}
          {row(t('regCard.email'), g?.email)}
          {row(t('regCard.birth'), g?.date_of_birth ? printDate(g.date_of_birth) : null)}
          {row(t('regCard.address'), null)}
        </div>

        <h2 className="mt-5 mb-1 text-[13px] font-semibold">{t('regCard.stay')}</h2>
        <div className="grid grid-cols-2 gap-x-6">
          {row(t('regCard.arrival'), `${printDate(localDate(r.check_in_at))} ${localTime(r.check_in_at)}`)}
          {row(t('regCard.departure'), `${printDate(localDate(r.check_out_at))} ${localTime(r.check_out_at)}`)}
          {row(t('regCard.rooms'), r.rooms.map((x) => [x.room_number, x.room_type].filter(Boolean).join(' · ')).join(', '))}
          {row(t('regCard.people'), t('regCard.peopleLine', { adults: t('reservations.adultsCount', { count: r.adults }), children: t('reservations.childrenCount', { count: r.children }) }))}
          {row(t('regCard.stayType'), t(`reservations.stayTypes.${r.stay_type}`))}
          {row(t('regCard.total'), money(r.total, r.currency))}
          {row(t('regCard.paid'), money(r.paid_total, r.currency))}
          {row(t('regCard.purpose'), null)}
        </div>

        <section className="mt-5 flex flex-col gap-1 text-[11px] text-black/80">
          <strong className="text-[12px] text-black">{t('regCard.terms')}</strong>
          <span>{t('regCard.term1', { time: localTime(r.check_out_at) })}</span>
          {nights && r.policy && <span>{t('regCard.term2', { policy: r.policy.name })}</span>}
          <span>{t('regCard.term3')}</span>
          <span>{t('regCard.term4')}</span>
        </section>

        <div className="mt-12 grid grid-cols-2 gap-12">
          <div className="flex flex-col border-t border-black pt-1">
            <span>{t('regCard.guestSignature')}</span>
          </div>
          <div className="flex flex-col border-t border-black pt-1">
            <span>{t('regCard.staff', { name: printed_by })}</span>
          </div>
        </div>
        {h.footer && <p className="mt-8 text-center text-[11px] text-black/60">{h.footer}</p>}
      </article>
    </div>
  )
}
