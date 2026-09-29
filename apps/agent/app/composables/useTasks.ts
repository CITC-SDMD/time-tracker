import { invoke } from '@tauri-apps/api/core'

// Mirrors `TaskDto` in src-tauri/src/sync/client.rs. An employee only ever sees the tasks a manager
// assigned to them, cached from the last sync (`get_my_tasks` reads no network, so it works offline).
export interface TaskDto {
  id: string
  title: string
}

/** The signed-in person's own tasks, and picking which one (if any) the current session is tagged with. */
export function useTasks() {
  const tasks = ref<TaskDto[]>([])
  const error = ref<string | null>(null)

  async function refresh() {
    try {
      tasks.value = await invoke<TaskDto[]>('get_my_tasks')
      error.value = null
    }
    catch (e) {
      error.value = String(e)
    }
  }

  /** Picks a task (or clears it with `null`): the currently open session is closed and a new one opens tagged with it. */
  async function select(taskId: string | null) {
    return invoke('set_current_task', { taskId })
  }

  onMounted(refresh)

  return { tasks, error, refresh, select }
}
