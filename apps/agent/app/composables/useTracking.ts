import { invoke } from '@tauri-apps/api/core'
import { listen, type UnlistenFn } from '@tauri-apps/api/event'

// Mirrors the Rust DTOs in src-tauri/src/commands.rs and view.rs.
export type TrackingStateValue = 'not_tracking' | 'tracking' | 'paused' | 'away'

export interface TrackingStateDto {
  state: TrackingStateValue
  openSessionId: string | null
  openSessionApp: string | null
  openSessionStartedAt: number | null
}

export interface AppTimeDto {
  name: string
  seconds: number
}

export interface TodaySummaryDto {
  activeMs: number
  idleMs: number
  liveKind: 'active' | 'idle' | null
  apps: AppTimeDto[]
  currentApp: string | null
  currentTitle: string | null
  workStartedAt: number | null
}

export interface SegmentDto {
  kind: 'active' | 'idle'
  label: string
  startedAt: number
  endedAt: number
}

/** 3723 -> "01:02:03" */
export function formatClock(totalSeconds: number): string {
  const s = Math.max(0, Math.floor(totalSeconds))
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${pad(Math.floor(s / 3600))}:${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}`
}

/** 3723 -> "1 h 2 min", 95 -> "1 min 35 s" */
export function formatDuration(totalSeconds: number): string {
  const s = Math.max(0, Math.floor(totalSeconds))
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  if (h > 0)
    return `${h} h ${m} min`
  if (m > 0)
    return `${m} min ${s % 60} s`
  return `${s} s`
}

export type StatusKey = 'active' | 'idle' | 'paused' | 'away' | 'not_tracking'

export const STATUS_LABEL: Record<StatusKey, string> = {
  active: 'Active',
  idle: 'Idle',
  paused: 'Paused',
  away: 'Away',
  not_tracking: 'Not tracking',
}

export interface SessionDto {
  id: string
  sessionType: 'application' | 'idle'
  appName: string | null
  idleAppName: string | null
  startedAt: number
  endedAt: number | null
  durationSeconds: number | null
  syncStatus: string
}

/**
 * Drives and observes the local tracking engine while mounted. Rust announces state and
 * app changes as events; the totals are re-read once a second, and only while the window
 * is visible.
 */
export function useTracking() {
  const state = ref<TrackingStateDto | null>(null)
  const summary = ref<TodaySummaryDto | null>(null)
  const timeline = ref<SegmentDto[]>([])
  const resumedNotice = ref(false)
  const error = ref<string | null>(null)

  // The counters advance on the screen's own clock between fetches, so they move one
  // whole second at a time; each fetch only corrects them.
  const fetchedAt = ref(Date.now())
  const now = ref(Date.now())

  const status = computed<StatusKey>(() => {
    switch (state.value?.state) {
      case 'tracking':
        return summary.value?.liveKind === 'idle' ? 'idle' : 'active'
      case 'paused':
        return 'paused'
      case 'away':
        return 'away'
      default:
        return 'not_tracking'
    }
  })

  const clock = computed(() => {
    const s = summary.value
    if (!s)
      return null
    const running = now.value - fetchedAt.value
    const activeMs = s.activeMs + (s.liveKind === 'active' ? running : 0)
    const idleMs = s.idleMs + (s.liveKind === 'idle' ? running : 0)
    return {
      active: formatClock(activeMs / 1000),
      idle: formatClock(idleMs / 1000),
      tracked: formatClock((activeMs + idleMs) / 1000),
    }
  })

  /** Time since Start, while a tracking run is on. */
  const workClock = computed(() => {
    const started = summary.value?.workStartedAt
    if (!started || state.value?.state === 'not_tracking')
      return null
    return formatClock((now.value - started) / 1000)
  })

  let timer: ReturnType<typeof setInterval> | undefined
  let ticker: ReturnType<typeof setInterval> | undefined
  const unlisteners: UnlistenFn[] = []
  let timelineTicks = 0

  const visible = () => document.visibilityState === 'visible'

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

  async function refreshTimeline() {
    try {
      timeline.value = await invoke<SegmentDto[]>('get_today_timeline')
    }
    catch (e) {
      error.value = String(e)
    }
  }

  async function refreshAll() {
    await Promise.all([refresh(), refreshTimeline()])
  }

  async function act(command: string) {
    state.value = await invoke<TrackingStateDto>(command)
    await refreshAll()
  }

  const start = () => act('start_tracking')
  const pause = () => act('pause_tracking')
  const resume = () => act('resume_tracking')
  const stop = () => act('stop_tracking')

  /** Zeroes today's counters on screen; no session is deleted. Only while stopped. */
  async function resetCounters() {
    await invoke('reset_today_counters')
    await refresh()
  }

  onMounted(async () => {
    unlisteners.push(
      await listen('tracking-resumed', () => {
        resumedNotice.value = true
      }),
      await listen('tracking-state-changed', refreshAll),
      await listen('activity-changed', refresh),
    )
    await refreshAll()
    timer = setInterval(() => {
      if (!visible())
        return
      refresh()
      timelineTicks += 1
      if (timelineTicks % 5 === 0)
        refreshTimeline()
    }, 1000)
    ticker = setInterval(() => {
      now.value = Date.now()
    }, 200)
    document.addEventListener('visibilitychange', onVisible)
  })

  function onVisible() {
    if (visible())
      refreshAll()
  }

  onBeforeUnmount(() => {
    clearInterval(timer)
    clearInterval(ticker)
    document.removeEventListener('visibilitychange', onVisible)
    unlisteners.forEach(unlisten => unlisten())
  })

  return {
    state,
    summary,
    timeline,
    status,
    clock,
    workClock,
    resumedNotice,
    error,
    start,
    pause,
    resume,
    stop,
    resetCounters,
    refresh,
  }
}
