import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { ChevronRight } from 'lucide-react'

type Crumb = { label: string; to?: string }

/** The template's page heading (bold 24px title, grey line under it, actions on the right), with breadcrumbs. */
export function PageHeader({
  title,
  subtitle,
  actions,
  breadcrumbs,
}: {
  title: string
  subtitle?: ReactNode
  actions?: ReactNode
  breadcrumbs?: Crumb[]
}) {
  return (
    <div className="flex flex-col gap-y-2">
      {breadcrumbs && (
        <nav aria-label="Breadcrumb">
          <ol className="flex flex-wrap items-center gap-1.5 text-sm break-words text-muted sm:gap-2.5">
            {breadcrumbs.map((c, i) => (
              <li key={i} className="inline-flex items-center gap-1.5 sm:gap-2.5">
                {i > 0 && <ChevronRight aria-hidden className="size-3.5" />}
                {c.to ? (
                  <Link to={c.to} className="transition-colors hover:text-text">
                    {c.label}
                  </Link>
                ) : (
                  <span aria-current="page" className="font-normal text-text">
                    {c.label}
                  </span>
                )}
              </li>
            ))}
          </ol>
        </nav>
      )}
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <div className="flex min-w-0 flex-col gap-y-1">
          <h1 className="text-2xl font-bold tracking-tight">{title}</h1>
          {subtitle && <div className="text-muted">{subtitle}</div>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
      </div>
    </div>
  )
}
