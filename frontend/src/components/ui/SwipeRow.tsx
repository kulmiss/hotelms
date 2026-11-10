import { useRef, useState, type ReactNode, type PointerEvent } from 'react'
import { cn } from '@/lib/cn'

const OPEN = 112 // px the action is wide
const TRIGGER = 0.6 // swipe past 60% of the action = do it

/**
 * A list row you can swipe left on a touch screen to show a quick action (e.g. Check in).
 * With a mouse or keyboard the action is a normal button at the end of the row (nothing works only by swiping).
 */
export function SwipeRow({ children, action, onAction, tone = 'accent' }: { children: ReactNode; action: string; onAction: () => void; tone?: 'accent' | 'warn' }) {
  const start = useRef<{ x: number; y: number; base: number } | null>(null)
  const [dx, setDx] = useState(0)
  const [dragging, setDragging] = useState(false)

  const down = (e: PointerEvent) => {
    if (e.pointerType !== 'touch') return
    start.current = { x: e.clientX, y: e.clientY, base: dx }
  }
  const move = (e: PointerEvent) => {
    const s = start.current
    if (!s) return
    const x = e.clientX - s.x
    if (!dragging && Math.abs(e.clientY - s.y) > Math.abs(x)) {
      start.current = null // scrolling up/down, not a swipe
      return
    }
    if (Math.abs(x) > 6) setDragging(true)
    setDx(Math.max(-OPEN * 1.4, Math.min(0, s.base + x)))
  }
  const up = () => {
    if (!start.current) return
    start.current = null
    setDragging(false)
    if (dx < -OPEN * 1.2) {
      setDx(0)
      onAction()
    } else setDx(dx < -OPEN * TRIGGER ? -OPEN : 0)
  }

  return (
    <div className="relative overflow-hidden rounded-md">
      <button
        type="button"
        tabIndex={-1}
        aria-hidden
        onClick={onAction}
        style={{ width: OPEN }}
        className={cn('absolute inset-y-0 right-0 flex items-center justify-center text-sm font-medium', tone === 'warn' ? 'bg-warn-text text-white dark:text-background' : 'bg-primary text-primary-foreground')}
      >
        {action}
      </button>
      <div
        onPointerDown={down}
        onPointerMove={move}
        onPointerUp={up}
        onPointerCancel={up}
        onClickCapture={(e) => {
          if (dragging || dx !== 0) {
            e.preventDefault()
            e.stopPropagation()
            if (!dragging) setDx(0)
          }
        }}
        style={{ transform: `translateX(${dx}px)`, touchAction: 'pan-y' }}
        className={cn('relative bg-surface', !dragging && 'transition-transform duration-150 ease-out')}
      >
        {children}
      </div>
    </div>
  )
}
