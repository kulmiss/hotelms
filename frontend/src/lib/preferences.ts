import { setLanguage } from '@/i18n'
import type { Me } from './api'
import { setCalendarMode } from './format'

/** Language and calendar: the person's own choice, else the hotel's (else English / Gregorian). */
export function applyPreferences(me: Me) {
  const lang = me.user.language ?? me.hotel?.language ?? 'en'
  setCalendarMode(me.user.calendar ?? me.hotel?.calendar, lang)
  void setLanguage(lang)
}
