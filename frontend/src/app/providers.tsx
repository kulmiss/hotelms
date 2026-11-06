import { MutationCache, QueryCache, QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import i18n from '@/i18n'
import { ApiError } from '@/lib/api'
import { toast } from '@/lib/toast'
import { ME_KEY } from '@/lib/auth'
import { startOfflineCopy } from '@/lib/offline'
import { BrandingEffects } from '@/lib/brand'
import { ThemeProvider } from '@/lib/theme'
import { Toaster } from '@/components/ui/Toaster'

/** Codes after which the app must ask "who am I?" again (the sign-in pages take over from there). */
const RECHECK = ['password_change_required', 'two_factor_setup_required']
/** HotelMS emergency switches: ask again so the maintenance screen or read-only notice shows. */
const EMERGENCY = ['maintenance', 'read_only']

/**
 * One place for problems every screen can hit: a session that ended (expired, device signed out,
 * account disabled) sends the person to the sign-in page instead of leaving a broken screen.
 */
function makeClient(): QueryClient {
  const client: QueryClient = new QueryClient({
    queryCache: new QueryCache({ onError: (e, query) => handle(e, query.queryKey[0] === ME_KEY[0]) }),
    mutationCache: new MutationCache({
      onError: (e) => handle(e, false),
      // Every change confirms itself with a short toast ("Saved."), unless the action says otherwise (meta.toast).
      onSuccess: (_data, _vars, _ctx, mutation) => {
        const setting = mutation.meta?.toast
        if (setting === false) return
        toast(i18n.t(typeof setting === 'string' ? setting : 'common.savedToast'))
      },
    }),
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        refetchOnWindowFocus: false,
        // Answers like "not allowed" or "not found" do not change by asking again; network blips do.
        retry: (count, e) => !(e instanceof ApiError && (e.status < 500 || (e.code && EMERGENCY.includes(e.code)))) && count < 2,
      },
    },
  })

  function handle(e: unknown, isMe: boolean) {
    if (!(e instanceof ApiError) || isMe) return
    if (e.status === 401 || e.status === 419) {
      client.setQueryData(ME_KEY, null)
    } else if ((e.status === 403 && e.code && RECHECK.includes(e.code)) || (e.code && EMERGENCY.includes(e.code))) {
      void client.invalidateQueries({ queryKey: ME_KEY })
    }
  }

  startOfflineCopy(client)
  return client
}

export function Providers({ children }: { children: ReactNode }) {
  const [queryClient] = useState(makeClient)
  return (
    <ThemeProvider>
      <QueryClientProvider client={queryClient}>
        <BrandingEffects />
        {children}
        <Toaster />
      </QueryClientProvider>
    </ThemeProvider>
  )
}
