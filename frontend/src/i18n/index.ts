import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import en from './locales/en.json'
// Staff guides (Help → How to use): long texts in their own file, under the key "guide".
import guide from './locales/en.guide.json'

// Translation-ready: every UI string goes through t(). Amharic comes after v1.
void i18n.use(initReactI18next).init({
  resources: { en: { translation: { ...en, guide } } },
  lng: 'en',
  fallbackLng: 'en',
  interpolation: { escapeValue: false },
})

export default i18n
