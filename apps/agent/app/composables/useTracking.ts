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
  activeSeconds: number
  idleSeconds: number
  trackedSeconds: number
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

  let timer: ReturnType<typeof setInterval> | undefined
  let unlisten: UnlistenFn | undefined

  async function refresh() {
    try {
      state.value = await invoke<TrackingStateDto>('get_tracking_state')
      summary.value = await invoke<TodaySummaryDto>('get_today_summary')
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
    timer = setInterval(refresh, 2000)
  })

  onBeforeUnmount(() => {
    clearInterval(timer)
    unlisten?.()
  })

  return { state, summary, resumedNotice, error, start, pause, resume, stop, refresh }
}
