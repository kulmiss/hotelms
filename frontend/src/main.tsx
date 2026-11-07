import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { RouterProvider } from 'react-router/dom'
import './i18n'
import './index.css'
import { Providers } from './app/providers'
import { router } from './app/router'
import { registerServiceWorker } from './app/serviceWorker'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <Providers>
      <RouterProvider router={router} />
    </Providers>
  </StrictMode>,
)

registerServiceWorker()

// After an update the old screen files are gone: reload once to get the new ones (not in a loop).
window.addEventListener('vite:preloadError', (event) => {
  if (!sessionStorage.getItem('hms-reloaded-for-update')) {
    event.preventDefault()
    sessionStorage.setItem('hms-reloaded-for-update', '1')
    window.location.reload()
  }
})
// Running fine for a while: a later update may reload again.
window.setTimeout(() => sessionStorage.removeItem('hms-reloaded-for-update'), 15_000)
