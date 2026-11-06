import { useEffect, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { CheckIcon, LockClosedIcon, PlusIcon } from '@heroicons/react/20/solid'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Select } from '@/components/ui/Select'
import { TempPassword } from '@/components/ui/TempPassword'
import { ApiError } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { date } from '@/lib/format'
import { useAddMember, useSavePermissions, useTeam, useUpdateMember } from '@/lib/platform'

const errorOf = (e: unknown) => (e instanceof ApiError ? e.message : e ? String(e) : null)

/** Super Admin → Team and security (area 9): members, roles, and what each role may do. Owner only. */
export function TeamPage() {
  const { t } = useTranslation()
  const team = useTeam()
  const me = useMe()
  const update = useUpdateMember()
  const [adding, setAdding] = useState(false)

  if (team.isPending) return <p className="text-[13px] text-muted">{t('common.loading')}</p>
  if (team.isError) return <Alert>{errorOf(team.error)}</Alert>
  const { data: members, roles, permissions } = team.data

  return (
    <div className="mx-auto flex max-w-6xl flex-col gap-6">
      <PageHeader
        title={t('nav.team')}
        subtitle={t('platformTeam.subtitle')}
        actions={
          <Button onClick={() => setAdding((a) => !a)}>
            <PlusIcon aria-hidden className="size-4" />
            {t('platformTeam.add')}
          </Button>
        }
      />
      {adding && <AddMember roles={roles} onDone={() => setAdding(false)} />}
      {update.error && <Alert>{errorOf(update.error)}</Alert>}

      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[720px] text-left text-[13px]">
          <thead className="bg-sunken">
            <tr>
              <th className="px-5 py-3 font-medium">{t('platformTeam.member')}</th>
              <th className="px-5 py-3 font-medium">{t('platformTeam.role')}</th>
              <th className="px-5 py-3 font-medium">{t('platformTeam.twoStep')}</th>
              <th className="px-5 py-3 font-medium">{t('platformTeam.lastLogin')}</th>
              <th className="px-5 py-3 font-medium">
                <span className="sr-only">{t('common.actions')}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {members.map((m) => {
              const self = m.id === me.data?.user.id
              return (
                <tr key={m.id} className="border-t border-border">
                  <td className="px-5 py-3">
                    <span className="block font-medium">{m.name}</span>
                    <span className="text-xs text-muted">{m.email}</span>
                  </td>
                  <td className="px-5 py-3">
                    <select
                      aria-label={t('platformTeam.roleOf', { name: m.name })}
                      value={m.role}
                      disabled={self || update.isPending}
                      onChange={(e) => update.mutate({ id: m.id, role: e.target.value })}
                      className="min-h-touch rounded-lg border-0 bg-surface px-2 text-[13px] ring-1 ring-ring-strong disabled:bg-sunken disabled:text-muted"
                    >
                      {roles.map((r) => (
                        <option key={r} value={r}>
                          {t(`platformTeam.roles.${r}`)}
                        </option>
                      ))}
                    </select>
                  </td>
                  <td className="px-5 py-3">{m.two_factor ? <Badge tone="ok">{t('common.on')}</Badge> : <Badge tone="warn">{t('common.off')}</Badge>}</td>
                  <td className="px-5 py-3 text-muted">{m.invited ? t('platformTeam.invited') : date(m.last_login_at)}</td>
                  <td className="px-5 py-3 text-right">
                    {!self &&
                      (m.status === 'active' ? (
                        <Button variant="ghost" disabled={update.isPending} onClick={() => window.confirm(t('platformTeam.disableConfirm', { name: m.name })) && update.mutate({ id: m.id, status: 'disabled' })}>
                          {t('platformTeam.disable')}
                        </Button>
                      ) : (
                        <Button variant="secondary" disabled={update.isPending} onClick={() => update.mutate({ id: m.id, status: 'active' })}>
                          {t('platformTeam.enable')}
                        </Button>
                      ))}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </Card>

      <PermissionGrid roles={roles.filter((r) => r !== 'owner')} permissions={permissions} />
    </div>
  )
}

function AddMember({ roles, onDone }: { roles: string[]; onDone: () => void }) {
  const { t } = useTranslation()
  const add = useAddMember()
  const [f, setF] = useState({ name: '', email: '', phone: '', role: 'support' })
  const [password, setPassword] = useState<string | null>(null)
  const submit = (e: FormEvent) => {
    e.preventDefault()
    add.mutate({ name: f.name.trim(), email: f.email.trim(), phone: f.phone.trim() || undefined, role: f.role }, { onSuccess: (r) => setPassword(r.temporary_password) })
  }
  if (password)
    return (
      <Card className="flex flex-col gap-3">
        <p className="text-[13px]">{t('platformTeam.added', { name: f.name })}</p>
        <TempPassword password={password} emailSent={false} email={f.email} />
        <div>
          <Button variant="secondary" onClick={onDone}>
            {t('common.done')}
          </Button>
        </div>
      </Card>
    )
  return (
    <Card>
      <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {add.error && (
          <div className="sm:col-span-2 lg:col-span-4">
            <Alert>{errorOf(add.error)}</Alert>
          </div>
        )}
        <Field label={t('platformTeam.name')} value={f.name} required maxLength={100} onChange={(e) => setF({ ...f, name: e.target.value })} />
        <Field label={t('platformTeam.email')} type="email" value={f.email} required onChange={(e) => setF({ ...f, email: e.target.value })} />
        <Field label={t('platformTeam.phone')} type="tel" value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} />
        <Select label={t('platformTeam.role')} value={f.role} onChange={(e) => setF({ ...f, role: e.target.value })}>
          {roles.map((r) => (
            <option key={r} value={r}>
              {t(`platformTeam.roles.${r}`)}
            </option>
          ))}
        </Select>
        <div className="sm:col-span-2 lg:col-span-4">
          <Button type="submit" disabled={add.isPending}>
            {t('platformTeam.add')}
          </Button>
        </div>
      </form>
    </Card>
  )
}

function PermissionGrid({ roles, permissions }: { roles: string[]; permissions: Record<string, string[]> }) {
  const { t } = useTranslation()
  const save = useSavePermissions()
  const [grid, setGrid] = useState(permissions)
  useEffect(() => setGrid(permissions), [permissions])
  const changed = JSON.stringify(grid) !== JSON.stringify(permissions)
  const toggle = (p: string, r: string) => setGrid((g) => ({ ...g, [p]: g[p]?.includes(r) ? g[p].filter((x) => x !== r) : [...(g[p] ?? []), r] }))

  return (
    <Card className="flex flex-col gap-4">
      <div>
        <h2 className="text-sm font-semibold">{t('platformTeam.grid')}</h2>
        <p className="text-xs text-muted">{t('platformTeam.gridHint')}</p>
      </div>
      {save.error && <Alert>{errorOf(save.error)}</Alert>}
      <div className="overflow-x-auto">
        <table className="w-full min-w-[640px] text-left text-[13px]">
          <thead>
            <tr className="border-b border-border">
              <th className="py-2 pr-4 font-medium">{t('platformTeam.permission')}</th>
              <th className="px-2 py-2 text-center font-medium">
                <span className="inline-flex items-center gap-1">
                  <LockClosedIcon aria-hidden className="size-3.5 text-icon" />
                  {t('platformTeam.roles.owner')}
                </span>
              </th>
              {roles.map((r) => (
                <th key={r} className="px-2 py-2 text-center font-medium">
                  {t(`platformTeam.roles.${r}`)}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {Object.keys(grid).map((p) => (
              <tr key={p} className="border-b border-border last:border-0">
                <td className="py-2 pr-4">{t(`platformTeam.permissions.${p.replace('.', '_')}`)}</td>
                <td className="px-2 py-2 text-center">
                  <CheckIcon aria-label={t('common.on')} className="inline size-4 text-icon" />
                </td>
                {roles.map((r) => (
                  <td key={r} className="px-2 py-2 text-center">
                    <input
                      type="checkbox"
                      aria-label={`${t(`platformTeam.roles.${r}`)}: ${t(`platformTeam.permissions.${p.replace('.', '_')}`)}`}
                      checked={grid[p]?.includes(r) ?? false}
                      onChange={() => toggle(p, r)}
                      className="size-4 accent-accent"
                    />
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="flex gap-2">
        <Button disabled={!changed || save.isPending} onClick={() => save.mutate(grid)}>
          {save.isPending ? t('common.saving') : t('common.save')}
        </Button>
        {changed && (
          <Button variant="ghost" onClick={() => setGrid(permissions)}>
            {t('common.cancel')}
          </Button>
        )}
      </div>
    </Card>
  )
}
