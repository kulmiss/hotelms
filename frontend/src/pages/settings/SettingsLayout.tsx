import { NavLink, Navigate, Outlet, useLocation } from 'react-router'
import { useTranslation } from 'react-i18next'
import { PageHeader } from '@/components/ui/PageHeader'
import { useMe } from '@/lib/auth'
import { cn } from '@/lib/cn'
import { useCan } from '@/lib/hotel'

/** Settings sections, each guarded by its permission (permissions.md §10). */
export const SETTINGS_SECTIONS = [
  { key: 'profile', permission: 'settings.hotel' },
  { key: 'branches', permission: 'settings.branches' },
  { key: 'room-types', permission: 'settings.rooms' },
  { key: 'rooms', permission: 'settings.rooms' },
  { key: 'import', permission: 'settings.rooms' },
  { key: 'rate-rules', permission: 'settings.pricing' },
  { key: 'guest-types', permission: 'settings.pricing' },
  { key: 'currencies', permission: 'settings.exchange_rates' },
  { key: 'taxes', permission: 'settings.money' },
  { key: 'payment-methods', permission: 'settings.money' },
  { key: 'charges', permission: 'settings.charges' },
  { key: 'booking-rules', permission: 'settings.policies' },
  { key: 'cancellation', permission: 'settings.policies' },
  { key: 'modules', permission: 'settings.modules' },
  { key: 'attendance', permission: 'settings.attendance', module: 'attendance' },
  { key: 'security', permission: 'settings.hotel' },
  { key: 'subscription', permission: 'subscription.view' },
] as const

export function SettingsLayout() {
  const { t } = useTranslation()
  const can = useCan()
  const me = useMe()
  const { pathname } = useLocation()
  const modules = me.data?.hotel?.modules ?? []
  const sections = SETTINGS_SECTIONS.filter((s) => can(s.permission) && (!('module' in s) || modules.includes(s.module)))

  // Open the first allowed section; never show a section the role may not use.
  const current = pathname.replace(/\/$/, '').split('/')[2]
  if (!current || !sections.some((s) => s.key === current)) {
    return sections[0] ? <Navigate to={`/settings/${sections[0].key}`} replace /> : <Navigate to="/" replace />
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader title={t('nav.settings')} subtitle={t('settings.subtitle')} />
      <div className="flex flex-col gap-6 lg:flex-row">
        <nav aria-label={t('nav.settings')} className="lg:w-56 lg:shrink-0">
          <ul className="no-scrollbar flex gap-1 overflow-x-auto lg:flex-col">
            {sections.map((s) => (
              <li key={s.key}>
                <NavLink
                  to={`/settings/${s.key}`}
                  className={({ isActive }) =>
                    cn(
                      'flex min-h-touch items-center rounded-lg px-3 text-[13px] font-medium whitespace-nowrap transition duration-75',
                      isActive ? 'bg-surface text-accent-text shadow-sm ring-1 ring-ring' : 'text-muted hover:bg-sunken hover:text-text',
                    )
                  }
                >
                  {t(`settings.sections.${s.key}`)}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>
        <div className="min-w-0 flex-1">
          <Outlet />
        </div>
      </div>
    </div>
  )
}
