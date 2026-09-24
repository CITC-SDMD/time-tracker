import { invoke } from '@tauri-apps/api/core'
import { listen, type UnlistenFn } from '@tauri-apps/api/event'

// Mirrors the Rust structs in src-tauri/src/commands.rs.
export interface CurrentActivity {
  application: string | null
  processName: string | null
  windowTitle: string | null
  idleSeconds: number
}

export type SystemEventKind = 'LOCK' | 'UNLOCK' | 'SLEEP' | 'WAKE' | 'SHUTDOWN'

export interface SystemEventRecord {
  kind: SystemEventKind
  at: string
}

/** Polls the Rust side for the foreground app once a second while mounted. */
export function useActivity() {
  const activity = ref<CurrentActivity | null>(null)
  const events = ref<SystemEventRecord[]>([])
  const logPath = ref<string>('')
  const error = ref<string | null>(null)

  let timer: ReturnType<typeof setInterval> | undefined
  let unlisten: UnlistenFn | undefined

  async function refresh() {
    try {
      activity.value = await invoke<CurrentActivity>('get_current_activity')
      error.value = null
    }
    catch (e) {
      error.value = String(e)
    }
  }

  onMounted(async () => {
    logPath.value = await invoke<string>('get_spike_log_path')
    events.value = await invoke<SystemEventRecord[]>('get_recent_events')
    unlisten = await listen<SystemEventRecord>('system-event', (e) => {
      events.value = [e.payload, ...events.value].slice(0, 50)
    })
    await refresh()
    timer = setInterval(refresh, 1000)
  })

  onBeforeUnmount(() => {
    clearInterval(timer)
    unlisten?.()
  })

  return { activity, events, logPath, error }
}
