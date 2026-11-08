import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { PageHeader } from '@/components/ui/PageHeader'
import { errorText, lastSeen, STATUS_TONE, useDeviceAction, useHotelDevices } from '@/lib/security'

/** Team → Devices: every device your staff use; approve, sign out or block (decisions.md W7). */
export function DevicesPage() {
  const { t } = useTranslation()
  const q = useHotelDevices()
  const act = useDeviceAction()
  const rows = q.data?.data ?? []
  const pending = rows.filter((d) => d.status === 'pending').length

  return (
    <div className="flex flex-col gap-6">
      <PageHeader title={t('nav.devices')} subtitle={t('devices.subtitle')} />
      {pending > 0 && <Alert tone="info">{t('devices.waiting', { count: pending })}</Alert>}
      {act.error && <Alert>{errorText(act.error)}</Alert>}
      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[720px] text-left text-sm">
          <thead className="text-muted">
            <tr>
              <th className="px-4 py-2 font-medium">{t('devices.person')}</th>
              <th className="px-4 py-2 font-medium">{t('devices.device')}</th>
              <th className="px-4 py-2 font-medium">{t('devices.lastSeen')}</th>
              <th className="px-4 py-2 font-medium">{t('devices.status')}</th>
              <th className="px-4 py-2" />
            </tr>
          </thead>
          <tbody>
            {rows.map((d) => (
              <tr key={d.id} className="border-t border-border">
                <td className="px-4 py-2 font-medium">{d.user?.name}</td>
                <td className="px-4 py-2">
                  {d.name}
                  {d.approved && <span className="block text-xs text-ok-text">{t('devices.approved')}</span>}
                </td>
                <td className="px-4 py-2 text-muted">
                  {lastSeen(d.last_seen)}
                  {d.ip && <span className="block text-xs">{d.ip}</span>}
                </td>
                <td className="px-4 py-2">
                  <Badge tone={STATUS_TONE[d.status]}>{t(`security.statuses.${d.status}`)}</Badge>
                </td>
                <td className="px-2 py-1 text-right whitespace-nowrap">
                  {d.status === 'pending' && <Button disabled={act.isPending} onClick={() => act.mutate({ id: d.id, action: 'approve' })}>{t('devices.approve')}</Button>}
                  {d.status === 'active' && !d.this_device && (
                    <Button variant="ghost" disabled={act.isPending} onClick={() => act.mutate({ id: d.id, action: 'sign-out' })}>
                      {t('security.signOut')}
                    </Button>
                  )}
                  {d.status === 'blocked' ? (
                    <Button variant="ghost" disabled={act.isPending} onClick={() => act.mutate({ id: d.id, action: 'unblock' })}>
                      {t('devices.unblock')}
                    </Button>
                  ) : (
                    !d.this_device && (
                      <Button variant="ghost" className="text-danger-text" disabled={act.isPending} onClick={() => act.mutate({ id: d.id, action: 'block' })}>
                        {t('devices.block')}
                      </Button>
                    )
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {rows.length === 0 && <p className="p-6 text-center text-muted">{t('common.nothingYet')}</p>}
      </Card>
    </div>
  )
}
