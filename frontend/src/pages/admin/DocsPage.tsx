import { useEffect, useState, type MouseEvent } from 'react'
import { DownloadIcon, ExternalLinkIcon, FileTextIcon } from 'lucide-react'
import { Link, useNavigate, useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { cn } from '@/lib/cn'
import { dateTime } from '@/lib/format'
import { useDebounced } from '@/lib/useDebounced'
import { docPdfUrl, useDoc, useDocPdf, useDocs, type DocPdf } from '@/lib/platform'

/** "840 KB", "1.2 MB". */
const fileSize = (bytes: number) => (bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`)
/** "shared-hosting" → "Shared hosting" (when there is no translation for a new PDF). */
const headline = (name: string) => name.charAt(0).toUpperCase() + name.slice(1).replace(/-/g, ' ')

/**
 * Super Admin → Developer docs: the docs/ folder of the code, for the Owner and the tech team.
 * The server turns the Markdown into safe HTML (raw HTML in the files is removed).
 * Also the deployment guide PDFs (deploy-guide/pdf): read in the page, open in a new tab or download.
 */
export function DocsPage() {
  const { t } = useTranslation()
  const { name = 'readme', pdf } = useParams()
  const navigate = useNavigate()
  const [q, setQ] = useState('')
  const term = useDebounced(q.trim(), 250)
  const list = useDocs(term)
  const doc = useDoc(name, !pdf)
  const pdfs = list.data?.pdfs ?? []
  const pdfTitle = (n: string) => t(`docs.pdfNames.${n}`, { defaultValue: headline(n) })

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
                className={cn('flex min-h-touch flex-col justify-center rounded-lg px-3 py-1.5 text-sm transition duration-75 hover:bg-sunken', d.name === name && 'bg-sunken font-semibold')}
              >
                <span>{d.title}</span>
                {d.snippet && <span className="line-clamp-2 text-xs font-normal text-muted">…{d.snippet}…</span>}
              </Link>
            ))}
            {list.data && list.data.data.length === 0 && <p className="px-3 py-2 text-sm text-muted">{list.data.available ? t('docs.noHits') : t('docs.missing')}</p>}
          </nav>
          {pdfs.length > 0 && (
            <nav aria-label={t('docs.pdfHeading')} className="panel flex flex-col p-1.5">
              <p className="px-3 pt-1.5 pb-1 text-xs font-medium text-muted">{t('docs.pdfHeading')}</p>
              {pdfs.map((p) => (
                <Link
                  key={p.name}
                  to={`/admin/docs/pdf/${p.name}`}
                  aria-current={p.name === pdf ? 'page' : undefined}
                  className={cn('flex min-h-touch items-center gap-2 rounded-lg px-3 py-1.5 text-sm transition duration-75 hover:bg-sunken', p.name === pdf && 'bg-sunken font-semibold')}
                >
                  <FileTextIcon className="size-4 shrink-0 text-muted" aria-hidden />
                  <span className="flex min-w-0 flex-col">
                    <span>{pdfTitle(p.name)}</span>
                    <span className="text-xs font-normal text-muted">{t('docs.pdfSize', { size: fileSize(p.size) })}</span>
                  </span>
                </Link>
              ))}
            </nav>
          )}
        </aside>
        <Card className="min-w-0">
          {pdf ? (
            <PdfView name={pdf} title={pdfTitle(pdf)} info={pdfs.find((p) => p.name === pdf)} />
          ) : (
            <>
              {doc.isPending && <p className="text-sm text-muted">{t('common.loading')}</p>}
              {doc.isError && <p className="text-sm text-muted">{t('docs.notFound')}</p>}
              {doc.data && (
                <article className="flex flex-col gap-2">
                  <p className="text-xs text-muted">{t('docs.updated', { date: dateTime(doc.data.data.updated_at) })}</p>
                  {/* Safe HTML made by the server from our own docs/ files (raw HTML stripped). */}
                  <div className="doc" onClick={follow} dangerouslySetInnerHTML={{ __html: doc.data.data.html }} />
                </article>
              )}
            </>
          )}
        </Card>
      </div>
    </div>
  )
}

/** One guide PDF: shown in the page (the browser's own PDF reader), with Download and Open in a new tab. */
function PdfView({ name, title, info }: { name: string; title: string; info?: DocPdf }) {
  const { t } = useTranslation()
  const file = useDocPdf(name)
  const [url, setUrl] = useState<string | null>(null)
  useEffect(() => {
    if (!file.data || typeof URL.createObjectURL !== 'function') return
    const u = URL.createObjectURL(file.data)
    setUrl(u)
    return () => {
      URL.revokeObjectURL(u)
      setUrl(null)
    }
  }, [file.data])

  return (
    <article className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="min-w-0">
          <h1 className="text-lg font-semibold">{title}</h1>
          {info && <p className="text-xs text-muted">{t('docs.updated', { date: dateTime(info.updated_at) })} · {t('docs.pdfSize', { size: fileSize(info.size) })}</p>}
        </div>
        <div className="flex flex-wrap gap-2">
          {url && (
            <a href={url} target="_blank" rel="noreferrer" className={buttonClass('secondary')}>
              <ExternalLinkIcon className="size-4" aria-hidden />
              {t('docs.openTab')}
            </a>
          )}
          <a href={docPdfUrl(name)} download={`hotelms-deploy-${name}.pdf`} className={buttonClass('primary')}>
            <DownloadIcon className="size-4" aria-hidden />
            {t('docs.download')}
          </a>
        </div>
      </div>
      {file.isPending && <p className="text-sm text-muted">{t('common.loading')}</p>}
      {file.isError && <p className="text-sm text-muted">{t('docs.notFound')}</p>}
      {url && <iframe src={url} title={title} className="h-[75vh] w-full rounded-lg border border-ring bg-surface" />}
      {url && <p className="text-xs text-muted">{t('docs.pdfTip')}</p>}
    </article>
  )
}
