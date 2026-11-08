import { useTranslation } from 'react-i18next'
import { Laptop, Moon, Sun } from 'lucide-react'
import { Button } from '@/components/shadcn/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuTrigger } from '@/components/shadcn/dropdown-menu'
import { useTheme, type ThemeChoice } from '@/lib/theme'

const THEMES: { choice: ThemeChoice; icon: typeof Sun }[] = [
  { choice: 'light', icon: Sun },
  { choice: 'dark', icon: Moon },
  { choice: 'system', icon: Laptop },
]

/** Sun / moon button in the header: Light, Dark or Same as device (template ThemeSwitch). */
export function ThemeSwitch() {
  const { t } = useTranslation()
  const { choice, setChoice } = useTheme()

  return (
    <DropdownMenu modal={false}>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon" aria-label={t('topbar.theme')} title={t('topbar.theme')} className="scale-95 rounded-full pointer-coarse:scale-100">
          <Sun aria-hidden className="size-[1.2rem] scale-100 rotate-0 transition-all dark:scale-0 dark:-rotate-90" />
          <Moon aria-hidden className="absolute size-[1.2rem] scale-0 rotate-90 transition-all dark:scale-100 dark:rotate-0" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuRadioGroup value={choice} onValueChange={(v) => setChoice(v as ThemeChoice)}>
          {THEMES.map(({ choice: c, icon: Icon }) => (
            <DropdownMenuRadioItem key={c} value={c}>
              <Icon aria-hidden />
              {t(`topbar.themes.${c}`)}
            </DropdownMenuRadioItem>
          ))}
        </DropdownMenuRadioGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
