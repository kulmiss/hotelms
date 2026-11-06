import { useEffect, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Select } from '@/components/ui/Select'
import { ApiError } from '@/lib/api'
import { useContent, useSaveContent, type PlatformContent } from '@/lib/platform'
import { TextArea } from '@/pages/help/HelpPage'

const errorOf = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : e ? String(e) : null)

/** Super Admin → Content (area 10): what is on HotelMS invoices, the welcome email, and defaults for new hotels. Banners are on Switches. */
export function ContentPage() {
  const { t } = useTranslation()
  const q = useContent()
  const save = useSaveContent()
  const [f, setF] = useState<PlatformContent | null>(null)
  useEffect(() => {
    if (q.data) setF(q.data.data)
  }, [q.data])
  if (!f) return <p className="text-[13px] text-muted">{t('common.loading')}</p>
  const d = f.new_hotel_defaults
  const setD = (k: keyof typeof d, v: string) => setF({ ...f, new_hotel_defaults: { ...d, [k]: v } })
  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate(f)
  }

  return (
    <form onSubmit={submit} className="mx-auto flex max-w-4xl flex-col gap-6">
      <PageHeader title={t('nav.content')} subtitle={t('content.subtitle')} />
      {save.error && <Alert>{errorOf(save.error)}</Alert>}
      <Card className="flex flex-col gap-3">
        <h2 className="text-sm font-semibold">{t('content.invoiceTitle')}</h2>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t('content.billingName')} value={f.billing_name ?? ''} maxLength={120} onChange={(e) => setF({ ...f, billing_name: e.target.value })} />
          <Field label={t('content.billingTin')} value={f.billing_tin ?? ''} maxLength={30} onChange={(e) => setF({ ...f, billing_tin: e.target.value })} />
        </div>
        <Field label={t('content.billingAddress')} value={f.billing_address ?? ''} maxLength={200} onChange={(e) => setF({ ...f, billing_address: e.target.value })} />
        <TextArea label={t('content.paymentInstructions')} value={f.payment_instructions ?? ''} rows={3} onChange={(v) => setF({ ...f, payment_instructions: v })} />
      </Card>
      <Card className="flex flex-col gap-3">
        <h2 className="text-sm font-semibold">{t('content.welcomeTitle')}</h2>
        <TextArea label={t('content.welcomeMessage')} value={f.welcome_message ?? ''} rows={3} onChange={(v) => setF({ ...f, welcome_message: v })} />
      </Card>
      <Card className="flex flex-col gap-3">
        <div>
          <h2 className="text-sm font-semibold">{t('content.defaultsTitle')}</h2>
          <p className="text-xs text-muted">{t('content.defaultsHelp')}</p>
        </div>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Field label={t('content.deposit')} type="number" min={0} max={100} value={d.deposit_value} onChange={(e) => setD('deposit_value', e.target.value)} />
          <Field label={t('content.vat')} type="number" min={0} max={100} step="0.01" value={d.vat_rate} onChange={(e) => setD('vat_rate', e.target.value)} />
          <Select label={t('settings.profile.paper')} value={d.receipt_paper} onChange={(e) => setD('receipt_paper', e.target.value)}>
            <option value="a4">{t('billing.paper.a4')}</option>
            <option value="receipt">{t('billing.paper.receipt')}</option>
          </Select>
          <Field label={t('settings.profile.footer')} value={d.invoice_footer ?? ''} maxLength={300} onChange={(e) => setD('invoice_footer', e.target.value)} />
        </div>
      </Card>
      <div>
        <Button type="submit" disabled={save.isPending}>
          {save.isPending ? t('common.saving') : t('common.save')}
        </Button>
      </div>
    </form>
  )
}
