import { useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { PrinterIcon } from '@heroicons/react/24/outline'
import { Button } from '@/components/ui/Button'
import { useBrand } from '@/lib/brand'
import { date, money } from '@/lib/format'
import { useInvoicePrint } from '@/lib/platform'

/** A HotelMS subscription invoice to view and print (A4). */
export function SubscriptionInvoicePage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const brand = useBrand()
  const q = useInvoicePrint(Number(id))
  if (!q.data) return q.isError ? <p className="p-6 text-[13px] text-danger-text">{t('admin.hotel.notFound')}</p> : null
  const inv = q.data.data
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
          <div className="flex gap-3">
            {brand.logoUrl && <img src={brand.logoUrl} alt="" className="h-12 w-auto" />}
            <div className="flex flex-col">
              <strong className="text-[14px]">{inv.from.name}</strong>
              {inv.from.address && <span>{inv.from.address}</span>}
              <span>{[inv.from.phone, inv.from.email].filter(Boolean).join(' · ')}</span>
              {inv.from.tin && <span>TIN: {inv.from.tin}</span>}
            </div>
          </div>
          <div className="flex flex-col text-right">
            <strong className="text-[16px]">{inv.status === 'void' ? t('billing.printVoid') : t('hotelInvoices.title')}</strong>
            <span>{inv.number}</span>
            <span>{date(inv.created_at?.slice(0, 10) ?? inv.period_start)}</span>
          </div>
        </header>
        <section className="grid grid-cols-2 gap-2 py-3">
          <div className="flex flex-col">
            <span className="text-black/60">{t('billing.billTo')}</span>
            <strong>{inv.hotel.legal_name || inv.hotel.name}</strong>
            <span>{[inv.hotel.address, inv.hotel.city].filter(Boolean).join(', ')}</span>
            {inv.hotel.tin && <span>TIN: {inv.hotel.tin}</span>}
          </div>
          <div className="flex flex-col text-right">
            <span className="text-black/60">{t('hotelInvoices.due')}</span>
            <strong>{date(inv.due_date)}</strong>
            <span className="uppercase">{t(`hotelInvoices.status.${inv.status}`)}</span>
          </div>
        </section>
        <table className="w-full border-collapse text-left">
          <thead>
            <tr className="border-y border-black/20">
              <th className="py-2 font-semibold">{t('hotelInvoices.description')}</th>
              <th className="py-2 text-right font-semibold">{t('hotelInvoices.amount')}</th>
            </tr>
          </thead>
          <tbody>
            <tr className="border-b border-black/10">
              <td className="py-2">
                {t('hotelInvoices.line', { plan: inv.plan ?? '', cycle: t(`admin.billing.${inv.billing_cycle}`), rooms: inv.rooms })}
                <span className="block text-black/60">
                  {date(inv.period_start)} – {date(inv.period_end)}
                </span>
              </td>
              <td className="py-2 text-right tabular-nums">{money(inv.amount, inv.currency)}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <td className="py-2 text-right font-semibold">{t('hotelInvoices.total')}</td>
              <td className="py-2 text-right text-[14px] font-semibold tabular-nums">{money(inv.amount, inv.currency)}</td>
            </tr>
          </tfoot>
        </table>
        {inv.from.payment_instructions && (
          <section className="mt-6 flex flex-col gap-1">
            <strong>{t('hotelInvoices.howToPay')}</strong>
            <p className="whitespace-pre-wrap">{inv.from.payment_instructions}</p>
          </section>
        )}
      </article>
    </div>
  )
}
