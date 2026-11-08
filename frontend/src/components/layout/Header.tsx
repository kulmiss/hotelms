import { useEffect, useState } from 'react'
import { Separator } from '@/components/shadcn/separator'
import { SidebarTrigger } from '@/components/shadcn/sidebar'
import { cn } from '@/lib/cn'
import { ProfileDropdown } from './AccountMenu'
import { AlertBell } from './AlertBell'
import { Search } from './Search'
import { ThemeSwitch } from './ThemeSwitch'

/** The template's header: menu button, search next to it, then bell, theme and account on the right. Sticky, with a shadow once scrolled. */
export function Header() {
  const [offset, setOffset] = useState(0)

  useEffect(() => {
    const onScroll = () => setOffset(document.body.scrollTop || document.documentElement.scrollTop)
    document.addEventListener('scroll', onScroll, { passive: true })
    return () => document.removeEventListener('scroll', onScroll)
  }, [])

  const scrolled = offset > 10
  return (
    <header className={cn('header-fixed peer/header sticky top-0 z-50 h-16 w-[inherit] md:rounded-t-xl', scrolled ? 'shadow' : 'shadow-none')}>
      <div className={cn('relative flex h-full items-center gap-3 p-4 sm:gap-4', scrolled && 'after:absolute after:inset-0 after:-z-10 after:bg-background/20 after:backdrop-blur-lg')}>
        <SidebarTrigger variant="outline" className="max-md:scale-125 pointer-coarse:max-md:scale-100" />
        <Separator orientation="vertical" className="h-6" />
        {/* Like the template's pages without top links: search on the left, next to the menu button; icons on the right. */}
        <div className="flex min-w-0 flex-1 items-center gap-2 sm:gap-4">
          <Search />
          <div className="ms-auto flex shrink-0 items-center gap-1 sm:gap-2">
            <AlertBell />
            <ThemeSwitch />
            <ProfileDropdown />
          </div>
        </div>
      </div>
    </header>
  )
}
