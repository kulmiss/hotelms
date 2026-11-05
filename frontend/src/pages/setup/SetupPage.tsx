import { useState, type ComponentType, type FormEvent } from 'react'
import { useNavigate } from 'react-router'
import { useTranslation } from 'react-i18next'
import { CheckIcon, ForwardIcon } from '@heroicons/react/20/solid'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { Select } from '@/components/ui/Select'
import { TempPassword } from '@/components/ui/TempPassword'
import { ApiError, put } from '@/lib/api'
import { ME_KEY, useChangePassword, useLogout, useMe } from '@/lib/auth'
import { BrandMark, useBrand } from '@/lib/brand'
import { cn } from '@/lib/cn'
import { date } from '@/lib/format'
import { useRoles, useSaveStaff, useStaff, type NewStaffResult } from '@/lib/hotel'
import { SETUP_STEPS, useFinishSetup, useMarkStep, useSetup, type Setup, type SetupStepKey, type StepState } from '@/lib/setup'
import {
  BookingRulesSettings,
  BranchesSettings,
  CancellationSettings,
  CurrenciesSettings,
  ModulesSettings,
  PaymentMethodsSettings,
  ProfileSettings,
  TaxesSettings,
} from '@/pages/settings/SettingsSections'
import { RoomsSettings, RoomTypesSettings } from '@/pages/settings/RoomSections'
import { useQueryClient, useMutation } from '@tanstack/react-query'

/** What each step shows: mostly the same screens as Settings. */
const CONTENT: Record<SetupStepKey, ComponentType> = {
  account: AccountStep,
  profile: ProfileSettings,
  branches: BranchesSettings,
  room_types: RoomTypesSettings,
  rooms: RoomsSettings,
  payments: PaymentsStep,
  taxes: TaxesSettings,
  booking_rules: BookingRulesSettings,
  cancellation: CancellationSettings,
  modules: ModulesSettings,
  team: TeamStep,
  review: () => null, // rendered by the page (needs the progress)
}

const WELCOME = -1
const ALL_SET = SETUP_STEPS.length

/** Full-screen setup walkthrough for a new Hotel Admin (screen-list.md section 12). */
export function SetupPage() {
  const q = useSetup()
  if (!q.data) {
    return <div className="flex h-full items-center justify-center text-muted">{q.isError ? <Alert>{(q.error as Error).message}</Alert> : null}</div>
  }
  return <Wizard setup={q.data.data} />
}

function Wizard({ setup }: { setup: Setup }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const me = useMe()
  const mark = useMarkStep()
  const finish = useFinishSetup()
  const stateOf = (k: SetupStepKey): StepState => setup.steps.find((s) => s.key === k)?.state ?? 'todo'
  const firstTodo = SETUP_STEPS.findIndex((k) => stateOf(k) === 'todo')
  const [index, setIndex] = useState(() => (!setup.started && !setup.completed ? WELCOME : firstTodo === -1 ? SETUP_STEPS.length - 1 : firstTodo))
  const passwordPending = me.data?.user.must_change_password ?? false
  const done = setup.steps.filter((s) => s.state !== 'todo').length
  const pct = Math.round((done / SETUP_STEPS.length) * 100)

  const key = SETUP_STEPS[index] as SetupStepKey | undefined
  const step = key ? setup.steps.find((s) => s.key === key) : undefined
  const error = (mark.error ?? finish.error) instanceof ApiError ? ((mark.error ?? finish.error) as ApiError) : null
  const go = (i: number) => {
    mark.reset()
    finish.reset()
    setIndex(i)
    window.scrollTo({ top: 0 })
  }
  const next = (state: 'done' | 'skipped') => {
    if (!key) return
    mark.mutate({ step: key, state }, { onSuccess: () => go(index + 1) })
  }

  if (index === ALL_SET) return <AllSet setup={setup} />

  return (
    <div className="flex min-h-full flex-col lg:flex-row">
      {/* Step list */}
      <aside className="border-b border-border bg-surface lg:sticky lg:top-0 lg:h-dvh lg:w-72 lg:shrink-0 lg:overflow-y-auto lg:border-r lg:border-b-0">
        <div className="flex items-center gap-2.5 px-5 py-4">
          <BrandMark className="size-7" />
          <span className="text-sm font-semibold">{t('setup.title')}</span>
        </div>
        <div className="flex flex-col gap-1.5 px-5 pb-4">
          <div className="flex justify-between text-xs text-muted">
            <span>{t('setup.progress', { done, total: SETUP_STEPS.length })}</span>
            <span>{pct}%</span>
          </div>
          <div className="h-1.5 overflow-hidden rounded-full bg-sunken" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100} aria-label={t('setup.title')}>
            <div className="h-full rounded-full bg-accent transition-[width] duration-200" style={{ width: `${pct}%` }} />
          </div>
        </div>
        <ol className="hidden flex-col gap-0.5 px-3 pb-4 lg:flex">
          {SETUP_STEPS.map((k, i) => {
            const s = stateOf(k)
            const locked = passwordPending && k !== 'account'
            return (
              <li key={k}>
                <button
                  type="button"
                  disabled={locked}
                  onClick={() => go(i)}
                  aria-current={i === index ? 'step' : undefined}
                  className={cn(
                    'flex min-h-touch w-full items-center gap-3 rounded-lg px-2 text-left text-[13px] transition duration-75',
                    i === index ? 'bg-sunken font-semibold text-text' : 'text-muted hover:bg-sunken hover:text-text',
                    locked && 'cursor-not-allowed opacity-50 hover:bg-transparent',
                  )}
                >
                  <StepDot state={s} n={i + 1} active={i === index} />
                  <span className="flex-1">{t(`setup.steps.${k}.title`)}</span>
                  {!setup.steps[i]?.required && <span className="text-[11px] text-muted">{t('setup.optional')}</span>}
                </button>
              </li>
            )
          })}
        </ol>
      </aside>

      {/* Step content */}
      <main className="flex min-w-0 flex-1 flex-col">
        <div className="flex items-center justify-between gap-3 border-b border-border px-5 py-3 lg:px-8">
          <span className="text-xs text-muted">
            {index === WELCOME ? t('setup.welcomeShort') : t('setup.stepOf', { n: index + 1, total: SETUP_STEPS.length })}
          </span>
          {!passwordPending && (
            <Button variant="ghost" onClick={() => navigate('/')}>
              {t('setup.saveExit')}
            </Button>
          )}
        </div>

        <div key={index} className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 px-5 py-6 animate-page-in motion-reduce:animate-none lg:px-8">
          {index === WELCOME ? (
            <Welcome setup={setup} onStart={() => go(0)} />
          ) : key === 'review' ? (
            <Review setup={setup} onEdit={go} />
          ) : (
            key && <StepBody k={key} />
          )}
          {error && <Alert>{error.field('step') ?? error.message}</Alert>}
        </div>

        {index !== WELCOME && key && (
          <div className="sticky bottom-0 flex flex-wrap items-center justify-between gap-2 border-t border-border bg-surface/95 px-5 py-3 backdrop-blur lg:px-8">
            <Button variant="ghost" disabled={index === 0} onClick={() => go(index - 1)}>
              {t('setup.back')}
            </Button>
            <div className="flex gap-2">
              {step && !step.required && (
                <Button variant="secondary" disabled={mark.isPending} onClick={() => next('skipped')}>
                  {t('setup.skip')}
                </Button>
              )}
              {key === 'review' ? (
                <Button size="lg" disabled={finish.isPending} onClick={() => finish.mutate(undefined, { onSuccess: () => go(ALL_SET) })}>
                  {t('setup.finish')}
                </Button>
              ) : (
                <Button size="lg" disabled={mark.isPending || (key === 'account' && passwordPending)} onClick={() => next('done')}>
                  {t('setup.continue')}
                </Button>
              )}
            </div>
          </div>
        )}
      </main>
    </div>
  )
}

function StepDot({ state, n, active }: { state: StepState; n: number; active: boolean }) {
  if (state === 'done') {
    return (
      <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-accent text-on-accent">
        <CheckIcon aria-hidden className="size-3.5" />
      </span>
    )
  }
  if (state === 'skipped') {
    return (
      <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-sunken text-muted ring-1 ring-ring">
        <ForwardIcon aria-hidden className="size-3" />
      </span>
    )
  }
  return (
    <span className={cn('flex size-6 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold ring-1', active ? 'text-accent-text ring-accent' : 'text-muted ring-ring')}>
      {n}
    </span>
  )
}

function StepBody({ k }: { k: SetupStepKey }) {
  const { t } = useTranslation()
  const Content = CONTENT[k]
  return (
    <>
      <p className="max-w-prose text-[13px] text-muted">{t(`setup.steps.${k}.help`)}</p>
      <Content />
    </>
  )
}

/* ------------------------------------------------------------- welcome */

function Welcome({ setup, onStart }: { setup: Setup; onStart: () => void }) {
  const { t } = useTranslation()
  const brand = useBrand()
  const parts = ['hotel', 'rooms', 'rules'] as const
  return (
    <div className="flex flex-col gap-6 py-4">
      <div className="flex flex-col gap-2">
        <h1 className="text-2xl font-bold">{t('setup.welcome', { hotel: setup.hotel.name })}</h1>
        <p className="max-w-prose text-muted">{t('setup.welcomeIntro', { app: brand.name })}</p>
      </div>
      {setup.hotel.plan && (
        <div className="flex flex-wrap gap-2">
          <Badge tone="accent">{t('setup.plan', { plan: setup.hotel.plan })}</Badge>
          {setup.hotel.trial_ends_at && <Badge tone="info">{t('setup.trialUntil', { date: date(setup.hotel.trial_ends_at) })}</Badge>}
          <Badge tone="grey">{setup.hotel.max_rooms ? t('setup.roomLimit', { count: setup.hotel.max_rooms }) : t('setup.noRoomLimit')}</Badge>
        </div>
      )}
      <div className="grid gap-4 sm:grid-cols-3">
        {parts.map((p, i) => (
          <Card key={p} className="flex flex-col gap-1.5">
            <span className="text-xs font-semibold text-accent-text">{i + 1}</span>
            <span className="text-sm font-semibold">{t(`setup.parts.${p}.title`)}</span>
            <span className="text-[13px] text-muted">{t(`setup.parts.${p}.text`)}</span>
          </Card>
        ))}
      </div>
      <p className="text-[13px] text-muted">{t('setup.duration')}</p>
      <div>
        <Button size="lg" onClick={onStart}>
          {t('setup.start')}
        </Button>
      </div>
    </div>
  )
}

/* ------------------------------------------------------------- account */

function AccountStep() {
  const { t } = useTranslation()
  const me = useMe()
  const qc = useQueryClient()
  const logout = useLogout()
  const change = useChangePassword()
  const [current, setCurrent] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [phone, setPhone] = useState(me.data?.user.phone ?? '')
  const savePhone = useMutation({
    mutationFn: () => put('/me', { name: me.data?.user.name, phone: phone || null }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ME_KEY }),
  })
  const error = change.error instanceof ApiError ? change.error : null
  const mismatch = confirm !== '' && confirm !== password

  if (me.data?.user.must_change_password) {
    const submit = (e: FormEvent) => {
      e.preventDefault()
      change.mutate({ current_password: current, password, password_confirmation: confirm }, { onSuccess: () => qc.invalidateQueries({ queryKey: ['setup'] }) })
    }
    return (
      <Card className="max-w-md">
        <form className="flex flex-col gap-4" onSubmit={submit} noValidate>
          <h2 className="text-base font-semibold">{t('password.title')}</h2>
          {change.error && !error && <Alert>{t('common.serverUnreachable')}</Alert>}
          <Field label={t('password.current')} type="password" autoComplete="current-password" hint={t('setup.account.currentHint')} value={current} onChange={(e) => setCurrent(e.target.value)} error={error?.field('current_password')} />
          <Field label={t('password.new')} type="password" autoComplete="new-password" hint={t('password.rules')} value={password} onChange={(e) => setPassword(e.target.value)} error={error?.field('password')} />
          <Strength password={password} />
          <Field label={t('password.confirm')} type="password" autoComplete="new-password" value={confirm} onChange={(e) => setConfirm(e.target.value)} error={mismatch ? t('password.mismatch') : undefined} />
          <div className="flex justify-between gap-2">
            <Button variant="ghost" onClick={() => logout.mutate()}>
              {t('topbar.signOut')}
            </Button>
            <Button type="submit" disabled={change.isPending || !current || !password || mismatch}>
              {t('password.submit')}
            </Button>
          </div>
        </form>
      </Card>
    )
  }

  return (
    <Card className="flex max-w-md flex-col gap-4">
      <Alert tone="ok">{t('setup.account.passwordDone')}</Alert>
      <form
        className="flex flex-col gap-4"
        noValidate
        onSubmit={(e) => {
          e.preventDefault()
          savePhone.mutate()
        }}
      >
        <Field label={t('setup.account.phone')} type="tel" hint={t('setup.account.phoneHint')} value={phone} onChange={(e) => setPhone(e.target.value)} />
        {savePhone.isSuccess && <Alert tone="ok">{t('common.saved')}</Alert>}
        <div className="flex justify-end">
          <Button type="submit" variant="secondary" disabled={savePhone.isPending}>
            {t('common.save')}
          </Button>
        </div>
      </form>
    </Card>
  )
}

/** Simple strength meter: length plus mix of character kinds. */
function Strength({ password }: { password: string }) {
  const { t } = useTranslation()
  if (!password) return null
  const kinds = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/].filter((r) => r.test(password)).length
  const score = Math.min(3, (password.length >= 8 ? 1 : 0) + (password.length >= 12 ? 1 : 0) + (kinds >= 3 ? 1 : 0))
  const label = ['weak', 'weak', 'good', 'strong'][score]
  return (
    <div className="flex items-center gap-2" aria-live="polite">
      <div className="flex flex-1 gap-1">
        {[1, 2, 3].map((i) => (
          <span key={i} className={cn('h-1 flex-1 rounded-full transition duration-150', i <= score ? (score === 3 ? 'bg-ok-text' : score === 2 ? 'bg-accent' : 'bg-danger') : 'bg-sunken')} />
        ))}
      </div>
      <span className="text-xs text-muted">{t(`setup.account.strength.${label}`)}</span>
    </div>
  )
}

/* ------------------------------------------------------------- payments */

function PaymentsStep() {
  return (
    <div className="flex flex-col gap-8">
      <CurrenciesSettings />
      <PaymentMethodsSettings />
    </div>
  )
}

/* ------------------------------------------------------------- team */

function TeamStep() {
  const { t } = useTranslation()
  const me = useMe()
  const roles = useRoles()
  const staff = useStaff()
  const save = useSaveStaff()
  const branches = me.data?.branches ?? []
  const choosable = (roles.data?.data ?? []).filter((r) => r.key !== 'hotel_admin')
  const [f, setF] = useState({ name: '', email: '', role_id: 0, branch_id: 0 })
  const [created, setCreated] = useState<NewStaffResult | null>(null)
  const error = save.error instanceof ApiError ? save.error : null
  const roleId = f.role_id || choosable[0]?.id || 0
  const branchId = f.branch_id || branches[0]?.id || 0
  const role = choosable.find((r) => r.id === roleId)
  const others = (staff.data?.data ?? []).filter((s) => s.id !== me.data?.user.id)

  function submit(e: FormEvent) {
    e.preventDefault()
    save.mutate(
      { name: f.name, email: f.email, phone: null, role_id: roleId, branch_ids: branchId ? [branchId] : [], send_email: true },
      {
        onSuccess: (res) => {
          setCreated(res as NewStaffResult)
          setF({ ...f, name: '', email: '' })
        },
      },
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <Card>
        <form className="grid gap-4 sm:grid-cols-2" onSubmit={submit} noValidate>
          <Field label={t('team.staff.name')} value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} error={error?.field('name')} />
          <Field label={t('team.staff.email')} type="email" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} error={error?.field('email')} />
          <Select label={t('team.staff.role')} value={String(roleId)} onChange={(e) => setF({ ...f, role_id: Number(e.target.value) })} error={error?.field('role_id')}>
            {choosable.map((r) => (
              <option key={r.id} value={r.id}>
                {r.name}
              </option>
            ))}
          </Select>
          <Select label={t('settings.rooms.branch')} value={String(branchId)} onChange={(e) => setF({ ...f, branch_id: Number(e.target.value) })}>
            {branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </Select>
          {role?.description && <p className="text-[13px] text-muted sm:col-span-2">{role.description}</p>}
          <div className="flex justify-end sm:col-span-2">
            <Button type="submit" disabled={save.isPending || !f.name || !f.email}>
              {t('setup.team.invite')}
            </Button>
          </div>
        </form>
      </Card>
      {created && <TempPassword password={created.temporary_password} emailSent={created.email_sent} email={created.data.email} />}
      {others.length > 0 && (
        <Card className="flex flex-col gap-2">
          <span className="text-[13px] font-medium">{t('setup.team.invited', { count: others.length })}</span>
          <ul className="flex flex-col divide-y divide-border text-[13px]">
            {others.map((s) => (
              <li key={s.id} className="flex justify-between gap-3 py-2">
                <span>
                  {s.name} <span className="text-muted">· {s.email}</span>
                </span>
                <span className="text-muted">{s.role?.name}</span>
              </li>
            ))}
          </ul>
        </Card>
      )}
    </div>
  )
}

/* ------------------------------------------------------------- review */

function Review({ setup, onEdit }: { setup: Setup; onEdit: (i: number) => void }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-col gap-4">
      <h2 className="text-base font-semibold">{t('setup.steps.review.title')}</h2>
      <p className="max-w-prose text-[13px] text-muted">{t('setup.steps.review.help')}</p>
      <div className="grid gap-3 sm:grid-cols-2">
        {setup.steps
          .filter((s) => s.key !== 'review')
          .map((s) => (
            <Card key={s.key} className="flex items-center justify-between gap-3 py-3">
              <div className="flex flex-col gap-1">
                <span className="text-[13px] font-medium">{t(`setup.steps.${s.key}.title`)}</span>
                {s.state === 'done' && <Badge tone="ok">{t('setup.state.done')}</Badge>}
                {s.state === 'skipped' && <Badge tone="grey">{t('setup.state.skipped')}</Badge>}
                {s.state === 'todo' && <Badge tone={s.required ? 'warn' : 'grey'}>{s.required ? t('setup.state.required') : t('setup.state.defaults')}</Badge>}
              </div>
              <Button variant="ghost" onClick={() => onEdit(SETUP_STEPS.indexOf(s.key))}>
                {t('common.edit')}
              </Button>
            </Card>
          ))}
      </div>
    </div>
  )
}

/* ------------------------------------------------------------- all set */

function AllSet({ setup }: { setup: Setup }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const done = setup.steps.filter((s) => s.state === 'done').length
  return (
    <div className="flex min-h-full items-center justify-center p-4">
      <Card className="flex w-full max-w-lg flex-col items-center gap-5 p-8 text-center animate-page-in motion-reduce:animate-none">
        <span className="flex size-12 items-center justify-center rounded-full bg-accent text-on-accent">
          <CheckIcon aria-hidden className="size-6" />
        </span>
        <div className="flex flex-col gap-1.5">
          <h1 className="text-xl font-bold">{t('setup.allSet.title')}</h1>
          <p className="text-[13px] text-muted">{t('setup.allSet.text', { hotel: setup.hotel.name })}</p>
        </div>
        <div className="flex flex-wrap justify-center gap-2">
          <Badge tone="ok">{t('setup.allSet.done', { count: done })}</Badge>
          {setup.skipped > 0 && <Badge tone="grey">{t('setup.allSet.skipped', { count: setup.skipped })}</Badge>}
        </div>
        <ul className="flex w-full flex-col gap-1.5 text-left text-[13px] text-muted">
          {(['booking', 'board', 'settings'] as const).map((k) => (
            <li key={k} className="flex gap-2">
              <CheckIcon aria-hidden className="mt-0.5 size-4 shrink-0 text-accent-text" />
              {t(`setup.allSet.next.${k}`)}
            </li>
          ))}
        </ul>
        <Button size="lg" onClick={() => navigate('/', { replace: true })}>
          {t('setup.allSet.go')}
        </Button>
      </Card>
    </div>
  )
}
