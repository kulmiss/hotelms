import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { useMutation, useQuery } from '@tanstack/react-query'
import { SILENT } from '@/lib/toast'
import { Alert } from '@/components/ui/Alert'
import { Button, buttonClass } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { ApiError, api, post, prepareSession } from '@/lib/api'
import { contactHref, useBrand } from '@/lib/brand'
import { Frame } from './PasswordResetPages'

type SignupForm = { mode: 'off' | 'approval' | 'open'; form_token: string; trial: boolean }

const EMPTY = { hotel_name: '', owner_name: '', email: '', phone: '', city: '', rooms: '', password: '', password_confirmation: '' }

/**
 * "Register your hotel" (owner, 14 Nov 2026). Free plan; the email is confirmed before the first sign-in.
 * Bot protection without a captcha: a hidden field, the form time and a limit per address (all checked by the server).
 */
export function RegisterPage() {
  const { t } = useTranslation()
  const brand = useBrand()
  const form = useQuery({ queryKey: ['signup-form'], queryFn: () => api<{ data: SignupForm }>('/signup').then((r) => r.data), staleTime: 0, gcTime: 0 })
  const [f, setF] = useState(EMPTY)
  const [accept, setAccept] = useState(false)
  const [website, setWebsite] = useState('')
  const set = (k: keyof typeof EMPTY) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))
  const send = useMutation({
    meta: SILENT,
    mutationFn: async () => {
      await prepareSession()
      return post<{ message: string; mode: string }>('/signup', { ...f, rooms: Number(f.rooms) || 0, accept_terms: accept, website, form_token: form.data?.form_token ?? '' })
    },
  })
  const error = send.error instanceof ApiError ? send.error : null
  const mismatch = f.password_confirmation !== '' && f.password !== f.password_confirmation

  function submit(e: FormEvent) {
    e.preventDefault()
    send.mutate()
  }

  if (form.data?.mode === 'off') {
    const href = contactHref(brand.raw)
    return (
      <Frame title={t('signup.closedTitle')} intro={t('signup.closedIntro')}>
        {href && (
          <a className={buttonClass('primary', 'lg')} href={href} target="_blank" rel="noopener noreferrer">
            {t('signup.contact')}
          </a>
        )}
        <Link to="/login" className="text-center text-sm font-medium text-muted hover:opacity-75">{t('signup.haveAccount')}</Link>
      </Frame>
    )
  }

  if (send.isSuccess) {
    return (
      <Frame title={t('signup.sentTitle')} intro={send.data.message}>
        <Alert tone="info">{send.data.mode === 'approval' ? t('signup.sentApproval') : t('signup.sentOpen')}</Alert>
        <ResendLink email={f.email} />
        <Link to="/login" className="text-center text-sm font-medium text-muted hover:opacity-75">{t('signup.haveAccount')}</Link>
      </Frame>
    )
  }

  return (
    <Frame title={t('signup.title')} intro={form.data?.mode === 'approval' ? t('signup.introApproval') : t('signup.intro')}>
      <form className="flex flex-col gap-4" onSubmit={submit} noValidate>
        {error?.field('form') && <Alert>{error.field('form')}</Alert>}
        {error?.status === 429 && <Alert>{t('signup.tooMany')}</Alert>}
        {send.error && !error && <Alert>{t('common.serverUnreachable')}</Alert>}
        <Field label={t('signup.hotelName')} autoComplete="organization" value={f.hotel_name} onChange={set('hotel_name')} error={error?.field('hotel_name')} required />
        <Field label={t('signup.ownerName')} autoComplete="name" value={f.owner_name} onChange={set('owner_name')} error={error?.field('owner_name')} required />
        <Field label={t('login.email')} type="email" autoComplete="email" inputMode="email" value={f.email} onChange={set('email')} error={error?.field('email')} required />
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('signup.phone')} type="tel" autoComplete="tel" inputMode="tel" placeholder="+251 9…" value={f.phone} onChange={set('phone')} error={error?.field('phone')} required />
          <Field label={t('signup.city')} autoComplete="address-level2" value={f.city} onChange={set('city')} error={error?.field('city')} required />
        </div>
        <Field label={t('signup.rooms')} type="number" inputMode="numeric" min={1} value={f.rooms} onChange={set('rooms')} error={error?.field('rooms')} required />
        <Field label={t('login.password')} type="password" autoComplete="new-password" value={f.password} hint={t('password.rules')} onChange={set('password')} error={error?.field('password')} required />
        <Field label={t('password.confirm')} type="password" autoComplete="new-password" value={f.password_confirmation} onChange={set('password_confirmation')} error={mismatch ? t('password.mismatch') : undefined} required />
        {/* Hidden from people (and from screen readers); bots fill it. */}
        <div aria-hidden="true" className="absolute -left-[9999px] size-px overflow-hidden">
          <label>
            Website
            <input tabIndex={-1} autoComplete="off" name="website" value={website} onChange={(e) => setWebsite(e.target.value)} />
          </label>
        </div>
        <label className="flex min-h-touch items-start gap-3 text-sm">
          <input type="checkbox" className="mt-0.5 size-5 rounded" checked={accept} onChange={(e) => setAccept(e.target.checked)} />
          <span>
            {t('signup.acceptBefore')}{' '}
            <a className="font-medium underline" href="/terms" target="_blank" rel="noopener">{t('signup.terms')}</a>{' '}
            {t('signup.and')}{' '}
            <a className="font-medium underline" href="/privacy" target="_blank" rel="noopener">{t('signup.privacy')}</a>
          </span>
        </label>
        {error?.field('accept_terms') && <Alert>{error.field('accept_terms')}</Alert>}
        <Button type="submit" size="lg" disabled={send.isPending || !form.data || mismatch}>
          {send.isPending ? t('signup.sending') : t('signup.submit')}
        </Button>
        <Link to="/login" className="text-center text-sm font-medium text-muted hover:opacity-75">{t('signup.haveAccount')}</Link>
      </form>
    </Frame>
  )
}

/** "Send the link again" (quiet answer, limited by the server). */
export function ResendLink({ email }: { email: string }) {
  const { t } = useTranslation()
  const resend = useMutation({
    meta: SILENT,
    mutationFn: async () => {
      await prepareSession()
      return post<{ message: string }>('/signup/resend', { email })
    },
  })
  if (resend.isSuccess) return <Alert tone="ok">{resend.data.message}</Alert>
  return (
    <Button variant="secondary" disabled={resend.isPending || !email} onClick={() => resend.mutate()}>
      {t('signup.resend')}
    </Button>
  )
}

/** The link in the email: confirms it on open (one tap if the browser blocked the automatic try). */
export function VerifyEmailPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const verify = useMutation({
    meta: SILENT,
    mutationFn: async () => {
      await prepareSession()
      return post<{ message: string; state: 'approved' | 'pending' }>('/signup/verify', { token: params.get('token') ?? '' })
    },
  })
  const { mutate } = verify
  const sent = useRef(false) // once, also in React's double-run dev mode (a link works only once)
  useEffect(() => {
    if (!sent.current) {
      sent.current = true
      mutate()
    }
  }, [mutate])
  const error = verify.error instanceof ApiError ? verify.error : null
  return (
    <Frame title={t('signup.verifyTitle')} intro={t('signup.verifyIntro')}>
      {verify.isSuccess ? (
        <div className="flex flex-col gap-4">
          <Alert tone="ok">{verify.data.message}</Alert>
          {verify.data.state === 'approved' && (
            <Button size="lg" onClick={() => navigate('/login', { replace: true })}>
              {t('login.submit')}
            </Button>
          )}
        </div>
      ) : verify.isPending ? (
        <p className="text-sm text-muted">{t('common.loading')}</p>
      ) : (
        <div className="flex flex-col gap-4">
          {error && <Alert>{error.field('token') ?? error.message}</Alert>}
          {verify.error && !error && <Alert>{t('common.serverUnreachable')}</Alert>}
          <Link to="/login" className="text-center text-sm font-medium text-muted hover:opacity-75">{t('login.submit')}</Link>
        </div>
      )}
    </Frame>
  )
}
