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

  let timer: ReturnType<typeof setInterval> | undefined
  const unlisteners: UnlistenFn[] = []

  async function refresh() {
    try {
      status.value = await invoke<SyncStatus>('get_sync_status')
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
    timer = setInterval(refresh, 5000)
  })

  onBeforeUnmount(() => {
    clearInterval(timer)
    unlisteners.forEach(unlisten => unlisten())
  })

  return { status, notice, refresh }
}
