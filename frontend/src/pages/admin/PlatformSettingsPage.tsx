import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Select } from '@/components/ui/Select'
import { usePlans, usePlatformSettings, useSaveSettings, type PlatformSettings } from '@/lib/admin'
import { ApiError } from '@/lib/api'

export function PlatformSettingsPage() {
  const { t } = useTranslation()
  const settings = usePlatformSettings()

  return (
    <div className="mx-auto flex max-w-2xl flex-col gap-6">
      <PageHeader title={t('nav.platformSettings')} />
      {settings.data && <SettingsForm initial={settings.data.data} />}
    </div>
  )
}

function SettingsForm({ initial }: { initial: PlatformSettings }) {
  const { t } = useTranslation()
  const plans = usePlans()
  const save = useSaveSettings()
  const [days, setDays] = useState(String(initial.default_trial_days))
  const [plan, setPlan] = useState(initial.default_trial_plan_id ? String(initial.default_trial_plan_id) : '')
  const [grace, setGrace] = useState(String(initial.grace_days))
  const error = save.error instanceof ApiError ? save.error : null

  function submit(e: FormEvent) {
    e.preventDefault()
    save.mutate({ default_trial_days: Number(days), default_trial_plan_id: plan ? Number(plan) : null, grace_days: Number(grace) })
  }

  return (
    <Card>
      <form onSubmit={submit} noValidate className="flex flex-col gap-4">
        <h2 className="font-semibold">{t('admin.settings.trials')}</h2>
        {save.isSuccess && <Alert tone="ok">{t('common.saved')}</Alert>}
        <Field
          label={t('admin.settings.trialDays')}
          type="number"
          inputMode="numeric"
          min={0}
          max={365}
          value={days}
          hint={t('admin.settings.trialDaysHint')}
          onChange={(e) => setDays(e.target.value)}
          error={error?.field('default_trial_days')}
        />
        <Select label={t('admin.settings.trialPlan')} value={plan} hint={t('admin.settings.trialPlanHint')} onChange={(e) => setPlan(e.target.value)}>
          <option value="">{t('admin.settings.samePlan')}</option>
          {plans.data?.data.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </Select>
        <Field
          label={t('admin.settings.graceDays')}
          type="number"
          inputMode="numeric"
          min={0}
          max={30}
          value={grace}
          hint={t('admin.settings.graceHint')}
          onChange={(e) => setGrace(e.target.value)}
          error={error?.field('grace_days')}
        />
        <div className="flex justify-end">
          <Button type="submit" disabled={save.isPending}>
            {t('common.save')}
          </Button>
        </div>
      </form>
    </Card>
  )
}
