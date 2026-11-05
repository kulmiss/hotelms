import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router'
import { useTranslation } from 'react-i18next'
import { LockClosedIcon } from '@heroicons/react/24/outline'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { useLogout, useMe } from '@/lib/auth'
import { errorText, usePinSwitch, useUnlock } from '@/lib/security'

const KEY = 'hms_locked'

function stored(): string | null {
  try {
    return localStorage.getItem(KEY)
  } catch {
    return null
  }
}
function store(v: string | null) {
  try {
    if (v) localStorage.setItem(KEY, v)
    else localStorage.removeItem(KEY)
  } catch {
    /* private mode: the lock still works until the page reloads */
  }
}

/**
 * Shared reception devices (decisions.md W7): after some minutes without use the screen locks.
 * The same person unlocks with their PIN or password; another staff member takes over with their PIN.
 */
export function ScreenLock() {
  const me = useMe()
  const minutes = me.data?.security?.screen_lock_minutes ?? 0
  const userId = me.data ? String(me.data.user.id) : null
  const [locked, setLocked] = useState(() => userId !== null && stored() === userId)
  const last = useRef(Date.now())

  useEffect(() => {
    if (!minutes || !userId) return
    const touch = () => {
      last.current = Date.now()
    }
    const events = ['pointerdown', 'keydown', 'wheel', 'touchstart'] as const
    events.forEach((e) => window.addEventListener(e, touch, { passive: true }))
    const timer = window.setInterval(() => {
      if (Date.now() - last.current >= minutes * 60_000) {
        store(userId)
        setLocked(true)
      }
    }, 15_000)
    return () => {
      events.forEach((e) => window.removeEventListener(e, touch))
      window.clearInterval(timer)
    }
  }, [minutes, userId])

  if (!locked || !me.data) return null
  return (
    <LockScreen
      name={me.data.user.name}
      canUsePin={!me.data.role?.is_hotel_admin}
      onUnlocked={() => {
        store(null)
        last.current = Date.now()
        setLocked(false)
      }}
    />
  )
}

function LockScreen({ name, canUsePin, onUnlocked }: { name: string; canUsePin: boolean; onUnlocked: () => void }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const pin = usePinSwitch()
  const unlock = useUnlock()
  const logout = useLogout()
  const [usePassword, setUsePassword] = useState(!canUsePin)
  const [value, setValue] = useState('')
  const busy = pin.isPending || unlock.isPending

  function submit(e: FormEvent) {
    e.preventDefault()
    if (usePassword) {
      unlock.mutate(value, { onSuccess: onUnlocked })
    } else {
      pin.mutate(value, {
        onSuccess: (r) => {
          onUnlocked()
          if ((r as { message?: string } | undefined)?.message !== 'Unlocked.') navigate('/', { replace: true }) // someone else took over
        },
      })
    }
    setValue('')
  }

  return (
    <div role="dialog" aria-modal="true" aria-labelledby="lock-title" className="fixed inset-0 z-50 flex items-center justify-center bg-bg/95 p-4 backdrop-blur-xl">
      <form onSubmit={submit} noValidate className="panel flex w-full max-w-sm flex-col gap-5 p-7">
        <div className="flex flex-col items-center gap-2 text-center">
          <LockClosedIcon aria-hidden className="size-8 text-icon" />
          <h1 id="lock-title" className="text-lg font-semibold">
            {t('lock.title')}
          </h1>
          <p className="text-sm text-muted">{usePassword ? t('lock.passwordHelp', { name }) : t('lock.pinHelp', { name })}</p>
        </div>
        {(pin.error || unlock.error) && <Alert>{errorText(pin.error ?? unlock.error)}</Alert>}
        {usePassword ? (
          <Field label={t('login.password')} type="password" autoComplete="current-password" autoFocus value={value} onChange={(e) => setValue(e.target.value)} />
        ) : (
          <Field label={t('lock.pin')} type="password" inputMode="numeric" autoComplete="off" autoFocus maxLength={6} value={value} onChange={(e) => setValue(e.target.value.replace(/\D/g, ''))} />
        )}
        <Button type="submit" size="lg" disabled={busy || (usePassword ? !value : value.length < 4)}>
          {t('lock.continue')}
        </Button>
        <div className="flex flex-wrap justify-between gap-2">
          {canUsePin && (
            <Button variant="ghost" onClick={() => { setUsePassword(!usePassword); setValue('') }}>
              {usePassword ? t('lock.usePin') : t('lock.usePassword')}
            </Button>
          )}
          <Button
            variant="ghost"
            onClick={() =>
              logout.mutate(undefined, {
                onSettled: () => {
                  store(null)
                  navigate('/login', { replace: true })
                },
              })
            }
          >
            {t('topbar.signOut')}
          </Button>
        </div>
      </form>
    </div>
  )
}
