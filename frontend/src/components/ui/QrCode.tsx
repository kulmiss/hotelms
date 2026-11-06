import { useMemo } from 'react'
import { qrMatrix, qrSvgPath } from '@/lib/qr'

/** A QR code (always black on white, also in dark mode, so every phone camera can read it). */
export function QrCode({ value, label, className = 'size-48' }: { value: string; label: string; className?: string }) {
  const { path, size } = useMemo(() => qrSvgPath(qrMatrix(value)), [value])
  return (
    <svg role="img" aria-label={label} viewBox={`0 0 ${size} ${size}`} shapeRendering="crispEdges" className={`${className} rounded-lg bg-white text-black ring-1 ring-ring`}>
      <path d={path} fill="currentColor" />
    </svg>
  )
}
