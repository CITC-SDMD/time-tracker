import { invoke } from '@tauri-apps/api/core'

// Mirrors `TaskDto` in src-tauri/src/sync/client.rs. An employee only ever sees the tasks a manager
// assigned to them, cached from the last sync (`get_my_tasks` reads no network, so it works offline).
export interface TaskDto {
  id: string
  title: string
}

// Mirrors `TaskTimeDto` in src-tauri/src/view.rs. `id: null` is the "No task" row (general time); a
// task's tracked time counts idle time too, unlike the app-usage breakdown.
export interface TaskTimeDto {
  id: string | null
  title: string
  trackedSeconds: number
}

/**
 * The signed-in person's own tasks (`tasks`, for a compact "current task" readout wherever that's all
 * that's needed) and today's tracked time per task (`times`, for the Tasks screen's list). `times` is not
 * polled by this composable itself -- it's read from two pages with different needs, so the page that
 * wants it live (pages/tasks.vue) owns the polling loop and just calls `refreshTimes()`.
 */
export function useTasks() {
  const tasks = ref<TaskDto[]>([])
  const times = ref<TaskTimeDto[]>([])
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

  async function refreshTimes() {
    try {
      times.value = await invoke<TaskTimeDto[]>('get_today_tasks')
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

  return { tasks, times, error, refresh, refreshTimes, select }
}
