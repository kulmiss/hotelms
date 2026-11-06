import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { PlusIcon } from '@heroicons/react/20/solid'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { ApiError } from '@/lib/api'
import { date } from '@/lib/format'
import { useDeleteRelease, usePublishRelease, useReleases, useSaveRelease, type Release } from '@/lib/platform'
import { TextArea } from '@/pages/help/HelpPage'

const errorOf = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : e ? String(e) : null)

/**
 * Super Admin → Releases (area 5): the live version and "What's new" notes for hotels.
 * Updating the code itself follows docs/hosting.md (shared hosting cannot update itself).
 */
export function ReleasesPage() {
  const { t } = useTranslation()
  const q = useReleases()
  const publish = usePublishRelease()
  const remove = useDeleteRelease()
  const [editing, setEditing] = useState<Release | 'new' | null>(null)

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-6">
      <PageHeader
        title={t('nav.releases')}
        subtitle={q.data ? t('releases.live', { version: q.data.live_version }) : undefined}
        actions={
          <Button onClick={() => setEditing('new')}>
            <PlusIcon aria-hidden className="size-4" />
            {t('releases.new')}
          </Button>
        }
      />
      <Alert tone="info">{t('releases.how')}</Alert>
      {editing && <ReleaseForm release={editing === 'new' ? null : editing} onDone={() => setEditing(null)} />}
      {(publish.error || remove.error) && <Alert>{errorOf(publish.error ?? remove.error)}</Alert>}
      <ul className="flex flex-col gap-3">
        {q.data?.data.map((r) => (
          <li key={r.id}>
            <Card className="flex flex-col gap-2">
              <div className="flex flex-wrap items-center gap-2">
                <strong className="text-[13px]">{r.version}</strong>
                <span className="min-w-0 flex-1 text-[13px] font-medium">{r.title}</span>
                <Badge tone={r.status === 'published' ? 'ok' : 'grey'}>{r.status === 'published' ? t('releases.publishedOn', { date: date(r.published_at?.slice(0, 10) ?? null) }) : t('releases.draft')}</Badge>
              </div>
              <p className="text-[13px] whitespace-pre-wrap text-muted">{r.body}</p>
              <div className="flex gap-2">
                <Button variant="ghost" onClick={() => setEditing(r)}>
                  {t('common.edit')}
                </Button>
                {r.status === 'draft' && (
                  <>
                    <Button disabled={publish.isPending} onClick={() => window.confirm(t('releases.publishConfirm')) && publish.mutate(r.id)}>
                      {t('releases.publish')}
                    </Button>
                    <Button variant="ghost" disabled={remove.isPending} onClick={() => remove.mutate(r.id)}>
                      {t('common.delete')}
                    </Button>
                  </>
                )}
              </div>
            </Card>
          </li>
        ))}
      </ul>
      {q.data?.data.length === 0 && <p className="text-[13px] text-muted">{t('releases.none')}</p>}
    </div>
  )
}

function ReleaseForm({ release, onDone }: { release: Release | null; onDone: () => void }) {
  const { t } = useTranslation()
  const save = useSaveRelease()
  const [f, setF] = useState({ version: release?.version ?? '', title: release?.title ?? '', body: release?.body ?? '' })
  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate({ id: release?.id, ...f }, { onSuccess: onDone })
  }
  return (
    <Card>
      <form onSubmit={submit} className="flex flex-col gap-3">
        {save.error && <Alert>{errorOf(save.error)}</Alert>}
        <div className="grid gap-3 sm:grid-cols-[10rem_1fr]">
          <Field label={t('releases.version')} value={f.version} required maxLength={20} placeholder="1.2.0" onChange={(e) => setF({ ...f, version: e.target.value })} />
          <Field label={t('releases.title')} value={f.title} required maxLength={150} onChange={(e) => setF({ ...f, title: e.target.value })} />
        </div>
        <TextArea label={t('releases.body')} value={f.body} rows={6} onChange={(body) => setF({ ...f, body })} />
        <div className="flex gap-2">
          <Button type="submit" disabled={save.isPending}>
            {t('common.save')}
          </Button>
          <Button variant="ghost" onClick={onDone}>
            {t('common.cancel')}
          </Button>
        </div>
      </form>
    </Card>
  )
}
