import { useState } from 'react'
import { useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { PrinterIcon } from '@heroicons/react/24/outline'
import { Button } from '@/components/ui/Button'
import { cn } from '@/lib/cn'
import { useInvoice } from '@/lib/desk'
import { date, money } from '@/lib/format'
import { localDate, localTime } from '@/lib/front'

/**
 * Print layout (decisions.md W9): any printer set up on the device works.
 * A4 invoice with logo and TIN, or an 80 mm receipt.
 */
export function InvoicePrintPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const q = useInvoice(Number(id))
  const [paper, setPaper] = useState<'a4' | 'receipt'>('a4')
  if (!q.data) return null
  const inv = q.data.data
  const h = inv.hotel
  const c = inv.currency
  const small = paper === 'receipt'

  return (
    <div className="min-h-full bg-sunken py-6 print:bg-white print:py-0">
      <div className="mx-auto mb-4 flex max-w-[210mm] items-center justify-between gap-3 px-4 print:hidden">
        <div className="flex gap-1.5">
          {(['a4', 'receipt'] as const).map((p) => (
            <button
              key={p}
              type="button"
              aria-pressed={paper === p}
              onClick={() => setPaper(p)}
              className={cn('min-h-touch rounded-lg px-3 text-[13px] font-medium ring-1', paper === p ? 'bg-accent text-on-accent ring-accent' : 'bg-surface ring-ring')}
            >
              {t(`billing.paper.${p}`)}
            </button>
          ))}
        </div>
        <Button onClick={() => window.print()}>
          <PrinterIcon aria-hidden className="size-4" />
          {t('billing.print')}
        </Button>
      </div>

      <article className={cn('mx-auto bg-white text-black shadow-sm print:shadow-none', small ? 'w-[80mm] p-3 text-[11px]' : 'w-[210mm] max-w-full p-[14mm] text-[12px]')}>
        <header className={cn('flex gap-4 border-b border-black/20 pb-3', small ? 'flex-col items-center text-center' : 'items-start justify-between')}>
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
          <div className={cn('flex flex-col', !small && 'text-right')}>
            <strong className="text-[16px]">{inv.status === 'void' ? t('billing.printVoid') : t('billing.printTitle')}</strong>
            <span>{inv.number}</span>
            <span>{date(localDate(inv.issued_at))}</span>
          </div>
        </header>

        <section className={cn('grid gap-2 py-3', small ? 'grid-cols-1' : 'grid-cols-2')}>
          <div className="flex flex-col">
            <span className="text-black/60">{t('billing.billTo')}</span>
            <strong>{inv.guest.full_name}</strong>
            {inv.guest.phone && <span>{inv.guest.phone}</span>}
            {inv.guest.id_number && <span>ID: {inv.guest.id_number}</span>}
          </div>
          {inv.stay && (
            <div className={cn('flex flex-col', !small && 'text-right')}>
              <span className="text-black/60">{inv.reservation?.code}</span>
              <span>
                {date(localDate(inv.stay.check_in_at))} {localTime(inv.stay.check_in_at)} → {date(localDate(inv.stay.check_out_at))} {localTime(inv.stay.check_out_at)}
              </span>
              <span>{inv.stay.rooms.map((r) => r.room_number ?? r.room_type).join(', ')}</span>
            </div>
          )}
        </section>

        <table className="w-full border-collapse">
          <thead>
            <tr className="border-y border-black/30 text-left">
              {!small && <th className="py-1 pr-2">{t('billing.date')}</th>}
              <th className="py-1 pr-2">{t('billing.description')}</th>
              <th className="py-1 pr-2 text-right">{t('billing.qty')}</th>
              <th className="py-1 text-right">{t('billing.amount')}</th>
            </tr>
          </thead>
          <tbody>
            {inv.items.map((it) => (
              <tr key={it.id} className="border-b border-black/10 align-top">
                {!small && <td className="py-1 pr-2 whitespace-nowrap">{date(it.service_date)}</td>}
                <td className="py-1 pr-2">
                  {it.description}
                  {it.is_waived && ` (${t('billing.waived')})`}
                </td>
                <td className="py-1 pr-2 text-right">{Number(it.quantity)}</td>
                <td className="py-1 text-right whitespace-nowrap">{money(it.amount, c)}</td>
              </tr>
            ))}
          </tbody>
        </table>

        <section className={cn('mt-3 ml-auto flex flex-col gap-0.5', small ? 'w-full' : 'w-72')}>
          <Row label={t('billing.charges')} value={money(inv.subtotal, c)} />
          {Number(inv.discount_total) > 0 && <Row label={t('billing.discounts')} value={`− ${money(inv.discount_total, c)}`} />}
          {inv.taxes.map((x) => (
            <Row key={`${x.name}${x.rate}${x.is_inclusive}`} label={x.is_inclusive ? t('billing.taxIncluded', { name: x.name, rate: Number(x.rate) }) : `${x.name} ${Number(x.rate)}%`} value={money(x.amount, c)} />
          ))}
          <Row label={t('reservations.total')} value={money(inv.total, c)} strong />
          {inv.payments.map((p) => (
            <Row key={p.id} label={`${t(`reservations.paymentTypes.${p.type}`)} · ${p.method ?? ''} · ${date(localDate(p.paid_at))}`} value={`${p.type === 'refund' ? '' : '− '}${money(p.amount_in_base, c)}`} />
          ))}
          <Row label={t('reservations.balance')} value={money(inv.balance, c)} strong />
        </section>

        {h.footer && <footer className="mt-6 border-t border-black/20 pt-2 text-center text-black/70">{h.footer}</footer>}
        {!small && (
          <div className="mt-10 grid grid-cols-2 gap-10 text-black/70">
            <span className="border-t border-black/40 pt-1">{t('billing.guestSignature')}</span>
            <span className="border-t border-black/40 pt-1">{t('billing.cashierSignature')}</span>
          </div>
        )}
      </article>
    </div>
  )
}

function Row({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className={cn('flex justify-between gap-3', strong && 'border-t border-black/30 pt-1 font-bold')}>
      <span>{label}</span>
      <span className="whitespace-nowrap">{value}</span>
    </div>
  )
}
