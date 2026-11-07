import { useState, type MouseEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { cn } from '@/lib/cn'
import { dateTime } from '@/lib/format'
import { useDebounced } from '@/lib/useDebounced'
import { useDoc, useDocs } from '@/lib/platform'

/**
 * Super Admin → Developer docs: the docs/ folder of the code, for the Owner and the tech team.
 * The server turns the Markdown into safe HTML (raw HTML in the files is removed).
 */
export function DocsPage() {
  const { t } = useTranslation()
  const { name = 'readme' } = useParams()
  const navigate = useNavigate()
  const [q, setQ] = useState('')
  const term = useDebounced(q.trim(), 250)
  const list = useDocs(term)
  const doc = useDoc(name)

  // Links between documents open here; other links open in a new tab.
  const follow = (e: MouseEvent<HTMLDivElement>) => {
    const a = (e.target as HTMLElement).closest('a')
    const href = a?.getAttribute('href') ?? ''
    if (!a || !href) return
    if (href.startsWith('/admin/docs/')) {
      e.preventDefault()
      navigate(href.split('#')[0])
      window.scrollTo({ top: 0 })
    } else if (/^https?:/.test(href)) {
      a.target = '_blank'
      a.rel = 'noreferrer'
    } else if (!href.startsWith('#')) {
      e.preventDefault() // a file of the code (e.g. ../backend/...): not a page
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader title={t('docs.title')} subtitle={t('docs.subtitle')} />
      <div className="grid gap-4 lg:grid-cols-[16rem_minmax(0,1fr)]">
        <aside className="flex flex-col gap-3 lg:sticky lg:top-20 lg:self-start">
          <Field label={t('docs.search')} type="search" value={q} placeholder={t('docs.searchHint')} onChange={(e) => setQ(e.target.value)} />
          <nav aria-label={t('docs.title')} className="panel flex flex-col p-1.5">
            {list.data?.data.map((d) => (
              <Link
                key={d.name}
                to={`/admin/docs/${d.name}`}
                aria-current={d.name === name ? 'page' : undefined}
                className={cn('flex min-h-touch flex-col justify-center rounded-lg px-3 py-1.5 text-[13px] transition duration-75 hover:bg-sunken', d.name === name && 'bg-sunken font-semibold')}
              >
                <span>{d.title}</span>
                {d.snippet && <span className="line-clamp-2 text-xs font-normal text-muted">…{d.snippet}…</span>}
              </Link>
            ))}
            {list.data && list.data.data.length === 0 && <p className="px-3 py-2 text-[13px] text-muted">{list.data.available ? t('docs.noHits') : t('docs.missing')}</p>}
          </nav>
        </aside>
        <Card className="min-w-0">
          {doc.isPending && <p className="text-[13px] text-muted">{t('common.loading')}</p>}
          {doc.isError && <p className="text-[13px] text-muted">{t('docs.notFound')}</p>}
          {doc.data && (
            <article className="flex flex-col gap-2">
              <p className="text-xs text-muted">{t('docs.updated', { date: dateTime(doc.data.data.updated_at) })}</p>
              {/* Safe HTML made by the server from our own docs/ files (raw HTML stripped). */}
              <div className="doc" onClick={follow} dangerouslySetInnerHTML={{ __html: doc.data.data.html }} />
            </article>
          )}
        </Card>
      </div>
    </div>
  )
}
