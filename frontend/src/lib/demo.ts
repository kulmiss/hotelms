/** "Try the demo" visits end after the minutes the Super Admin set; the sign-in page then says so once. */
const KEY = 'hms-demo-over'

export function markDemoOver() {
  try {
    sessionStorage.setItem(KEY, '1')
  } catch {
    /* storage can be blocked */
  }
}

/** Did a demo visit just end? (forget it with clearDemoOver once shown) */
export function isDemoOver(): boolean {
  try {
    return sessionStorage.getItem(KEY) === '1'
  } catch {
    return false
  }
}

export function clearDemoOver() {
  try {
    sessionStorage.removeItem(KEY)
  } catch {
    /* storage can be blocked */
  }
}

/** "12:05" for 725 seconds. */
export const mmss = (seconds: number) => `${Math.floor(Math.max(0, seconds) / 60)}:${String(Math.max(0, seconds) % 60).padStart(2, '0')}`
