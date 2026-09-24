<script setup lang="ts">
import { invoke } from '@tauri-apps/api/core'
import type { SessionDto } from '~/composables/useTracking'

// Phase 3 task 12: a raw list of today's local sessions, for debugging the engine.
// Deliberately not merged into display-ready timeline blocks -- see the comment on
// get_today_timeline in src-tauri/src/commands.rs.
const sessions = ref<SessionDto[]>([])
const error = ref<string | null>(null)

async function refresh() {
  try {
    sessions.value = await invoke<SessionDto[]>('get_today_sessions_debug')
    error.value = null
  }
  catch (e) {
    error.value = String(e)
  }
}

function formatTime(ms: number | null) {
  return ms === null ? '—' : new Date(ms).toLocaleTimeString()
}

let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  refresh()
  timer = setInterval(refresh, 2000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <main class="mx-auto max-w-2xl space-y-4 p-4">
    <header class="flex items-center justify-between">
      <h1 class="text-lg font-semibold">
        Debug: today's sessions
      </h1>
      <NuxtLink
        to="/"
        class="text-sm text-slate-500 underline"
      >
        Back
      </NuxtLink>
    </header>

    <p
      v-if="error"
      class="text-sm text-red-600"
    >
      {{ error }}
    </p>

    <table class="w-full text-left text-sm">
      <thead>
        <tr class="border-b border-slate-200 text-slate-500">
          <th class="py-1 pr-2">
            Type
          </th>
          <th class="py-1 pr-2">
            App
          </th>
          <th class="py-1 pr-2">
            Start
          </th>
          <th class="py-1 pr-2">
            End
          </th>
          <th class="py-1 pr-2">
            Duration
          </th>
          <th class="py-1">
            Sync
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="s in sessions"
          :key="s.id"
          class="border-b border-slate-100"
        >
          <td class="py-1 pr-2">
            {{ s.sessionType }}
          </td>
          <td class="py-1 pr-2">
            {{ s.appName ?? s.idleAppName ?? '—' }}
          </td>
          <td class="py-1 pr-2">
            {{ formatTime(s.startedAt) }}
          </td>
          <td class="py-1 pr-2">
            {{ formatTime(s.endedAt) }}
          </td>
          <td class="py-1 pr-2">
            {{ s.durationSeconds ?? '—' }}
          </td>
          <td class="py-1">
            {{ s.syncStatus }}
          </td>
        </tr>
      </tbody>
    </table>
    <p
      v-if="!sessions.length"
      class="text-sm text-slate-500"
    >
      No sessions yet today.
    </p>
  </main>
</template>
