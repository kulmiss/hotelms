import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { SmartHints } from '@/components/ui/SmartHints'
import { phoneLooksWrong, useSmart } from '@/lib/smart'
import { useDebounced } from '@/lib/useDebounced'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Select } from '@/components/ui/Select'
import { ApiError } from '@/lib/api'
import { useGuestTypes, useSaveGuest, type Guest, type GuestInput } from '@/lib/front'

export const ID_TYPES = ['national_id', 'passport', 'kebele_id', 'driving_licence'] as const

const EMPTY: GuestInput = { first_name: '', last_name: '', phone: '', email: '', nationality: 'Ethiopian', id_type: 'national_id', id_number: '', gender: null, date_of_birth: null, guest_type_id: null, notes: '' }

/** Add / edit a guest. "compact" shows only what reception needs to book quickly. */
export function GuestForm({ guest, compact = false, onSaved, onCancel }: { guest?: Guest; compact?: boolean; onSaved: (g: Guest) => void; onCancel?: () => void }) {
  const { t } = useTranslation()
  const save = useSaveGuest(guest?.id)
  const types = useGuestTypes()
  const [f, setF] = useState<GuestInput>(() => (guest ? { ...EMPTY, ...guest } : EMPTY))
  const [duplicate, setDuplicate] = useState<{ id: number; name: string } | null>(null)
  const error = save.error instanceof ApiError ? save.error : null
  const set = (k: keyof GuestInput) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value === '' ? null : e.target.value })
  const val = (k: keyof GuestInput) => String(f[k] ?? '')
  // ✨ While typing: "this guest may already exist" (same phone, ID number or name).
  const check = useDebounced({ phone: f.phone ?? '', id_number: f.id_number ?? '', first_name: f.first_name ?? '', last_name: f.last_name ?? '', exclude_id: guest?.id }, 500)
  const filled = (check.phone.replace(/\D/g, '').length >= 9 || check.id_number.length >= 4 || (check.first_name.length >= 2 && check.last_name.length >= 2))
  const smart = useSmart('guest_check', check, filled && !duplicate)
  const phoneWrong = phoneLooksWrong(f.phone ?? '')

  function submit(e: FormEvent) {
    e.preventDefault()
    e.stopPropagation()
    save.mutate(
      { ...f, guest_type_id: f.guest_type_id ? Number(f.guest_type_id) : null },
      {
        onSuccess: (res) => {
          const dup = (res as { possible_duplicate?: { id: number; name: string } | null }).possible_duplicate
          if (dup) setDuplicate(dup)
          onSaved(res.data)
        },
      },
    )
  }

  return (
    <form onSubmit={submit} noValidate className="grid gap-4 sm:grid-cols-2">
      {save.error && !error && <Alert>{t('common.serverUnreachable')}</Alert>}
      {duplicate && <Alert tone="info">{t('guests.duplicate', { name: duplicate.name })}</Alert>}
      {filled && <SmartHints hints={smart.data?.data} className="sm:col-span-2" />}
      <Field label={t('guests.firstName')} value={val('first_name')} onChange={set('first_name')} error={error?.field('first_name')} autoFocus={compact} />
      <Field label={t('guests.lastName')} value={val('last_name')} onChange={set('last_name')} error={error?.field('last_name')} />
      <Field label={t('guests.phone')} type="tel" inputMode="tel" value={val('phone')} onChange={set('phone')} error={error?.field('phone')} hint={phoneWrong ? t('smart.phoneCheck') : undefined} />
      {!compact && <Field label={t('guests.email')} type="email" value={val('email')} onChange={set('email')} error={error?.field('email')} />}
      <Select label={t('guests.idType')} value={val('id_type')} onChange={set('id_type')} error={error?.field('id_type')}>
        <option value="">—</option>
        {ID_TYPES.map((x) => (
          <option key={x} value={x}>
            {t(`guests.idTypes.${x}`)}
          </option>
        ))}
      </Select>
      <Field label={t('guests.idNumber')} value={val('id_number')} onChange={set('id_number')} error={error?.field('id_number')} />
      {!compact && (
        <>
          <Field label={t('guests.nationality')} value={val('nationality')} onChange={set('nationality')} error={error?.field('nationality')} />
          <Select label={t('guests.gender')} value={val('gender')} onChange={set('gender')}>
            <option value="">—</option>
            <option value="female">{t('guests.genders.female')}</option>
            <option value="male">{t('guests.genders.male')}</option>
          </Select>
          <Field label={t('guests.dateOfBirth')} type="date" value={val('date_of_birth').slice(0, 10)} onChange={set('date_of_birth')} error={error?.field('date_of_birth')} />
        </>
      )}
      {(types.data?.data.length ?? 0) > 0 && (
        <Select label={t('guests.guestType')} hint={t('guests.guestTypeHint')} value={val('guest_type_id')} onChange={set('guest_type_id')}>
          <option value="">—</option>
          {types.data?.data.map((g) => (
            <option key={g.id} value={g.id}>
              {g.name}
            </option>
          ))}
        </Select>
      )}
      {!compact && (
        <div className="sm:col-span-2">
          <Field label={t('guests.notes')} value={val('notes')} onChange={set('notes')} error={error?.field('notes')} />
        </div>
      )}
      {!compact && (
        <>
          <Select label={t('messages.guestLanguage')} value={val('language')} onChange={set('language')}>
            <option value="">{t('messages.hotelLanguage')}</option>
            <option value="en">English</option>
            <option value="am">አማርኛ</option>
          </Select>
          <label className="flex min-h-touch items-start gap-2 self-end text-sm">
            <input type="checkbox" className="mt-0.5 size-4" checked={!!f.messages_opt_out} onChange={(e) => setF({ ...f, messages_opt_out: e.target.checked })} />
            <span className="flex flex-col">
              {t('messages.optOut')}
              <span className="text-xs text-muted">{t('messages.optOutHint')}</span>
            </span>
          </label>
        </>
      )}
      <div className="flex justify-end gap-2 sm:col-span-2">
        {onCancel && (
          <Button variant="ghost" onClick={onCancel}>
            {t('common.cancel')}
          </Button>
        )}
        <Button type="submit" disabled={save.isPending || !f.first_name}>
          {guest ? t('common.save') : t('guests.add')}
        </Button>
      </div>
    </form>
  )
}
