/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_URL?: string
}

/** Build number (vite.config.ts), used by the offline copy of the app. */
declare const __APP_BUILD__: string
