import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { Select } from '@/components/ui/Select'
import { Tabs } from '@/components/ui/Tabs'
import { api, ApiError, post, put } from '@/lib/api'

type Template = { subject: string; body: string; custom: boolean }
type Msg = { key: string; consent: 'unless_opt_out' | 'ticked'; en: Template; am: Template }
type Info = { wifi_name: string | null; wifi_password: string | null; breakfast: string | null; whatsapp: string | null; menu_url: string | null }
type Sms = { provider: 'none' | 'afromessage' | 'http'; sender: string | null; identifier: string | null; url: string | null; method: 'GET' | 'POST'; body: string | null; has_token: boolean }
type Data = { messages: Msg[]; placeholders: string[]; auto: Record<string, { email: boolean; sms: boolean }>; info: Info; sms: Sms; auto_sms: boolean }

const KEY = ['settings', '/settings/guest-messages']

/** Settings → Guest messages (owner, 14 Nov 2026). */
export function GuestMessagesSettings() {
  const { t } = useTranslation()
  const q = useQuery({ queryKey: KEY, queryFn: () => api<Data>('/settings/guest-messages') })
  const d = q.data
  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h2 className="text-lg font-semibold">{t('settings.sections.guest-messages')}</h2>
        <p className="max-w-3xl text-sm text-muted">{t('messages.settings.help')}</p>
      </div>
      {d && (
        <>
          <InfoForm info={d.info} />
          <AutoTable data={d} />
          <Templates data={d} />
          <SmsAccount sms={d.sms} autoSms={d.auto_sms} />
          <p className="max-w-3xl text-xs text-muted">{t('messages.settings.whatsappApi')}</p>
        </>
      )}
    </div>
  )
}

function InfoForm({ info }: { info: Info }) {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const [f, setF] = useState(info)
  const save = useMutation({ mutationFn: () => put('/settings/guest-messages/info', f), onSuccess: () => void qc.invalidateQueries({ queryKey: KEY }) })
  const err = save.error instanceof ApiError ? save.error : null
  const field = (k: keyof Info, label: string, extra: Record<string, unknown> = {}) => (
    <Field label={label} value={f[k] ?? ''} onChange={(e) => setF({ ...f, [k]: e.target.value || null })} error={err?.field(k)} {...extra} />
  )
  return (
    <Card className="flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <h3 className="text-sm font-semibold">{t('messages.settings.info')}</h3>
        <p className="text-sm text-muted">{t('messages.settings.infoHelp')}</p>
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        {field('wifi_name', t('messages.settings.wifiName'))}
        {field('wifi_password', t('messages.settings.wifiPassword'), { autoComplete: 'off' })}
        {field('breakfast', t('messages.settings.breakfast'), { placeholder: '07:00–10:00' })}
        {field('whatsapp', t('messages.settings.whatsapp'), { inputMode: 'tel', placeholder: '+251 9…' })}
        <div className="sm:col-span-2">{field('menu_url', t('messages.settings.menuUrl'), { inputMode: 'url', placeholder: 'https://…', hint: t('messages.settings.menuUrlHint') })}</div>
      </div>
      <div className="flex items-center gap-3">
        <Button disabled={save.isPending} onClick={() => save.mutate()}>
          {t('common.save')}
        </Button>
        {save.isSuccess && <span className="text-sm text-ok-text">{t('common.saved')}</span>}
      </div>
    </Card>
  )
}

function AutoTable({ data }: { data: Data }) {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const [auto, setAuto] = useState(data.auto)
  const save = useMutation({ mutationFn: () => put('/settings/guest-messages/auto', auto), onSuccess: () => void qc.invalidateQueries({ queryKey: KEY }) })
  const smsReady = data.sms.provider !== 'none'
  return (
    <Card className="flex flex-col gap-3">
      <div className="flex flex-col gap-1">
        <h3 className="text-sm font-semibold">{t('messages.settings.auto')}</h3>
        <p className="text-sm text-muted">{data.auto_sms ? t('messages.settings.autoHelp') : t('messages.settings.autoSmsPlan')}</p>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[480px] text-left text-sm">
          <thead className="text-xs text-muted">
            <tr>
              <th className="py-2 font-medium">{t('messages.which')}</th>
              <th className="py-2 font-medium">{t('messages.channels.email')}</th>
              <th className="py-2 font-medium">{t('messages.channels.sms')}</th>
            </tr>
          </thead>
          <tbody>
            {data.messages.map((m) => (
              <tr key={m.key} className="border-t border-border">
                <td className="py-2">
                  {t(`messages.keys.${m.key}`)}
                  {m.consent === 'ticked' && <span className="block text-xs text-muted">{t('messages.onlyWithYes')}</span>}
                </td>
                <td className="py-2">
                  <input type="checkbox" className="size-5" aria-label={`${t(`messages.keys.${m.key}`)} – ${t('messages.channels.email')}`} checked={auto[m.key]?.email ?? false} onChange={(e) => setAuto({ ...auto, [m.key]: { ...auto[m.key], email: e.target.checked } })} />
                </td>
                <td className="py-2">
                  <input type="checkbox" className="size-5" aria-label={`${t(`messages.keys.${m.key}`)} – ${t('messages.channels.sms')}`} disabled={!data.auto_sms || !smsReady} checked={auto[m.key]?.sms ?? false} onChange={(e) => setAuto({ ...auto, [m.key]: { ...auto[m.key], sms: e.target.checked } })} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="flex items-center gap-3">
        <Button disabled={save.isPending} onClick={() => save.mutate()}>
          {t('common.save')}
        </Button>
        {save.isSuccess && <span className="text-sm text-ok-text">{t('common.saved')}</span>}
      </div>
    </Card>
  )
}

function Templates({ data }: { data: Data }) {
  const { t } = useTranslation()
  const [key, setKey] = useState(data.messages[0]?.key ?? 'confirmation')
  const [lang, setLang] = useState<'en' | 'am'>('en')
  const msg = data.messages.find((m) => m.key === key)
  return (
    <Card className="flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <h3 className="text-sm font-semibold">{t('messages.settings.texts')}</h3>
        <p className="text-sm text-muted">{t('messages.settings.textsHelp')}</p>
      </div>
      <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
        <Select label={t('messages.which')} value={key} onChange={(e) => setKey(e.target.value)}>
          {data.messages.map((m) => (
            <option key={m.key} value={m.key}>
              {t(`messages.keys.${m.key}`)}
            </option>
          ))}
        </Select>
        <Tabs label={t('messages.language')} active={lang} onChange={(k) => setLang(k as 'en' | 'am')} items={[{ key: 'en', label: 'English' }, { key: 'am', label: 'አማርኛ' }]} />
      </div>
      {msg && <TemplateForm key={`${key}-${lang}`} msgKey={key} lang={lang} tpl={msg[lang]} placeholders={data.placeholders} />}
    </Card>
  )
}

function TemplateForm({ msgKey, lang, tpl, placeholders }: { msgKey: string; lang: 'en' | 'am'; tpl: Template; placeholders: string[] }) {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const [subject, setSubject] = useState(tpl.subject)
  const [body, setBody] = useState(tpl.body)
  useEffect(() => {
    setSubject(tpl.subject)
    setBody(tpl.body)
  }, [tpl.subject, tpl.body])
  const save = useMutation({ mutationFn: () => put(`/settings/guest-messages/templates/${msgKey}/${lang}`, { subject, body }), onSuccess: () => void qc.invalidateQueries({ queryKey: KEY }) })
  const reset = useMutation({ mutationFn: () => api(`/settings/guest-messages/templates/${msgKey}/${lang}`, { method: 'DELETE' }), onSuccess: () => void qc.invalidateQueries({ queryKey: KEY }) })
  const err = save.error instanceof ApiError ? save.error : null
  return (
    <div className="flex flex-col gap-3" lang={lang}>
      {tpl.custom ? <Badge tone="accent">{t('messages.settings.ownText')}</Badge> : <Badge tone="grey">{t('messages.settings.defaultText')}</Badge>}
      <Field label={t('messages.settings.subject')} value={subject} onChange={(e) => setSubject(e.target.value)} error={err?.field('subject')} />
      <label className="flex flex-col gap-1.5 text-sm font-medium">
        {t('messages.settings.body')}
        <textarea className="min-h-40 rounded-lg border border-ring-strong bg-surface p-3 font-normal" value={body} onChange={(e) => setBody(e.target.value)} />
        <span className="text-xs font-normal text-muted">{t('messages.settings.length', { count: body.length })}</span>
      </label>
      <p className="text-xs text-muted">
        {t('messages.settings.placeholders')} {placeholders.map((p) => `{${p}}`).join(' ')}
      </p>
      <div className="flex flex-wrap items-center gap-2">
        <Button disabled={save.isPending || !subject || !body} onClick={() => save.mutate()}>
          {t('common.save')}
        </Button>
        {tpl.custom && (
          <Button variant="ghost" disabled={reset.isPending} onClick={() => reset.mutate()}>
            {t('messages.settings.reset')}
          </Button>
        )}
        {save.isSuccess && <span className="text-sm text-ok-text">{t('common.saved')}</span>}
      </div>
    </div>
  )
}

function SmsAccount({ sms, autoSms }: { sms: Sms; autoSms: boolean }) {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const [f, setF] = useState({ ...sms, token: '' })
  const [phone, setPhone] = useState('')
  const save = useMutation({ mutationFn: () => put('/settings/guest-messages/sms', { ...f, token: f.token || null }), onSuccess: () => {
    setF((x) => ({ ...x, token: '' }))
    void qc.invalidateQueries({ queryKey: KEY })
  } })
  const test = useMutation({ mutationFn: () => post('/settings/guest-messages/sms/test', { phone }) })
  const err = save.error instanceof ApiError ? save.error : null
  const testErr = test.error instanceof ApiError ? (Object.values(test.error.errors)[0]?.[0] ?? test.error.message) : null
  const set = (k: string) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value || null })
  return (
    <Card className="flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <h3 className="text-sm font-semibold">{t('messages.settings.sms')}</h3>
        <p className="text-sm text-muted">{t('messages.settings.smsHelp')}</p>
        {!autoSms && <p className="text-sm text-muted">{t('messages.settings.autoSmsPlan')}</p>}
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <Select label={t('messages.settings.provider')} value={f.provider} onChange={(e) => setF({ ...f, provider: e.target.value as Sms['provider'] })}>
          <option value="none">{t('messages.settings.providers.none')}</option>
          <option value="afromessage">AfroMessage</option>
          <option value="http">{t('messages.settings.providers.http')}</option>
        </Select>
        {f.provider !== 'none' && (
          <Field label={t('messages.settings.token')} type="password" autoComplete="new-password" value={f.token} placeholder={sms.has_token ? t('messages.settings.tokenSaved') : ''} onChange={(e) => setF({ ...f, token: e.target.value })} error={err?.field('token')} hint={t('messages.settings.tokenHint')} />
        )}
        {f.provider === 'afromessage' && (
          <>
            <Field label={t('messages.settings.sender')} value={f.sender ?? ''} onChange={set('sender')} error={err?.field('sender')} />
            <Field label={t('messages.settings.identifier')} value={f.identifier ?? ''} onChange={set('identifier')} error={err?.field('identifier')} />
          </>
        )}
        {f.provider === 'http' && (
          <>
            <div className="sm:col-span-2">
              <Field label={t('messages.settings.url')} value={f.url ?? ''} placeholder="https://sms.example.com/send?to={to}&text={message}" onChange={set('url')} error={err?.field('url')} hint={t('messages.settings.urlHint')} />
            </div>
            <Select label={t('messages.settings.method')} value={f.method} onChange={(e) => setF({ ...f, method: e.target.value as 'GET' | 'POST' })}>
              <option value="POST">POST</option>
              <option value="GET">GET</option>
            </Select>
            {f.method === 'POST' && <Field label={t('messages.settings.bodyJson')} value={f.body ?? ''} placeholder='{"to":"{to}","message":"{message}"}' onChange={set('body')} error={err?.field('body')} />}
          </>
        )}
      </div>
      <div className="flex items-center gap-3">
        <Button disabled={save.isPending} onClick={() => save.mutate()}>
          {t('common.save')}
        </Button>
        {save.isSuccess && <span className="text-sm text-ok-text">{t('common.saved')}</span>}
      </div>
      {sms.provider !== 'none' && (
        <div className="flex flex-wrap items-end gap-3 border-t border-border pt-4">
          <div className="w-56">
            <Field label={t('messages.settings.testPhone')} inputMode="tel" value={phone} onChange={(e) => setPhone(e.target.value)} />
          </div>
          <Button variant="secondary" disabled={!phone || test.isPending} onClick={() => test.mutate()}>
            {t('messages.settings.test')}
          </Button>
          {test.isSuccess && <span className="text-sm text-ok-text">{t('messages.settings.testSent')}</span>}
          {testErr && <Alert>{testErr}</Alert>}
        </div>
      )}
    </Card>
  )
}
