<template>
  <main class="flex h-dvh flex-col gap-2.5 overflow-hidden p-3">
    <UiPageHeader
      title="Today's sessions"
      subtitle="A developer view of the local sessions."
      back="/settings"
    />

    <p
      v-if="error"
      class="rounded-2xl bg-red-100 p-2.5 text-xs text-red-700 dark:bg-red-500/15 dark:text-red-300"
    >
      {{ error }}
    </p>

    <UiCard v-if="sessions.length">
      <table class="w-full table-fixed text-left text-xs">
        <thead>
          <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
            <th class="w-[34%] py-1 pr-2 font-medium">
              App
            </th>
            <th class="w-[17%] py-1 pr-2 font-medium">
              Start
            </th>
            <th class="w-[17%] py-1 pr-2 font-medium">
              End
            </th>
            <th class="w-[14%] py-1 pr-2 font-medium">
              Secs
            </th>
            <th class="w-[18%] py-1 font-medium">
              Sync
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="s in pageItems"
            :key="s.id"
            class="border-b border-gray-100 last:border-0 dark:border-white/5"
          >
            <td
              class="truncate py-1.5 pr-2"
              :title="s.appName ?? s.idleAppName ?? ''"
            >
              {{ s.appName ?? s.idleAppName ?? '—' }}
            </td>
            <td class="py-1.5 pr-2 tabular-nums">
              {{ formatTime(s.startedAt) }}
            </td>
            <td class="py-1.5 pr-2 tabular-nums">
              {{ formatTime(s.endedAt) }}
            </td>
            <td class="py-1.5 pr-2 tabular-nums">
              {{ s.durationSeconds ?? '—' }}
            </td>
            <td class="truncate py-1.5">
              {{ s.syncStatus }}
            </td>
          </tr>
        </tbody>
      </table>
    </UiCard>
    <UiCard v-else>
      <p class="text-sm text-gray-500 dark:text-gray-400">
        No sessions yet today.
      </p>
    </UiCard>

    <UiPager
      v-if="pageCount > 1"
      class="mt-auto"
      :label="`${page + 1} of ${pageCount}`"
      :has-previous="page > 0"
      :has-next="page < pageCount - 1"
      @previous="page--"
      @next="page++"
    />
  </main>
</template>

<script setup lang="ts">
import { invoke } from '@tauri-apps/api/core'
import type { SessionDto } from '~/composables/useTracking'

// Phase 3 task 12: a raw list of today's local sessions, for debugging the engine.
// Deliberately not merged into display-ready timeline blocks -- see the comment on
// get_today_timeline in src-tauri/src/commands.rs. Shown a page at a time, newest first, so it never scrolls.
const sessions = ref<SessionDto[]>([])
const error = ref<string | null>(null)

const PER_PAGE = 14
const page = ref(0)
const pageCount = computed(() => Math.max(1, Math.ceil(sessions.value.length / PER_PAGE)))
const pageItems = computed(() => sessions.value.slice(page.value * PER_PAGE, (page.value + 1) * PER_PAGE))

async function refresh() {
  try {
    sessions.value = (await invoke<SessionDto[]>('get_today_sessions_debug')).reverse()
    error.value = null
    if (page.value >= pageCount.value)
      page.value = pageCount.value - 1
  }
  catch (e) {
    error.value = String(e)
  }
}

function formatTime(ms: number | null) {
  return ms === null ? '—' : new Date(ms).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false })
}

let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  refresh()
  timer = setInterval(refresh, 2000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>
