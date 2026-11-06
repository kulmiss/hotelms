import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'
import '@/i18n'
import { clearToasts } from '@/lib/toast'

afterEach(() => {
  cleanup()
  clearToasts()
  localStorage.clear()
  document.documentElement.classList.remove('dark')
})

// jsdom has no matchMedia.
if (!window.matchMedia) {
  window.matchMedia = (query: string) =>
    ({
      matches: false,
      media: query,
      onchange: null,
      addListener: () => {},
      removeListener: () => {},
      addEventListener: () => {},
      removeEventListener: () => {},
      dispatchEvent: () => false,
    }) as MediaQueryList
}
