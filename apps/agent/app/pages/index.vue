<template>
  <main class="mx-auto max-w-md space-y-3 p-4">
    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-lg font-semibold">
          Time Tracker
        </h1>
        <p class="text-sm text-slate-500">
          {{ me?.name }}
        </p>
      </div>
      <NuxtLink
        to="/settings"
        class="text-sm text-slate-500 underline"
      >
        Settings
      </NuxtLink>
    </header>

    <p
      v-if="notice"
      class="flex items-start justify-between gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-700"
    >
      <span>{{ notice }}</span>
      <button
        class="shrink-0 underline"
        @click="dismissNotice"
      >
        Dismiss
      </button>
    </p>
    <p
      v-if="resumedNotice"
      class="rounded-lg bg-amber-50 p-3 text-sm text-amber-700"
    >
      Tracking resumed after the app restarted.
    </p>
    <p
      v-if="error"
      class="text-sm text-red-600"
    >
      {{ error }}
    </p>

    <section class="rounded-lg border border-slate-200 bg-white p-4">
      <div class="flex items-center gap-3">
        <span
          class="inline-block h-4 w-4 rounded-full"
          :class="STATUS_STYLE[status]"
        />
        <p
          class="text-2xl font-semibold"
          data-testid="status"
        >
          {{ STATUS_LABEL[status] }}
        </p>
        <p
          v-if="workClock"
          class="ml-auto text-right"
        >
          <span class="block font-mono text-lg tabular-nums">{{ workClock }}</span>
          <span class="block text-xs text-slate-500">this work period</span>
        </p>
      </div>

      <p
        v-if="summary?.currentApp && status === 'active'"
        class="mt-3 truncate text-sm"
        :title="summary.currentTitle ?? summary.currentApp"
      >
        <span class="font-medium">{{ summary.currentApp }}</span>
        <span
          v-if="summary.currentTitle"
          class="text-slate-500"
        > · {{ summary.currentTitle }}</span>
      </p>
      <p
        v-else-if="status === 'idle'"
        class="mt-3 text-sm text-slate-500"
      >
        No keyboard or mouse use.
      </p>

      <div class="mt-4 flex gap-2">
        <button
          v-if="state?.state === 'not_tracking'"
          class="rounded bg-slate-900 px-4 py-2 text-sm text-white"
          @click="start"
        >
          Start
        </button>
        <button
          v-if="state?.state === 'tracking'"
          class="rounded bg-slate-200 px-4 py-2 text-sm"
          @click="pause"
        >
          Pause
        </button>
        <button
          v-if="state?.state === 'paused'"
          class="rounded bg-slate-900 px-4 py-2 text-sm text-white"
          @click="resume"
        >
          Resume
        </button>
        <button
          v-if="state?.state === 'tracking' || state?.state === 'paused'"
          class="rounded bg-slate-200 px-4 py-2 text-sm"
          @click="stop"
        >
          Stop
        </button>
      </div>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-4">
      <h2 class="mb-2 flex items-center justify-between text-sm font-medium text-slate-500">
        <span>Today</span>
        <button
          v-if="isDev && state?.state === 'not_tracking'"
          class="text-xs font-normal underline"
          title="Sets these counters back to 00:00:00. Nothing is deleted."
          @click="resetCounters"
        >
          Reset
        </button>
      </h2>
      <dl class="grid grid-cols-[7rem_1fr] gap-y-1 text-sm">
        <dt class="text-slate-500">
          Tracked
        </dt>
        <dd class="font-mono tabular-nums">
          {{ clock?.tracked ?? '--:--:--' }}
        </dd>
        <dt class="text-slate-500">
          Active
        </dt>
        <dd class="font-mono tabular-nums">
          {{ clock?.active ?? '--:--:--' }}
        </dd>
        <dt class="text-slate-500">
          Idle
        </dt>
        <dd class="font-mono tabular-nums">
          {{ clock?.idle ?? '--:--:--' }}
        </dd>
      </dl>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-4">
      <h2 class="mb-2 text-sm font-medium text-slate-500">
        Apps today
      </h2>
      <p
        v-if="!summary?.apps.length"
        class="text-sm text-slate-500"
      >
        No apps recorded yet.
      </p>
      <ul
        v-else
        class="max-h-40 space-y-1 overflow-y-auto text-sm"
      >
        <li
          v-for="app in summary.apps"
          :key="app.name"
          class="flex justify-between gap-2"
        >
          <span class="truncate">{{ app.name }}</span>
          <span class="shrink-0 tabular-nums text-slate-500">{{ formatDuration(app.seconds) }}</span>
        </li>
      </ul>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-4">
      <h2 class="mb-2 text-sm font-medium text-slate-500">
        Timeline
      </h2>
      <TimelineBar :segments="timeline" />
    </section>

    <footer>
      <p
        v-if="syncLabel"
        class="text-center text-xs text-slate-500"
      >
        {{ syncLabel }}
      </p>
      <div
        v-if="progress && sync?.online"
        class="mt-2"
      >
        <div
          class="h-2 overflow-hidden rounded bg-slate-200"
          role="progressbar"
          :aria-valuenow="progress.percent"
          aria-valuemin="0"
          aria-valuemax="100"
        >
          <div
            class="h-full bg-slate-700 transition-all duration-500"
            :style="{ width: `${progress.percent}%` }"
          />
        </div>
        <p class="mt-1 text-center text-xs text-slate-500">
          Sending {{ progress.sent.toLocaleString() }} of {{ progress.total.toLocaleString() }} ({{ progress.percent }}%)
        </p>
      </div>
    </footer>
  </main>
</template>

<script setup lang="ts">
const { state, summary, timeline, status, clock, workClock, resumedNotice, error, start, pause, resume, stop, resetCounters } = useTracking()
const { me, refresh } = useAuth()
const { status: sync, notice, progress, dismissNotice } = useSync()

// The counter Reset is a testing aid: only dev builds show it.
const isDev = import.meta.dev

const STATUS_STYLE: Record<StatusKey, string> = {
  active: 'bg-green-500',
  idle: 'bg-amber-500',
  paused: 'bg-blue-500',
  away: 'bg-slate-400',
  not_tracking: 'bg-slate-400',
}

const syncLabel = computed(() => {
  const s = sync.value
  if (!s)
    return null
  if (s.needsLogin)
    return 'Please log in again'
  if (s.upgradeRequired)
    return 'Please update the app'
  if (!s.online)
    return s.pendingCount > 0 ? `Offline · ${s.pendingCount.toLocaleString()} waiting to send` : 'Offline'
  return s.pendingCount > 0 ? `${s.pendingCount.toLocaleString()} ${s.pendingCount === 1 ? 'session' : 'sessions'} waiting to send` : 'All data sent'
})

// Best effort: picks up a raised consent version. The route guard then sends the person
// to the consent screen if needed.
onMounted(async () => {
  await refresh().catch(() => {})
  if (me.value?.consentRequired)
    await navigateTo('/consent')
})
</script>
