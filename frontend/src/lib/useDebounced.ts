import { useEffect, useState } from 'react'

/**
 * The value, updated only after it stopped changing for `ms` (search boxes, live prices).
 * Compared by content, so a new object with the same data does not restart the wait.
 */
export function useDebounced<T>(value: T, ms: number): T {
  const [v, setV] = useState(value)
  const key = JSON.stringify(value)
  useEffect(() => {
    const id = setTimeout(() => setV(JSON.parse(key) as T), ms)
    return () => clearTimeout(id)
  }, [key, ms])
  return v
}
