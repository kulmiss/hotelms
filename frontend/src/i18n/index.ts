import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import en from './locales/en.json'
// Staff guides (Help → How to use): long texts in their own file, under the key "guide".
import guide from './locales/en.guide.json'

/**
 * Languages (owner, 14 Nov 2026): English (default) and Amharic. Amharic is a separate download,
 * loaded only when someone uses it, so English stays as fast as before.
 */
export const LANGUAGES = ['en', 'am'] as const
export type Language = (typeof LANGUAGES)[number]
const KEY = 'hotelms.lang'

function remembered(): Language {
  try {
    // A link from the public website carries ?lang=am (it sets no cookie): that comes first.
    const asked = new URLSearchParams(window.location.search).get('lang')
    if (asked === 'am' || asked === 'en') return asked
    return localStorage.getItem(KEY) === 'am' ? 'am' : 'en'
  } catch {
    return 'en'
  }
}

void i18n.use(initReactI18next).init({
  resources: { en: { translation: { ...en, guide } } },
  lng: 'en',
  fallbackLng: 'en', // a string not translated yet shows in English
  interpolation: { escapeValue: false },
})

/** Switch the interface language (downloads Amharic the first time) and remember it on this device. */
export async function setLanguage(lang: string | null | undefined): Promise<void> {
  const next: Language = lang === 'am' ? 'am' : 'en'
  if (next === 'am' && !i18n.hasResourceBundle('am', 'translation')) {
    const [am, amGuide] = await Promise.all([import('./locales/am.json'), import('./locales/am.guide.json')])
    i18n.addResourceBundle('am', 'translation', { ...am.default, guide: amGuide.default }, true, true)
  }
  try {
    localStorage.setItem(KEY, next)
  } catch {
    /* private mode: just not remembered */
  }
  document.documentElement.lang = next
  if (i18n.language !== next) await i18n.changeLanguage(next)
}

// The link's or the last language used on this device, until /me says otherwise.
const first = remembered()
if (first === 'am' || new URLSearchParams(window.location.search).has('lang')) void setLanguage(first)

export default i18n
