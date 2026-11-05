import { useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { KeyIcon, NoSymbolIcon, ArrowPathIcon } from '@heroicons/react/24/outline'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { TempPassword } from '@/components/ui/TempPassword'
import { ApiError } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { cn } from '@/lib/cn'
import {
  useCan,
  useResetStaffPassword,
  useRoles,
  useSaveStaff,
  useSetStaffActive,
  useStaffMember,
  type NewStaffResult,
  type StaffMember,
} from '@/lib/hotel'

export function StaffFormPage() {
  const { t } = useTranslation()
  const id = Number(useParams().id ?? 0)
  const member = useStaffMember(id)

  const title = id ? (member.data?.data.name ?? '…') : t('team.staff.add')
  return (
    <div className="flex flex-col gap-8">
      <PageHeader title={title} breadcrumbs={[{ label: t('nav.staff'), to: '/staff' }, { label: id ? title : t('admin.newHotel.crumb') }]} />
      {id && member.isPending ? <p className="text-muted">{t('common.loading')}</p> : <StaffForm member={member.data?.data} />}
    </div>
  )
}

function StaffForm({ member }: { member?: StaffMember }) {
  const { t } = useTranslation()
  const me = useMe()
  const can = useCan()
  const navigate = useNavigate()
  const roles = useRoles()
  const save = useSaveStaff(member?.id)
  const [created, setCreated] = useState<NewStaffResult | null>(null)
  const [f, setF] = useState({
    name: member?.name ?? '',
    email: member?.email ?? '',
    phone: member?.phone ?? '',
    role_id: member?.role ? String(member.role.id) : '',
    branch_ids: member?.branches.map((b) => b.id) ?? [],
  })
  const error = save.error instanceof ApiError ? save.error : null
  const editable = can('staff.manage')
  const isSelf = member?.id === me.data?.user.id
  const branches = me.data?.branches ?? []

  function submit(e: FormEvent) {
    e.preventDefault()
    save.mutate(
      { name: f.name, email: f.email, phone: f.phone || null, role_id: Number(f.role_id), branch_ids: f.branch_ids },
      {
        onSuccess: (res) => {
          if (!member) setCreated(res as NewStaffResult)
          else navigate('/staff')
        },
      },
    )
  }

  if (created) {
    return (
      <Card className="flex max-w-xl flex-col gap-4">
        <h2 className="text-base font-semibold">{t('team.staff.created', { name: created.data.name })}</h2>
        <p className="text-sm text-muted">{t('team.staff.createdBody')}</p>
        <TempPassword password={created.temporary_password} emailSent={created.email_sent} email={created.data.email} />
        <div className="flex justify-end">
          <Button onClick={() => navigate('/staff')}>{t('common.done')}</Button>
        </div>
      </Card>
    )
  }

  return (
    <div className="grid gap-6 lg:grid-cols-3">
      <form onSubmit={submit} noValidate className="flex flex-col gap-6 lg:col-span-2">
        {save.error && !error && <Alert>{t('common.serverUnreachable')}</Alert>}
        <Card className="grid gap-4 sm:grid-cols-2">
          <h2 className="text-base font-semibold sm:col-span-2">{t('team.staff.details')}</h2>
          <div className="sm:col-span-2">
            <Field label={t('profile.name')} value={f.name} disabled={!editable} onChange={(e) => setF({ ...f, name: e.target.value })} error={error?.field('name')} />
          </div>
          <Field label={t('team.staff.email')} type="email" value={f.email} disabled={!editable} onChange={(e) => setF({ ...f, email: e.target.value })} error={error?.field('email')} />
          <Field label={t('profile.phone')} type="tel" inputMode="tel" value={f.phone} disabled={!editable} onChange={(e) => setF({ ...f, phone: e.target.value })} error={error?.field('phone')} />
        </Card>

        <Card className="flex flex-col gap-4">
          <h2 className="text-base font-semibold">{t('team.staff.role')}</h2>
          {isSelf && <Alert tone="info">{t('team.staff.ownRole')}</Alert>}
          <div className="grid gap-3 sm:grid-cols-2">
            {roles.data?.data.map((r) => (
              <label
                key={r.id}
                className={cn(
                  'flex cursor-pointer flex-col gap-1 rounded-lg p-4 shadow-sm ring-1 transition duration-75',
                  String(r.id) === f.role_id ? 'bg-accent-soft ring-2 ring-accent' : 'ring-ring-strong hover:bg-sunken',
                  (!editable || isSelf) && 'pointer-events-none opacity-70',
                )}
              >
                <span className="flex items-center gap-3">
                  <input
                    type="radio"
                    name="role"
                    aria-label={r.name}
                    className="size-5 accent-accent"
                    value={r.id}
                    checked={String(r.id) === f.role_id}
                    onChange={(e) => setF({ ...f, role_id: e.target.value })}
                  />
                  <span className="font-medium">{r.name}</span>
                  {r.is_locked && <Badge tone="accent">{t('team.roles.locked')}</Badge>}
                </span>
                <span className="pl-8 text-xs text-muted">{t('team.roles.permissionCount', { count: r.permissions.length })}</span>
              </label>
            ))}
          </div>
          {error?.field('role_id') && <p className="text-sm text-danger-text">{error.field('role_id')}</p>}
        </Card>

        <Card className="flex flex-col gap-3">
          <h2 className="text-base font-semibold">{t('team.staff.branches')}</h2>
          <p className="text-sm text-muted">{t('team.staff.branchesHelp')}</p>
          <div className="flex flex-wrap gap-x-6">
            {branches.map((b) => (
              <label key={b.id} className="flex min-h-touch items-center gap-3 text-sm">
                <input
                  type="checkbox"
                  className="size-5 rounded accent-accent"
                  disabled={!editable}
                  checked={f.branch_ids.includes(b.id)}
                  onChange={(e) =>
                    setF({ ...f, branch_ids: e.target.checked ? [...f.branch_ids, b.id] : f.branch_ids.filter((x) => x !== b.id) })
                  }
                />
                {b.name}
              </label>
            ))}
          </div>
          {error?.field('branch_ids') && <p className="text-sm text-danger-text">{error.field('branch_ids')}</p>}
        </Card>

        {editable && (
          <div className="flex justify-end gap-3">
            <Button variant="ghost" onClick={() => navigate('/staff')}>
              {t('common.cancel')}
            </Button>
            <Button type="submit" disabled={save.isPending || !f.name || !f.email || !f.role_id}>
              {member ? t('common.save') : t('team.staff.create')}
            </Button>
          </div>
        )}
      </form>

      {member && editable && !isSelf && <StaffActions member={member} />}
    </div>
  )
}

function StaffActions({ member }: { member: StaffMember }) {
  const { t } = useTranslation()
  const setActive = useSetStaffActive(member.id)
  const reset = useResetStaffPassword(member.id)
  const [sendEmail, setSendEmail] = useState(true)
  const error = (setActive.error ?? reset.error) instanceof ApiError ? ((setActive.error ?? reset.error) as ApiError) : null
  const firstError = error ? Object.values(error.errors)[0]?.[0] ?? error.message : null

  return (
    <div className="flex flex-col gap-6">
      <Card className="flex flex-col gap-3">
        <h2 className="text-base font-semibold">{t('team.staff.access')}</h2>
        <Badge tone={member.status === 'active' ? 'ok' : 'danger'}>{t(`team.staff.statuses.${member.status}`)}</Badge>
        {firstError && <Alert>{firstError}</Alert>}
        {member.status === 'active' ? (
          <Button variant="secondary" disabled={setActive.isPending} onClick={() => setActive.mutate(false)}>
            <NoSymbolIcon aria-hidden className="size-5 text-danger-text" />
            {t('team.staff.disable')}
          </Button>
        ) : (
          <Button variant="secondary" disabled={setActive.isPending} onClick={() => setActive.mutate(true)}>
            <ArrowPathIcon aria-hidden className="size-4 text-icon" />
            {t('team.staff.enable')}
          </Button>
        )}
        <p className="text-xs text-muted">{t('team.staff.disableHelp')}</p>
      </Card>

      <Card className="flex flex-col gap-3">
        <h2 className="text-base font-semibold">{t('admin.hotel.resetPassword')}</h2>
        {reset.data ? (
          <TempPassword password={reset.data.temporary_password} emailSent={reset.data.email_sent} email={member.email} />
        ) : (
          <>
            <p className="text-xs text-muted">{t('admin.hotel.resetHelp', { name: member.name })}</p>
            <label className="flex min-h-touch items-center gap-3 text-sm">
              <input type="checkbox" className="size-5 rounded accent-accent" checked={sendEmail} onChange={(e) => setSendEmail(e.target.checked)} />
              {t('admin.hotel.emailIt', { email: member.email })}
            </label>
            <Button variant="secondary" disabled={reset.isPending} onClick={() => reset.mutate(sendEmail)}>
              <KeyIcon aria-hidden className="size-4 text-icon" />
              {t('admin.hotel.createPassword')}
            </Button>
          </>
        )}
      </Card>
    </div>
  )
}
