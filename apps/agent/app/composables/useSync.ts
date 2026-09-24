import { invoke } from '@tauri-apps/api/core'
import { listen, type UnlistenFn } from '@tauri-apps/api/event'

// Mirrors `SyncStatusDto` in src-tauri/src/commands.rs.
export interface SyncStatus {
  pendingCount: number
  lastSyncAt: number | null
  lastError: string | null
  online: boolean
  needsLogin: boolean
  upgradeRequired: boolean
}

/** Sync status plus the one-line notices the Rust side raises ("Tracking was started on another computer."). */
export function useSync() {
  const status = ref<SyncStatus | null>(null)
  const notice = ref<string | null>(null)

  // The most that has been waiting during the current backlog; the bar measures from it.
  // It starts over once everything is sent.
  const backlogSize = ref(0)

  /** A bar only for a real backlog (a single session going out needs none). */
  const progress = computed(() => {
    const pending = status.value?.pendingCount ?? 0
    if (pending === 0 || backlogSize.value < 20)
      return null
    const sent = backlogSize.value - pending
    return {
      sent,
      total: backlogSize.value,
      percent: Math.round((sent / backlogSize.value) * 100),
    }
  })

  let timer: ReturnType<typeof setInterval> | undefined
  let ticks = 0
  const unlisteners: UnlistenFn[] = []

  async function refresh() {
    try {
      status.value = await invoke<SyncStatus>('get_sync_status')
      backlogSize.value = status.value.pendingCount === 0
        ? 0
        : Math.max(backlogSize.value, status.value.pendingCount)
      // The "please update" notice describes an ongoing condition: drop it once syncing works again.
      if (!status.value.upgradeRequired && notice.value?.startsWith('Please update the app'))
        notice.value = null
    }
    catch {
      // Not critical: the chip just keeps its last value.
    }
  }

  onMounted(async () => {
    unlisteners.push(
      await listen<string>('notice', async (event) => {
        notice.value = event.payload
        // A notice may mean the login ended ("Please log in again"); go where the guard says.
        const { me, load } = useAuth()
        await load()
        if (!me.value)
          await navigateTo('/login')
      }),
      await listen('sync-status-changed', refresh),
    )
    await refresh()
    // Every second while something is waiting (so the bar moves), every 5 seconds otherwise.
    timer = setInterval(() => {
      ticks += 1
      if ((status.value?.pendingCount ?? 0) > 0 || ticks % 5 === 0)
        refresh()
    }, 1000)
  })

  onBeforeUnmount(() => {
    clearInterval(timer)
    unlisteners.forEach(unlisten => unlisten())
  })

  function dismissNotice() {
    notice.value = null
  }

  return { status, notice, progress, refresh, dismissNotice }
}
