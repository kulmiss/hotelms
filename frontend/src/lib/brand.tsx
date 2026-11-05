import { useEffect, type ImgHTMLAttributes } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from './api'
import { cn } from './cn'

export type Branding = {
  platform_name: string
  brand_color: string
  login_message: string | null
  support_email: string | null
  support_phone: string | null
  logo_url: string | null
  demo_public?: boolean
}

const FALLBACK: Branding = {
  platform_name: 'HotelMS',
  brand_color: '#0f766e',
  login_message: null,
  support_email: null,
  support_phone: null,
  logo_url: null,
}

export const BRANDING_KEY = ['branding'] as const

/** Name, colour and logo set by the Super Admin (Settings → Branding). */
export function useBrand() {
  const q = useQuery({
    queryKey: BRANDING_KEY,
    queryFn: () => api<{ data: Branding }>('/branding').then((r) => r.data),
    staleTime: 30_000,
    refetchOnMount: 'always',
    retry: 1,
  })
  const b = q.data ?? FALLBACK
  return { name: b.platform_name, color: b.brand_color, logoUrl: b.logo_url, loginMessage: b.login_message, raw: b }
}

/** Readable text colour (white or near-black) on top of a hex colour. */
export function onColor(hex: string): string {
  const n = parseInt(hex.slice(1), 16)
  const [r, g, b] = [(n >> 16) & 255, (n >> 8) & 255, n & 255].map((v) => {
    const c = v / 255
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  })
  const lum = 0.2126 * r + 0.7152 * g + 0.0722 * b
  return lum > 0.4 ? '#0a0a0a' : '#ffffff'
}

/** CSS that turns the brand colour into the app's accent tokens (light and dark). */
export function brandCss(color: string): string {
  return `:root{--accent:${color};--accent-hover:color-mix(in oklab,${color} 85%,black);--accent-soft:color-mix(in oklab,${color} 8%,white);--accent-text:color-mix(in oklab,${color} 90%,black);--on-accent:${onColor(color)};--room-occupied:${color}}
.dark{--accent:color-mix(in oklab,${color} 70%,white);--accent-hover:color-mix(in oklab,${color} 55%,white);--accent-soft:color-mix(in oklab,${color} 15%,transparent);--accent-text:color-mix(in oklab,${color} 55%,white);--on-accent:#0a0a0a;--room-occupied:color-mix(in oklab,${color} 70%,white)}`
}

/** Applies the branding to the whole app: colours, tab title and icon. */
export function BrandingEffects() {
  const { name, color, logoUrl } = useBrand()

  useEffect(() => {
    let tag = document.getElementById('brand-css') as HTMLStyleElement | null
    if (!tag) {
      tag = document.createElement('style')
      tag.id = 'brand-css'
      document.head.appendChild(tag)
    }
    tag.textContent = /^#[0-9a-f]{6}$/i.test(color) ? brandCss(color) : ''
  }, [color])

  useEffect(() => {
    document.title = name
    const link = document.querySelector<HTMLLinkElement>('link[rel="icon"]')
    if (link) link.href = logoUrl ?? '/favicon.svg'
  }, [name, logoUrl])

  return null
}

/** The logo if one is uploaded, otherwise a letter mark in the brand colour. */
export function BrandMark({ className, ...props }: ImgHTMLAttributes<HTMLImageElement>) {
  const { name, logoUrl } = useBrand()
  if (logoUrl) return <img src={logoUrl} alt="" className={cn('object-contain', className)} {...props} />
  return (
    <span aria-hidden className={cn('flex shrink-0 items-center justify-center rounded-lg bg-accent font-bold text-on-accent', className)}>
      <span className="text-[0.6em] leading-none">{name.slice(0, 1).toUpperCase()}</span>
    </span>
  )
}
