/// <reference types="vitest/config" />
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { fileURLToPath, URL } from 'node:url'

// In development the API is reached through /api (proxied to Laravel),
// so the browser never needs CORS. The Origin header is kept as
// localhost:5173 so Sanctum treats the app as its own front end.
const apiTarget = process.env.API_PROXY_TARGET ?? 'http://localhost:8000'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  // A new number for every build: the offline copy of the app knows when there is a new version.
  define: { __APP_BUILD__: JSON.stringify(process.env.APP_BUILD ?? String(Date.now())) },
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: {
    host: true,
    port: 5173,
    // Prepare the app and every screen at start-up, so the first visit to a page is not slow.
    warmup: { clientFiles: ['./src/main.tsx', './src/app/router.tsx', './src/pages/**/*.tsx', './src/components/**/*.tsx'] },
    proxy: {
      '/api': { target: apiTarget },
      '/sanctum': { target: apiTarget },
      '/storage': { target: apiTarget },
      // The public website lives in Laravel too (the app itself starts at /dashboard).
      '^/(terms|privacy|robots\\.txt|sitemap\\.xml)?$': { target: apiTarget },
      '/landing/': { target: apiTarget },
    },
  },
  optimizeDeps: {
    include: ['react', 'react-dom', 'react-dom/client', 'react-router', '@tanstack/react-query', 'i18next', 'react-i18next', '@heroicons/react/24/outline', 'lucide-react'],
  },
  build: {
    target: 'es2022',
    reportCompressedSize: false,
    rollupOptions: {
      output: {
        // Libraries change rarely: in their own files they stay cached across app updates.
        manualChunks: {
          react: ['react', 'react-dom', 'react-router'],
          data: ['@tanstack/react-query', 'i18next', 'react-i18next'],
        },
      },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    css: false,
  },
})
