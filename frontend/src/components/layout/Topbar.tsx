import { useTranslation } from 'react-i18next'
import { Bars3Icon, MagnifyingGlassIcon } from '@heroicons/react/24/outline'
import { useMe } from '@/lib/auth'
import { useCurrentBranch } from '@/lib/branch'
import { AlertBell } from './AlertBell'
import { UserMenu } from './UserMenu'

export function Topbar({ onOpenMenu }: { onOpenMenu: () => void }) {
  const { t } = useTranslation()
  const me = useMe()
  const { branches, current, select } = useCurrentBranch(me.data)
  const iconButton =
    'flex size-touch shrink-0 items-center justify-center rounded-lg text-icon outline-none transition duration-75 hover:bg-sunken hover:text-muted focus-visible:bg-sunken'

  return (
    <header className="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-x-3 bg-surface/80 px-4 ring-1 ring-ring backdrop-blur-xl md:px-6 lg:px-8">
      <button type="button" onClick={onOpenMenu} aria-label={t('topbar.openMenu')} className={`${iconButton} -ml-2 lg:hidden`}>
        <Bars3Icon aria-hidden className="size-5" />
      </button>

      <label className="flex min-h-touch w-full max-w-xs items-center gap-x-2 rounded-lg bg-sunken px-3 ring-1 ring-transparent transition duration-75 focus-within:ring-2 focus-within:ring-accent">
        <MagnifyingGlassIcon aria-hidden className="size-4 shrink-0 text-icon" />
        <span className="sr-only">{t('topbar.search')}</span>
        <input
          type="search"
          placeholder={t('topbar.search')}
          className="w-full bg-transparent text-base text-text placeholder:text-icon focus:outline-none sm:text-sm"
        />
      </label>

      <div className="ml-auto flex items-center gap-x-2">
        {branches.length > 0 && (
          <select
            value={current?.id ?? ''}
            onChange={(e) => select(Number(e.target.value))}
            aria-label={t('topbar.branch')}
            className="hidden min-h-touch rounded-lg bg-surface px-3 text-sm font-medium text-text shadow-sm ring-1 ring-ring-strong md:block"
          >
            {branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </select>
        )}
        <AlertBell />
        <UserMenu />
      </div>
    </header>
  )
}
