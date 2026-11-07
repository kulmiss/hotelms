import { describe, expect, it } from 'vitest'
import { isUpdateError } from '@/components/layout/RouteError'

describe('launch recheck', () => {
  it('knows when a screen failed because a new version was put online', () => {
    expect(isUpdateError(new TypeError('Failed to fetch dynamically imported module: /assets/Billing-abc.js'))).toBe(true)
    expect(isUpdateError(new Error('Importing a module script failed.'))).toBe(true)
    expect(isUpdateError(new TypeError("Cannot read properties of undefined (reading 'map')"))).toBe(false)
  })
})
