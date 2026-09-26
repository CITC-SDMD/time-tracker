import { invoke } from '@tauri-apps/api/core'
import { listen, type UnlistenFn } from '@tauri-apps/api/event'

// Mirrors `ScreenshotStatus` and `ScreenshotItem` in src-tauri/src/screenshot (docs phase 10).
export interface ScreenshotStatus {
  enabled: boolean
  intervalMinutes: number
  random: boolean
  waiting: number
  lastTakenAt: number | null
}

export interface ScreenshotItem {
  id: string
  takenAt: string
  width: number
  height: number
}

/** "2026-09-26" for now in the given timezone. */
export function dayIn(timezone: string, moment: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(moment)
}

/** "14:05" in the given timezone. */
export function clockTime(moment: Date | number | string, timezone: string): string {
  return new Intl.DateTimeFormat('en-GB', { timeZone: timezone, hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(moment))
}

/** What the app knows about screenshots: whether they are on, what is waiting to be sent, when the last one was taken. */
export function useScreenshots() {
  const status = ref<ScreenshotStatus | null>(null)
  const unlisteners: UnlistenFn[] = []

  async function refresh() {
    try {
      status.value = await invoke<ScreenshotStatus>('get_screenshot_status')
    }
    catch {
      status.value = null // not logged in yet
    }
  }

  onMounted(async () => {
    await refresh()
    unlisteners.push(await listen('screenshots-changed', refresh))
  })
  onBeforeUnmount(() => unlisteners.forEach(off => off()))

  return { status, refresh }
}
