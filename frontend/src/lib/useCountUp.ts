import { useEffect, useRef, useState } from 'react'

/** Counts from the previous value to the new one (respects reduced motion). */
export function useCountUp(target: number | undefined, duration = 600): number | undefined {
  const [value, setValue] = useState(target)
  const from = useRef(0)

  useEffect(() => {
    if (target === undefined) return
    const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
    if (reduce || typeof requestAnimationFrame === 'undefined') {
      setValue(target)
      return
    }
    const start = performance.now()
    const begin = from.current
    let frame = 0
    const tick = (now: number) => {
      const p = Math.min(1, (now - start) / duration)
      const eased = 1 - Math.pow(1 - p, 3)
      setValue(Math.round(begin + (target - begin) * eased))
      if (p < 1) frame = requestAnimationFrame(tick)
      else from.current = target
    }
    frame = requestAnimationFrame(tick)
    return () => cancelAnimationFrame(frame)
  }, [target, duration])

  return value
}
