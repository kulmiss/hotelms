import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'
import '@/i18n'
import { clearToasts } from '@/lib/toast'
import { forgetDismissed } from '@/lib/smart'

afterEach(() => {
  cleanup()
  clearToasts()
  forgetDismissed()
  localStorage.clear()
  document.documentElement.classList.remove('dark')
})

// jsdom lacks what Radix UI and cmdk use (menus, tooltips, the command menu).
class NoResizeObserver {
  observe() {}
  unobserve() {}
  disconnect() {}
}
window.ResizeObserver ??= NoResizeObserver as unknown as typeof ResizeObserver
Element.prototype.scrollIntoView ??= function () {}
Element.prototype.hasPointerCapture ??= () => false
Element.prototype.releasePointerCapture ??= () => {}

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
