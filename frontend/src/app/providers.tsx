import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { BrandingEffects } from '@/lib/brand'
import { ThemeProvider } from '@/lib/theme'

export function Providers({ children }: { children: ReactNode }) {
  const [queryClient] = useState(
    () => new QueryClient({ defaultOptions: { queries: { staleTime: 30_000, refetchOnWindowFocus: false } } }),
  )
  return (
    <ThemeProvider>
      <QueryClientProvider client={queryClient}>
        <BrandingEffects />
        {children}
      </QueryClientProvider>
    </ThemeProvider>
  )
}
