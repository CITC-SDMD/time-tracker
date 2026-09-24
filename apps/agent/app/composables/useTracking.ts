import { invoke } from '@tauri-apps/api/core'
import { listen, type UnlistenFn } from '@tauri-apps/api/event'

// Mirrors the Rust DTOs in src-tauri/src/commands.rs.
export type TrackingStateValue = 'NOT_TRACKING' | 'TRACKING' | 'PAUSED' | 'AWAY'

export interface TrackingStateDto {
  state: TrackingStateValue
  openSessionId: string | null
  openSessionApp: string | null
  openSessionStartedAt: number | null
}

export interface TodaySummaryDto {
  activeMs: number
  idleMs: number
  liveKind: 'ACTIVE' | 'IDLE' | null
}

/** 3723 -> "01:02:03" */
export function formatClock(totalSeconds: number): string {
  const s = Math.max(0, Math.floor(totalSeconds))
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${pad(Math.floor(s / 3600))}:${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}`
}

export interface SessionDto {
  id: string
  sessionType: 'APPLICATION' | 'IDLE'
  appName: string | null
  idleAppName: string | null
  startedAt: number
  endedAt: number | null
  durationSeconds: number | null
  syncStatus: string
}

/** Drives and observes the local tracking engine while mounted. */
export function useTracking() {
  const state = ref<TrackingStateDto | null>(null)
  const summary = ref<TodaySummaryDto | null>(null)
  const resumedNotice = ref(false)
  const error = ref<string | null>(null)

  // The counters advance on the screen's own clock between fetches, so they move one
  // whole second at a time; each fetch only corrects them.
  const fetchedAt = ref(Date.now())
  const now = ref(Date.now())

  const clock = computed(() => {
    const s = summary.value
    if (!s)
      return null
    const running = now.value - fetchedAt.value
    const activeMs = s.activeMs + (s.liveKind === 'ACTIVE' ? running : 0)
    const idleMs = s.idleMs + (s.liveKind === 'IDLE' ? running : 0)
    return {
      active: formatClock(activeMs / 1000),
      idle: formatClock(idleMs / 1000),
      tracked: formatClock((activeMs + idleMs) / 1000),
    }
  })

  let timer: ReturnType<typeof setInterval> | undefined
  let ticker: ReturnType<typeof setInterval> | undefined
  let unlisten: UnlistenFn | undefined

  async function refresh() {
    try {
      state.value = await invoke<TrackingStateDto>('get_tracking_state')
      const fresh = await invoke<TodaySummaryDto>('get_today_summary')
      fetchedAt.value = Date.now()
      now.value = fetchedAt.value
      summary.value = fresh
      error.value = null
    }
    catch (e) {
      error.value = String(e)
    }
  }

  async function start() {
    state.value = await invoke<TrackingStateDto>('start_tracking')
  }

  async function pause() {
    state.value = await invoke<TrackingStateDto>('pause_tracking')
  }

  async function resume() {
    state.value = await invoke<TrackingStateDto>('resume_tracking')
  }

  async function stop() {
    state.value = await invoke<TrackingStateDto>('stop_tracking')
  }

  onMounted(async () => {
    unlisten = await listen('tracking-resumed', () => {
      resumedNotice.value = true
    })
    await refresh()
    timer = setInterval(refresh, 1000)
    ticker = setInterval(() => {
      now.value = Date.now()
    }, 200)
  })

  onBeforeUnmount(() => {
    clearInterval(timer)
    clearInterval(ticker)
    unlisten?.()
  })

  return { state, summary, clock, resumedNotice, error, start, pause, resume, stop, refresh }
}
