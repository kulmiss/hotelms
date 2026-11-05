import { useCallback, useState } from 'react'

const KEY = 'hms-sidebar-collapsed'

/** Remembers on this device whether the sidebar is collapsed. */
export function useSidebarCollapsed() {
  const [collapsed, setCollapsed] = useState(() => {
    try {
      return localStorage.getItem(KEY) === '1'
    } catch {
      return false
    }
  })

  const toggle = useCallback(() => {
    setCollapsed((c) => {
      try {
        localStorage.setItem(KEY, c ? '0' : '1')
      } catch {
        // ignore
      }
      return !c
    })
  }, [])

  return { collapsed, toggle }
}
