import { useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { ApiError } from '@/lib/api'
import { useMe } from '@/lib/auth'
import { useDeleteRole, usePermissionCatalog, useRole, useSaveRole, type Role } from '@/lib/hotel'

export function RoleFormPage() {
  const { t } = useTranslation()
  const id = Number(useParams().id ?? 0)
  const role = useRole(id)
  const title = id ? (role.data?.data.name ?? '…') : t('team.roles.new')
  return (
    <div className="flex flex-col gap-8">
      <PageHeader title={title} breadcrumbs={[{ label: t('nav.roles'), to: '/roles' }, { label: id ? title : t('admin.newHotel.crumb') }]} />
      {id && role.isPending ? <p className="text-muted">{t('common.loading')}</p> : <RoleForm role={role.data?.data} />}
    </div>
  )
}

function RoleForm({ role }: { role?: Role }) {
  const { t } = useTranslation()
  const me = useMe()
  const navigate = useNavigate()
  const catalog = usePermissionCatalog()
  const save = useSaveRole(role?.id)
  const remove = useDeleteRole(role?.id ?? 0)
  const [name, setName] = useState(role?.name ?? '')
  const [description, setDescription] = useState(role?.description ?? '')
  const [discount, setDiscount] = useState(role ? String(Number(role.max_discount_percent)) : '0')
  const [perms, setPerms] = useState<Set<string>>(new Set(role?.permissions ?? []))
  const error = (save.error ?? remove.error) instanceof ApiError ? ((save.error ?? remove.error) as ApiError) : null
  const readOnly = role?.is_locked || role?.id === me.data?.role?.id
  const mine = new Set(me.data?.permissions ?? [])

  const toggle = (keys: string[], on: boolean) =>
    setPerms((cur) => {
      const next = new Set(cur)
      keys.forEach((k) => (on ? next.add(k) : next.delete(k)))
      return next
    })

  function submit(e: FormEvent) {
    e.preventDefault()
    save.mutate(
      { name, description: description || null, max_discount_percent: discount || '0', permissions: [...perms] },
      { onSuccess: () => navigate('/roles') },
    )
  }

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-6">
      {role?.is_locked && <Alert tone="info">{t('team.roles.lockedHelp')}</Alert>}
      {!role?.is_locked && readOnly && <Alert tone="info">{t('team.roles.ownRoleHelp')}</Alert>}
      {error && <Alert>{Object.values(error.errors)[0]?.[0] ?? error.message}</Alert>}

      <Card className="grid gap-4 sm:grid-cols-3">
        <Field label={t('team.roles.name')} value={name} disabled={readOnly} onChange={(e) => setName(e.target.value)} error={error?.field('name')} />
        <Field label={t('admin.plans.description')} value={description} disabled={readOnly} onChange={(e) => setDescription(e.target.value)} />
        <Field
          label={t('team.roles.maxDiscountLong')}
          type="number"
          inputMode="decimal"
          min={0}
          max={100}
          value={discount}
          disabled={readOnly}
          hint={t('team.roles.maxDiscountHint')}
          onChange={(e) => setDiscount(e.target.value)}
          error={error?.field('max_discount_percent')}
        />
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        {catalog.data?.data.map((g) => {
          const keys = g.permissions.map((p) => p.key)
          const allOn = keys.every((k) => perms.has(k))
          return (
            <Card key={g.group} className="flex flex-col gap-2">
              <div className="flex items-center justify-between gap-2">
                <h2 className="text-base font-semibold">{t(`permissionGroups.${g.group}`)}</h2>
                {!readOnly && (
                  <button
                    type="button"
                    onClick={() => toggle(keys.filter((k) => mine.has(k)), !allOn)}
                    className="min-h-touch rounded-lg px-2 text-sm font-medium text-accent-text hover:bg-sunken"
                  >
                    {allOn ? t('team.roles.none') : t('team.roles.all')}
                  </button>
                )}
              </div>
              <ul className="flex flex-col">
                {g.permissions.map((p) => (
                  <li key={p.key}>
                    <label className="flex min-h-touch items-center gap-3 rounded-lg px-1 text-sm hover:bg-sunken">
                      <input
                        type="checkbox"
                        className="size-5 rounded"
                        checked={role?.is_locked || perms.has(p.key)}
                        disabled={readOnly || !mine.has(p.key)}
                        onChange={(e) => toggle([p.key], e.target.checked)}
                      />
                      <span className="flex-1">{t(`permissionNames.${p.key}`, p.key)}</span>
                      {p.module && <span className="text-xs text-muted">{t(`modules.${p.module}`)}</span>}
                    </label>
                  </li>
                ))}
              </ul>
            </Card>
          )
        })}
      </div>

      {!readOnly && (
        <div className="flex flex-wrap items-center justify-between gap-3">
          {role && !role.is_default ? (
            <Button variant="ghost" className="text-danger-text" disabled={remove.isPending} onClick={() => remove.mutate(undefined, { onSuccess: () => navigate('/roles') })}>
              {t('team.roles.delete')}
            </Button>
          ) : (
            <span />
          )}
          <div className="flex gap-3">
            <Button variant="ghost" onClick={() => navigate('/roles')}>
              {t('common.cancel')}
            </Button>
            <Button type="submit" disabled={save.isPending || !name}>
              {role ? t('common.save') : t('team.roles.create')}
            </Button>
          </div>
        </div>
      )}
    </form>
  )
}
