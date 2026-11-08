import { dateTime } from '@/lib/format'
import { useState } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { usePlatformActivity } from '@/lib/platform'
import { useDebounced } from '@/lib/useDebounced'

/** Super Admin → Activity log (area 11): every change the HotelMS team made. */
export function PlatformActivityPage() {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const term = useDebounced(search.trim(), 300)
  const log = usePlatformActivity({ search: term || undefined, page })
  const meta = log.data?.meta

  return (
    <div className="mx-auto flex max-w-6xl flex-col gap-6">
      <PageHeader title={t('nav.platformActivity')} subtitle={t('platformActivity.subtitle')} />
      <div className="max-w-sm">
        <Field
          label={t('platformActivity.search')}
          type="search"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value)
            setPage(1)
          }}
        />
      </div>
      <Card className="overflow-x-auto p-0">
        {log.isPending ? (
          <p className="p-5 text-sm text-muted">{t('common.loading')}</p>
        ) : log.data?.data.length === 0 ? (
          <p className="p-5 text-sm text-muted">{t('common.nothingYet')}</p>
        ) : (
          <table className="w-full min-w-[720px] text-left text-sm">
            <thead>
              <tr>
                <th className="px-5 py-3 font-medium">{t('platformActivity.when')}</th>
                <th className="px-5 py-3 font-medium">{t('platformActivity.who')}</th>
                <th className="px-5 py-3 font-medium">{t('platformActivity.what')}</th>
                <th className="px-5 py-3 font-medium">{t('platformActivity.hotel')}</th>
              </tr>
            </thead>
            <tbody>
              {log.data?.data.map((a) => (
                <tr key={a.id} className="border-t border-border">
                  <td className="px-5 py-3 whitespace-nowrap text-muted">{a.at && dateTime(a.at)}</td>
                  <td className="px-5 py-3">{a.user ?? '—'}</td>
                  <td className="px-5 py-3">{a.description}</td>
                  <td className="px-5 py-3">
                    {a.hotel ? (
                      <Link to={`/admin/hotels/${a.hotel.id}`} className="tap-link text-accent-text hover:underline">
                        {a.hotel.name}
                      </Link>
                    ) : (
                      '—'
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>
      {meta && meta.last_page > 1 && (
        <div className="flex items-center gap-2 text-sm">
          <Button variant="secondary" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            {t('common.previous')}
          </Button>
          <span className="text-muted">{t('common.pageOf', { page: meta.current_page, last: meta.last_page })}</span>
          <Button variant="secondary" disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>
            {t('common.next')}
          </Button>
        </div>
      )}
    </div>
  )
}
