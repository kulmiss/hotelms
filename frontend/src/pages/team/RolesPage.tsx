import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { PlusIcon } from '@heroicons/react/20/solid'
import { LockClosedIcon } from '@heroicons/react/24/outline'
import { Badge } from '@/components/ui/Badge'
import { buttonClass } from '@/components/ui/Button'
import { PageHeader } from '@/components/ui/PageHeader'
import { useRoles } from '@/lib/hotel'

export function RolesPage() {
  const { t } = useTranslation()
  const roles = useRoles()

  return (
    <div className="flex flex-col gap-8">
      <PageHeader
        title={t('nav.roles')}
        subtitle={t('team.roles.subtitle')}
        actions={
          <Link to="/roles/new" className={buttonClass('primary')}>
            <PlusIcon aria-hidden className="size-5" />
            {t('team.roles.new')}
          </Link>
        }
      />
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {roles.data?.data.map((r) => (
          <Link key={r.id} to={`/roles/${r.id}`} className="panel flex flex-col gap-3 p-6 transition duration-75 hover:bg-sunken">
            <div className="flex items-start justify-between gap-2">
              <h2 className="text-base font-semibold">{r.name}</h2>
              {r.is_locked ? (
                <Badge tone="accent">
                  <LockClosedIcon aria-hidden className="mr-1 size-3.5" />
                  {t('team.roles.locked')}
                </Badge>
              ) : r.is_default ? (
                <Badge tone="grey">{t('team.roles.default')}</Badge>
              ) : null}
            </div>
            {r.description && <p className="text-sm text-muted">{r.description}</p>}
            <dl className="mt-auto grid grid-cols-3 gap-2 text-sm">
              <div>
                <dt className="text-muted">{t('team.roles.staffCount')}</dt>
                <dd className="font-semibold">{r.users_count ?? 0}</dd>
              </div>
              <div>
                <dt className="text-muted">{t('team.roles.permissions')}</dt>
                <dd className="font-semibold">{r.permissions.length}</dd>
              </div>
              <div>
                <dt className="text-muted">{t('team.roles.maxDiscount')}</dt>
                <dd className="font-semibold">{Number(r.max_discount_percent)}%</dd>
              </div>
            </dl>
          </Link>
        ))}
      </div>
    </div>
  )
}
