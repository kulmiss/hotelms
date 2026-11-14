import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { StarIcon } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { api, put } from '@/lib/api'
import { dateTime } from '@/lib/format'
import { cn } from '@/lib/cn'

const RATINGS = ['overall', 'cleanliness', 'staff', 'comfort', 'value'] as const
type Rating = (typeof RATINGS)[number]
type Item = Record<Rating, number | null> & {
  id: number
  reservation_id: number
  code: string | null
  guest: string | null
  room: string | null
  comment: string | null
  public_ok: boolean
  submitted_at: string
  checked_in_by: string | null
  checked_out_by: string | null
  reply: string | null
  replied_by: string | null
}
type Summary = {
  count: number
  averages: Record<Rating, number | null>
  low: number
  dashboard: boolean
  trend?: { month: string; count: number; overall: number | null; cleanliness: number | null; staff: number | null }[]
  rooms?: { room: string; count: number; overall: number | null; cleanliness: number | null }[]
  staff?: { user_id: number; name: string; role: 'check_in' | 'check_out'; count: number; overall: number | null; staff: number | null }[]
}

function Stars({ n }: { n: number | null }) {
  if (n === null) return <span className="text-muted">—</span>
  return (
    <span className="inline-flex items-center gap-0.5" aria-label={`${n} / 5`}>
      {[1, 2, 3, 4, 5].map((i) => (
        <StarIcon key={i} aria-hidden className={cn('size-4', i <= n ? 'fill-warn-text text-warn-text' : 'text-border')} />
      ))}
    </span>
  )
}

/** Guest feedback (owner, 14 Nov 2026): the guests' own words (never changed), a reply note, and the dashboard on Starter+. */
export function FeedbackPage() {
  const { t } = useTranslation()
  const [params] = useSearchParams()
  const [low, setLow] = useState(false)
  const id = params.get('id')
  const summary = useQuery({ queryKey: ['feedback-summary'], queryFn: () => api<{ data: Summary }>('/feedback/summary') })
  const list = useQuery({ queryKey: ['feedback', low, id], queryFn: () => api<{ data: Item[]; total: number }>(`/feedback?${low ? 'low=1&' : ''}${id ? `id=${id}` : ''}`) })
  const s = summary.data?.data

  return (
    <div className="flex flex-col gap-6">
      <PageHeader title={t('nav.feedback')} subtitle={t('feedback.subtitle')} />
      {s && (
        <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
          <Card className="flex flex-col gap-1">
            <span className="text-xs text-muted">{t('feedback.answers')}</span>
            <span className="text-2xl font-semibold tabular-nums">{s.count}</span>
          </Card>
          {RATINGS.map((r) => (
            <Card key={r} className="flex flex-col gap-1">
              <span className="text-xs text-muted">{t(`feedback.ratings.${r}`)}</span>
              <span className="text-2xl font-semibold tabular-nums">{s.averages[r] ?? '—'}</span>
            </Card>
          ))}
        </div>
      )}
      {s && !s.dashboard && <p className="text-sm text-muted">{t('feedback.dashboardPlan')}</p>}
      {s?.dashboard && <Dashboard s={s} />}

      <Card className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-sm font-semibold">{t('feedback.list')}</h2>
          <label className="flex min-h-touch items-center gap-2 text-sm">
            <input type="checkbox" className="size-4" checked={low} onChange={(e) => setLow(e.target.checked)} />
            {t('feedback.onlyLow', { count: s?.low ?? 0 })}
          </label>
        </div>
        {list.data?.data.length === 0 && <p className="text-sm text-muted">{t('feedback.none')}</p>}
        <ul className="flex flex-col divide-y divide-border">
          {list.data?.data.map((f) => (
            <FeedbackRow key={f.id} f={f} />
          ))}
        </ul>
      </Card>
    </div>
  )
}

function FeedbackRow({ f }: { f: Item }) {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const [editing, setEditing] = useState(false)
  const [reply, setReply] = useState(f.reply ?? '')
  const save = useMutation({
    mutationFn: () => put(`/feedback/${f.id}/reply`, { reply }),
    onSuccess: () => {
      setEditing(false)
      void qc.invalidateQueries({ queryKey: ['feedback'] })
    },
  })
  return (
    <li className="flex flex-col gap-2 py-3">
      <div className="flex flex-wrap items-center gap-2">
        <Stars n={f.overall} />
        {f.overall !== null && f.overall <= 3 && <Badge tone="warn">{t('feedback.low')}</Badge>}
        <span className="font-medium">{f.guest}</span>
        <span className="text-sm text-muted">
          {f.room && `${t('feedback.room', { number: f.room })} · `}
          {dateTime(f.submitted_at)} ·{' '}
          <Link to={`/reservations/${f.reservation_id}`} className="hover:text-accent-text hover:underline">
            {f.code}
          </Link>
        </span>
        {f.public_ok && <Badge tone="grey">{t('feedback.publicOk')}</Badge>}
      </div>
      <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
        {RATINGS.slice(1).map((r) => (
          <span key={r} className="inline-flex items-center gap-1">
            {t(`feedback.ratings.${r}`)}: {f[r] ?? '—'}
          </span>
        ))}
        {f.checked_in_by && <span>{t('feedback.checkedInBy', { name: f.checked_in_by })}</span>}
      </div>
      {f.comment && <blockquote className="border-l-2 border-border pl-3 text-sm whitespace-pre-line">{f.comment}</blockquote>}
      {f.reply && !editing && (
        <p className="rounded-lg bg-sunken p-2 text-sm">
          <span className="font-medium">{t('feedback.replyBy', { name: f.replied_by ?? '' })}</span> {f.reply}
        </p>
      )}
      {editing ? (
        <div className="flex flex-col gap-2">
          <Field label={t('feedback.reply')} hint={t('feedback.replyHint')} value={reply} onChange={(e) => setReply(e.target.value)} />
          <div className="flex gap-2">
            <Button disabled={save.isPending} onClick={() => save.mutate()}>
              {t('common.save')}
            </Button>
            <Button variant="ghost" onClick={() => setEditing(false)}>
              {t('common.cancel')}
            </Button>
          </div>
        </div>
      ) : (
        <div>
          <Button variant="ghost" onClick={() => setEditing(true)}>
            {f.reply ? t('feedback.editReply') : t('feedback.addReply')}
          </Button>
        </div>
      )}
    </li>
  )
}

function Dashboard({ s }: { s: Summary }) {
  const { t } = useTranslation()
  const max = Math.max(1, ...(s.trend ?? []).map((m) => m.count))
  return (
    <div className="grid gap-4 lg:grid-cols-3">
      <Card className="flex flex-col gap-3">
        <h2 className="text-sm font-semibold">{t('feedback.trend')}</h2>
        {(s.trend ?? []).length === 0 && <p className="text-sm text-muted">{t('feedback.none')}</p>}
        <ul className="flex flex-col gap-1.5 text-sm">
          {(s.trend ?? []).map((m) => (
            <li key={m.month} className="grid grid-cols-[4.5rem_minmax(0,1fr)_2.5rem] items-center gap-2">
              <span className="tabular-nums text-muted">{m.month}</span>
              <span className="h-2 rounded-full bg-sunken" aria-hidden>
                <span className="block h-2 rounded-full bg-accent" style={{ width: `${(m.count / max) * 100}%` }} />
              </span>
              <span className="text-right tabular-nums" title={t('feedback.answersN', { count: m.count })}>
                {m.overall ?? '—'}
              </span>
            </li>
          ))}
        </ul>
      </Card>
      <Card className="flex flex-col gap-3">
        <h2 className="text-sm font-semibold">{t('feedback.perRoom')}</h2>
        <table className="text-sm">
          <thead className="text-xs text-muted">
            <tr>
              <th className="py-1 text-left font-medium">{t('feedback.roomCol')}</th>
              <th className="py-1 text-right font-medium">{t('feedback.ratings.overall')}</th>
              <th className="py-1 text-right font-medium">{t('feedback.ratings.cleanliness')}</th>
              <th className="py-1 text-right font-medium">{t('feedback.answers')}</th>
            </tr>
          </thead>
          <tbody>
            {(s.rooms ?? []).map((r) => (
              <tr key={r.room} className="border-t border-border">
                <td className="py-1">{r.room}</td>
                <td className="py-1 text-right tabular-nums">{r.overall ?? '—'}</td>
                <td className="py-1 text-right tabular-nums">{r.cleanliness ?? '—'}</td>
                <td className="py-1 text-right tabular-nums">{r.count}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
      <Card className="flex flex-col gap-3">
        <h2 className="text-sm font-semibold">{t('feedback.perStaff')}</h2>
        <table className="text-sm">
          <thead className="text-xs text-muted">
            <tr>
              <th className="py-1 text-left font-medium">{t('feedback.person')}</th>
              <th className="py-1 text-right font-medium">{t('feedback.ratings.overall')}</th>
              <th className="py-1 text-right font-medium">{t('feedback.ratings.staff')}</th>
              <th className="py-1 text-right font-medium">{t('feedback.answers')}</th>
            </tr>
          </thead>
          <tbody>
            {(s.staff ?? []).map((p) => (
              <tr key={`${p.user_id}-${p.role}`} className="border-t border-border">
                <td className="py-1">
                  {p.name} <span className="text-xs text-muted">· {t(`feedback.roles.${p.role}`)}</span>
                </td>
                <td className="py-1 text-right tabular-nums">{p.overall ?? '—'}</td>
                <td className="py-1 text-right tabular-nums">{p.staff ?? '—'}</td>
                <td className="py-1 text-right tabular-nums">{p.count}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
    </div>
  )
}
