import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { PlusIcon, UsersRoundIcon } from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import { buttonClass } from '@/components/ui/Button'
import { DataTable } from '@/components/ui/DataTable'
import { PageHeader } from '@/components/ui/PageHeader'
import { date } from '@/lib/format'
import { useCan, useStaff, type StaffMember } from '@/lib/hotel'

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
      <DataTable<StaffMember>
        id="staff"
        rows={staff.data?.data ?? []}
        rowKey={(s) => s.id}
        loading={staff.isLoading}
        error={staff.error}
        onRetry={() => staff.refetch()}
        csvName="staff"
        canExport={can('staff.manage')}
        empty={
          <div className="flex flex-col items-center gap-3 py-6">
            <span className="flex size-12 items-center justify-center rounded-full bg-sunken">
              <UsersRoundIcon aria-hidden className="size-6 text-icon" />
            </span>
            <p>{t('team.staff.empty')}</p>
          </div>
        }
        columns={[
          {
            key: 'name', label: t('team.staff.name'), hideable: false, sortValue: (s) => s.name, csv: (s) => `${s.name} <${s.email}>`,
            render: (s) => (
              <Link to={`/staff/${s.id}`} className="flex min-h-touch flex-col justify-center">
                <span className="font-medium text-text">{s.name}</span>
                <span className="text-xs text-muted">{s.email}</span>
              </Link>
            ),
          },
          { key: 'role', label: t('team.staff.role'), sortValue: (s) => s.role?.name ?? '', render: (s) => (s.role ? <Badge tone={s.role.is_locked ? 'accent' : 'grey'}>{s.role.name}</Badge> : '—') },
          {
            key: 'branches', label: t('team.staff.branches'), sortValue: (s) => s.branches.map((b) => b.name).join(', '),
            render: (s) => <span className="text-muted">{s.branches.length ? s.branches.map((b) => b.name).join(', ') : t('team.staff.allBranches')}</span>,
          },
          { key: 'status', label: t('team.staff.status'), sortValue: (s) => s.status, render: (s) => <Badge tone={s.status === 'active' ? 'ok' : 'danger'}>{t(`team.staff.statuses.${s.status}`)}</Badge> },
          {
            key: 'last', label: t('team.staff.lastSignIn'), sortValue: (s) => s.last_login_at ?? '',
            render: (s) => <span className="text-muted">{s.must_change_password ? t('admin.hotel.notSignedInYet') : date(s.last_login_at)}</span>,
          },
        ]}
      />
    </div>
  )
}
