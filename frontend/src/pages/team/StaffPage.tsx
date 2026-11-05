import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { PlusIcon } from '@heroicons/react/20/solid'
import { UserGroupIcon } from '@heroicons/react/24/outline'
import { Badge } from '@/components/ui/Badge'
import { buttonClass } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { PageHeader } from '@/components/ui/PageHeader'
import { date } from '@/lib/format'
import { useCan, useStaff } from '@/lib/hotel'

export function StaffPage() {
  const { t } = useTranslation()
  const staff = useStaff()
  const can = useCan()

  return (
    <div className="flex flex-col gap-8">
      <PageHeader
        title={t('nav.staff')}
        subtitle={t('team.staff.subtitle')}
        actions={
          can('staff.manage') && (
            <Link to="/staff/new" className={buttonClass('primary')}>
              <PlusIcon aria-hidden className="size-4" />
              {t('team.staff.add')}
            </Link>
          )
        }
      />
      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[720px] text-left text-sm">
          <thead className="bg-sunken text-text">
            <tr>
              <th className="px-4 py-3 font-semibold">{t('team.staff.name')}</th>
              <th className="px-4 py-3 font-semibold">{t('team.staff.role')}</th>
              <th className="px-4 py-3 font-semibold">{t('team.staff.branches')}</th>
              <th className="px-4 py-3 font-semibold">{t('team.staff.status')}</th>
              <th className="px-4 py-3 font-semibold">{t('team.staff.lastSignIn')}</th>
            </tr>
          </thead>
          <tbody>
            {staff.data?.data.map((s) => (
              <tr key={s.id} className="border-t border-border transition duration-75 hover:bg-sunken">
                <td className="px-4 py-2">
                  <Link to={`/staff/${s.id}`} className="flex min-h-touch flex-col justify-center">
                    <span className="font-medium text-text">{s.name}</span>
                    <span className="text-xs text-muted">{s.email}</span>
                  </Link>
                </td>
                <td className="px-4 py-2">{s.role ? <Badge tone={s.role.is_locked ? 'accent' : 'grey'}>{s.role.name}</Badge> : '—'}</td>
                <td className="px-4 py-2 text-muted">{s.branches.length ? s.branches.map((b) => b.name).join(', ') : t('team.staff.allBranches')}</td>
                <td className="px-4 py-2">
                  <Badge tone={s.status === 'active' ? 'ok' : 'danger'}>{t(`team.staff.statuses.${s.status}`)}</Badge>
                </td>
                <td className="px-4 py-2 text-muted">{s.must_change_password ? t('admin.hotel.notSignedInYet') : date(s.last_login_at)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {staff.data && staff.data.data.length === 0 && (
          <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
            <span className="flex size-12 items-center justify-center rounded-full bg-sunken">
              <UserGroupIcon aria-hidden className="size-6 text-icon" />
            </span>
            <p className="text-sm text-muted">{t('team.staff.empty')}</p>
          </div>
        )}
      </Card>
    </div>
  )
}
