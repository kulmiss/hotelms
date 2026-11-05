import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { ChevronRightIcon } from '@heroicons/react/20/solid'

type Crumb = { label: string; to?: string }

/** Filament page header: breadcrumbs, a bold heading and actions on the right. */
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
          <ol className="flex flex-wrap items-center gap-x-2 text-[13px] font-medium text-muted">
            {breadcrumbs.map((c, i) => (
              <li key={i} className="flex items-center gap-x-2">
                {i > 0 && <ChevronRightIcon aria-hidden className="size-4 text-icon" />}
                {c.to ? (
                  <Link to={c.to} className="transition duration-75 hover:text-text">
                    {c.label}
                  </Link>
                ) : (
                  <span>{c.label}</span>
                )}
              </li>
            ))}
          </ol>
        </nav>
      )}
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div className="flex min-w-0 flex-col gap-y-1">
          <h1 className="text-xl font-semibold tracking-tight sm:text-2xl">{title}</h1>
          {subtitle && <div className="text-sm text-muted">{subtitle}</div>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-3">{actions}</div>}
      </div>
    </div>
  )
}
