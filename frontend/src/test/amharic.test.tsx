import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useTranslation } from 'react-i18next'
import { afterEach, describe, expect, it } from 'vitest'
import { LanguageSwitch } from '@/components/ui/LanguageSwitch'
import i18n, { setLanguage } from '@/i18n'
import en from '@/i18n/locales/en.json'
import am from '@/i18n/locales/am.json'
import enGuide from '@/i18n/locales/en.guide.json'
import amGuide from '@/i18n/locales/am.guide.json'
import { date, setCalendarMode } from '@/lib/format'

afterEach(async () => {
  setCalendarMode('gregorian')
  await setLanguage('en')
})

type Tree = { [k: string]: unknown }
function flat(t: unknown, p = ''): Record<string, string> {
  if (typeof t === 'string') return { [p]: t }
  if (Array.isArray(t)) return Object.assign({}, ...t.map((x, i) => flat(x, `${p}[${i}]`)))
  return Object.assign({}, ...Object.entries(t as Tree).filter(([k]) => !(p === '' && k === '_meta')).map(([k, v]) => flat(v, p ? `${p}.${k}` : k)))
}
const vars = (s: string) => [...s.matchAll(/\{\{\s*([\w.]+)\s*\}\}/g)].map((m) => m[1]).sort()

describe('Amharic', () => {
  it.each([
    ['interface', en, am],
    ['staff guide', enGuide, amGuide],
  ])('%s: every English string has an Amharic one with the same {{placeholders}}', (_n, e, a) => {
    const E = flat(e)
    const A = flat(a)
    expect(Object.keys(A).sort()).toEqual(Object.keys(E).sort())
    for (const k of Object.keys(E)) expect(vars(A[k]), k).toEqual(vars(E[k]))
  })

  it('is marked for native review', () => {
    expect((am as unknown as { _meta: { status: string } })._meta.status).toBe('needs native review')
  })

  it('the switch loads Amharic and sets the page language', async () => {
    function Probe() {
      const { t } = useTranslation()
      return <p data-testid="p">{t('language.mine')}</p>
    }
    render(
      <>
        <LanguageSwitch />
        <Probe />
      </>,
    )
    expect(screen.getByTestId('p').textContent).toBe(flat(en)['language.mine'])
    await userEvent.click(screen.getByRole('button', { name: 'አማርኛ' }))
    await waitFor(() => expect(i18n.language).toBe('am'))
    expect(screen.getByTestId('p').textContent).toBe(flat(am)['language.mine'])
    expect(document.documentElement.lang).toBe('am')
    expect(localStorage.getItem('hotelms.lang')).toBe('am')
  })

  it('dates use Amharic month names and the EC mark', async () => {
    await setLanguage('am')
    setCalendarMode('both', 'am')
    expect(date('2026-11-14')).toContain('ኅዳር')
    setCalendarMode('ethiopian', 'am')
    expect(date('2026-11-14')).toContain('ዓ.ም.')
  })
})
