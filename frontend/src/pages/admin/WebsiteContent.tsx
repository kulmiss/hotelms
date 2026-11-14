import { useEffect, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { ApiError, api, put, upload } from '@/lib/api'
import { SILENT } from '@/lib/toast'
import { TextArea } from '@/pages/help/HelpPage'

type Pic = { src: string; alt?: string; caption?: string }
type Website = {
  headline: string
  subline: string
  sections: Record<string, boolean>
  faq: { q: string; a: string }[]
  testimonials: { quote: string; name: string; hotel?: string | null }[]
  pictures: { hero: Pic; gallery: Pic[] }
}
const KEY = ['admin', 'landing'] as const
const errorOf = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : null)

/** Super Admin → Content → Website: the public home page (words, sections, questions, real reviews, pictures). */
export function WebsiteContent() {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: KEY, queryFn: () => api<{ data: Website; sections: string[] }>('/admin/landing') })
  const save = useMutation({ meta: SILENT, mutationFn: (w: Website) => put<{ data: Website; message: string }>('/admin/landing', w), onSuccess: () => qc.invalidateQueries({ queryKey: KEY }) })
  const pic = useMutation({
    meta: SILENT,
    mutationFn: ({ slot, file }: { slot: string; file: File | null }) => upload<{ data: Website }>(`/admin/landing/picture?slot=${slot}`, 'file', file),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  })
  const [w, setW] = useState<Website | null>(null)
  useEffect(() => {
    if (q.data) setW(q.data.data)
  }, [q.data])
  if (!w || !q.data) return null
  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate(w)
  }
  const slots: [string, Pic][] = [['hero', w.pictures.hero], ...w.pictures.gallery.map((p, i) => [`gallery.${i}`, p] as [string, Pic])]

  return (
    <form onSubmit={submit} className="mx-auto flex w-full max-w-4xl flex-col gap-4">
      <Card className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-sm font-semibold">{t('website.title')}</h2>
          <a href="/" target="_blank" rel="noopener" className="text-sm font-medium text-accent hover:underline">{t('website.open')}</a>
        </div>
        <p className="text-sm text-muted">{t('website.help')}</p>
        {save.isSuccess && <Alert tone="ok">{save.data.message}</Alert>}
        {save.error && <Alert>{errorOf(save.error) ?? t('common.serverUnreachable')}</Alert>}
        <Field label={t('website.headline')} value={w.headline} maxLength={90} onChange={(e) => setW({ ...w, headline: e.target.value })} />
        <TextArea label={t('website.subline')} rows={2} value={w.subline} onChange={(subline) => setW({ ...w, subline })} />
        <fieldset className="flex flex-col gap-1">
          <legend className="mb-1 text-sm font-medium">{t('website.sections')}</legend>
          <div className="grid gap-x-6 sm:grid-cols-3">
            {q.data.sections.map((s) => (
              <label key={s} className="flex min-h-touch items-center gap-3 text-sm">
                <input type="checkbox" className="size-4 rounded" checked={!!w.sections[s]} onChange={(e) => setW({ ...w, sections: { ...w.sections, [s]: e.target.checked } })} />
                {t(`website.section.${s}`)}
              </label>
            ))}
          </div>
        </fieldset>
      </Card>

      <Card className="flex flex-col gap-3">
        <h2 className="text-sm font-semibold">{t('website.faq')}</h2>
        {w.faq.map((f, i) => (
          <div key={i} className="flex flex-col gap-2 rounded-lg border border-border p-3">
            <Field label={t('website.question')} value={f.q} maxLength={160} onChange={(e) => setW({ ...w, faq: w.faq.map((x, j) => (j === i ? { ...x, q: e.target.value } : x)) })} />
            <TextArea label={t('website.answer')} rows={3} value={f.a} onChange={(a) => setW({ ...w, faq: w.faq.map((x, j) => (j === i ? { ...x, a } : x)) })} />
            <div><Button type="button" variant="ghost" onClick={() => setW({ ...w, faq: w.faq.filter((_, j) => j !== i) })}>{t('common.remove')}</Button></div>
          </div>
        ))}
        <div><Button type="button" variant="secondary" onClick={() => setW({ ...w, faq: [...w.faq, { q: '', a: '' }] })}>{t('website.addQuestion')}</Button></div>
      </Card>

      <Card className="flex flex-col gap-3">
        <h2 className="text-sm font-semibold">{t('website.testimonials')}</h2>
        <p className="text-sm text-muted">{t('website.testimonialsHelp')}</p>
        {w.testimonials.map((r, i) => (
          <div key={i} className="flex flex-col gap-2 rounded-lg border border-border p-3">
            <TextArea label={t('website.quote')} rows={2} value={r.quote} onChange={(quote) => setW({ ...w, testimonials: w.testimonials.map((x, j) => (j === i ? { ...x, quote } : x)) })} />
            <div className="grid gap-2 sm:grid-cols-2">
              <Field label={t('website.person')} value={r.name} onChange={(e) => setW({ ...w, testimonials: w.testimonials.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)) })} />
              <Field label={t('website.hotel')} value={r.hotel ?? ''} onChange={(e) => setW({ ...w, testimonials: w.testimonials.map((x, j) => (j === i ? { ...x, hotel: e.target.value } : x)) })} />
            </div>
            <div><Button type="button" variant="ghost" onClick={() => setW({ ...w, testimonials: w.testimonials.filter((_, j) => j !== i) })}>{t('common.remove')}</Button></div>
          </div>
        ))}
        <div><Button type="button" variant="secondary" onClick={() => setW({ ...w, testimonials: [...w.testimonials, { quote: '', name: '', hotel: '' }] })}>{t('website.addTestimonial')}</Button></div>
      </Card>

      <Card className="flex flex-col gap-3">
        <h2 className="text-sm font-semibold">{t('website.pictures')}</h2>
        <p className="text-sm text-muted">{t('website.picturesHelp')}</p>
        {pic.error && <Alert>{errorOf(pic.error) ?? t('common.serverUnreachable')}</Alert>}
        <div className="grid gap-4 sm:grid-cols-2">
          {slots.map(([slot, p]) => (
            <div key={slot} className="flex flex-col gap-2">
              <img src={p.src} alt={p.alt ?? ''} className="aspect-video w-full rounded-lg border border-border object-cover object-top" loading="lazy" />
              <div className="flex flex-wrap gap-2">
                <label className="inline-flex min-h-touch cursor-pointer items-center rounded-md border border-border px-3 text-sm font-medium hover:bg-sunken">
                  {t('website.replace')}
                  <input type="file" accept="image/png,image/jpeg,image/webp" className="sr-only" onChange={(e) => e.target.files?.[0] && pic.mutate({ slot, file: e.target.files[0] })} />
                </label>
                {p.src.startsWith('/storage/') && <Button type="button" variant="ghost" onClick={() => pic.mutate({ slot, file: null })}>{t('website.original')}</Button>}
              </div>
            </div>
          ))}
        </div>
      </Card>
      <div>
        <Button type="submit" disabled={save.isPending}>{save.isPending ? t('common.saving') : t('website.save')}</Button>
      </div>
    </form>
  )
}
