<script setup lang="ts">
import { ROLE_LABEL, type EmployeeListItem } from 'shared'

// Overview (docs/DEVELOPMENT_PLAN.md §12 Phase 6): who is working now, and today's totals,
// over the caller's own visible set only. Refreshes every 60 s while the tab is visible.
const { api } = useApi()
const { formatDuration, formatTime } = useFormat()

const employees = ref<EmployeeListItem[]>([])
const loaded = ref(false)
const error = ref<string | null>(null)

const STATUS_LABEL: Record<EmployeeListItem['status'], string> = {
  ACTIVE: 'Active',
  IDLE: 'Idle',
  PAUSED: 'Paused',
  AWAY: 'Away',
  NOT_TRACKING: 'Not tracking',
  OFFLINE: 'Offline',
}

const STATUS_DOT: Record<EmployeeListItem['status'], string> = {
  ACTIVE: 'bg-green-500',
  IDLE: 'bg-amber-500',
  PAUSED: 'bg-blue-500',
  AWAY: 'bg-slate-400',
  NOT_TRACKING: 'bg-slate-300',
  OFFLINE: 'bg-red-400',
}

function statusText(e: EmployeeListItem): string {
  return e.status === 'OFFLINE'
    ? `Offline (last seen ${formatTime(e.lastActivityAt)})`
    : STATUS_LABEL[e.status]
}

const cards = computed(() => {
  const list = employees.value
  const tracking = list.filter(e => e.status === 'ACTIVE').length
  const idle = list.filter(e => e.status === 'IDLE').length
  return { tracking, idle, notTracking: list.length - tracking - idle }
})

const totals = computed(() => ({
  tracked: employees.value.reduce((sum, e) => sum + e.trackedSeconds, 0),
  active: employees.value.reduce((sum, e) => sum + e.activeSeconds, 0),
  idle: employees.value.reduce((sum, e) => sum + e.idleSeconds, 0),
}))

async function load() {
  try {
    employees.value = await api<EmployeeListItem[]>('/employees')
    error.value = null
  }
  catch (e) {
    error.value = messageOf(e, 'Could not load the overview.')
  }
  finally {
    loaded.value = true
  }
}

let timer: ReturnType<typeof setInterval> | undefined

function onVisible() {
  if (document.visibilityState === 'visible')
    load()
}

onMounted(() => {
  load()
  timer = setInterval(() => {
    if (document.visibilityState === 'visible')
      load()
  }, 60_000)
  document.addEventListener('visibilitychange', onVisible)
})

onBeforeUnmount(() => {
  clearInterval(timer)
  document.removeEventListener('visibilitychange', onVisible)
})
</script>

<template>
  <main class="mx-auto max-w-6xl space-y-4 p-4">
    <header>
      <h1 class="text-lg font-semibold">
        Overview
      </h1>
      <p class="text-sm text-slate-500">
        Everyone you can see, updated every minute.
      </p>
    </header>

    <p
      v-if="error"
      class="text-sm text-red-600"
      role="alert"
    >
      {{ error }}
    </p>

    <section class="grid grid-cols-1 gap-3 sm:grid-cols-3">
      <div class="rounded-lg border border-slate-200 bg-white p-4">
        <p class="text-sm text-slate-500">
          Tracking now
        </p>
        <p
          class="text-3xl font-semibold text-green-600"
          data-testid="card-tracking"
        >
          {{ cards.tracking }}
        </p>
      </div>
      <div class="rounded-lg border border-slate-200 bg-white p-4">
        <p class="text-sm text-slate-500">
          Idle now
        </p>
        <p
          class="text-3xl font-semibold text-amber-600"
          data-testid="card-idle"
        >
          {{ cards.idle }}
        </p>
      </div>
      <div class="rounded-lg border border-slate-200 bg-white p-4">
        <p class="text-sm text-slate-500">
          Not tracking
        </p>
        <p
          class="text-3xl font-semibold text-slate-600"
          data-testid="card-not-tracking"
        >
          {{ cards.notTracking }}
        </p>
      </div>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-4">
      <h2 class="mb-2 text-sm font-medium text-slate-500">
        Today, everyone together
      </h2>
      <dl class="flex flex-wrap gap-x-8 gap-y-1 text-sm">
        <div class="flex gap-2">
          <dt class="text-slate-500">
            Tracked
          </dt>
          <dd class="font-medium tabular-nums">
            {{ formatDuration(totals.tracked) }}
          </dd>
        </div>
        <div class="flex gap-2">
          <dt class="text-slate-500">
            Active
          </dt>
          <dd class="font-medium tabular-nums">
            {{ formatDuration(totals.active) }}
          </dd>
        </div>
        <div class="flex gap-2">
          <dt class="text-slate-500">
            Idle
          </dt>
          <dd class="font-medium tabular-nums">
            {{ formatDuration(totals.idle) }}
          </dd>
        </div>
      </dl>
    </section>

    <section class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table class="w-full min-w-[52rem] text-left text-sm">
        <thead class="border-b border-slate-200 text-slate-500">
          <tr>
            <th class="px-3 py-2 font-medium">
              Name
            </th>
            <th class="px-3 py-2 font-medium">
              Role
            </th>
            <th class="px-3 py-2 font-medium">
              Status
            </th>
            <th class="px-3 py-2 text-right font-medium">
              Tracked
            </th>
            <th class="px-3 py-2 text-right font-medium">
              Active
            </th>
            <th class="px-3 py-2 text-right font-medium">
              Idle
            </th>
            <th class="px-3 py-2 font-medium">
              Current app
            </th>
            <th class="px-3 py-2 font-medium">
              Last activity
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="e in employees"
            :key="e.id"
            class="border-b border-slate-100 last:border-0"
          >
            <td class="px-3 py-2">
              <NuxtLink
                :to="`/employees/${e.id}`"
                class="font-medium underline"
              >
                {{ e.name }}
              </NuxtLink>
            </td>
            <td class="px-3 py-2 text-slate-500">
              {{ ROLE_LABEL[e.role] }}
            </td>
            <td class="px-3 py-2">
              <span class="inline-flex items-center gap-2">
                <span
                  class="inline-block h-2.5 w-2.5 rounded-full"
                  :class="STATUS_DOT[e.status]"
                />
                {{ statusText(e) }}
              </span>
            </td>
            <td class="px-3 py-2 text-right tabular-nums">
              {{ formatDuration(e.trackedSeconds) }}
            </td>
            <td class="px-3 py-2 text-right tabular-nums">
              {{ formatDuration(e.activeSeconds) }}
            </td>
            <td class="px-3 py-2 text-right tabular-nums">
              {{ formatDuration(e.idleSeconds) }}
            </td>
            <td class="px-3 py-2">
              {{ e.status === 'ACTIVE' ? (e.currentApp ?? '—') : '—' }}
            </td>
            <td class="px-3 py-2 text-slate-500">
              {{ formatTime(e.lastActivityAt) }}
            </td>
          </tr>
          <tr v-if="loaded && !employees.length">
            <td
              colspan="8"
              class="px-3 py-4 text-center text-slate-500"
            >
              Nobody to show yet.
            </td>
          </tr>
        </tbody>
      </table>
    </section>
  </main>
</template>
