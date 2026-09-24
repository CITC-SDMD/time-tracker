<script setup lang="ts">
const { state, clock, resumedNotice, error, start, pause, resume, stop, resetCounters } = useTracking()
const { me, refresh, logout } = useAuth()
const { status: sync, notice, progress, dismissNotice } = useSync()
const loginNotice = useState<string | null>('loginNotice', () => null)

const loggingOut = ref(false)

const syncLabel = computed(() => {
  const s = sync.value
  if (!s)
    return null
  if (s.needsLogin)
    return 'Please log in again'
  if (s.upgradeRequired)
    return 'Please update the app'
  if (!s.online)
    return s.pendingCount > 0 ? `Offline · ${s.pendingCount} waiting to send` : 'Offline'
  return s.pendingCount > 0 ? 'Sync pending' : 'All data sent'
})

// Best effort: picks up a raised consent version. The route guard then sends the person
// to the consent screen if needed.
onMounted(async () => {
  await refresh().catch(() => {})
  if (me.value?.consentRequired)
    await navigateTo('/consent')
})

async function signOut() {
  loggingOut.value = true
  try {
    const result = await logout()
    loginNotice.value = result.synced
      ? null
      : 'You\'re offline, your data will be sent next time you log in.'
    await navigateTo('/login')
  }
  finally {
    loggingOut.value = false
  }
}
</script>

<template>
  <main class="mx-auto max-w-md space-y-4 p-4">
    <header>
      <h1 class="text-lg font-semibold">
        Time Tracker
      </h1>
      <p class="text-sm text-slate-500">
        Closing this window hides it to the tray; tracking keeps running.
      </p>
      <p class="mt-1 flex items-center justify-between text-sm">
        <span>{{ me?.name }}</span>
        <button
          :disabled="loggingOut"
          class="text-slate-500 underline disabled:opacity-50"
          @click="signOut"
        >
          {{ loggingOut ? 'Sending your data…' : 'Log out' }}
        </button>
      </p>
      <p
        v-if="syncLabel"
        class="mt-1 text-xs text-slate-500"
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
        <p class="mt-1 text-xs text-slate-500">
          Sending {{ progress.sent.toLocaleString() }} of {{ progress.total.toLocaleString() }} ({{ progress.percent }}%)
        </p>
      </div>
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
      <h2 class="mb-2 text-sm font-medium text-slate-500">
        Status
      </h2>
      <p class="text-2xl font-semibold">
        {{ state?.state ?? '…' }}
      </p>

      <div class="mt-4 flex gap-2">
        <button
          v-if="state?.state === 'NOT_TRACKING'"
          class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white"
          @click="start"
        >
          Start
        </button>
        <button
          v-if="state?.state === 'TRACKING'"
          class="rounded bg-slate-200 px-3 py-1.5 text-sm"
          @click="pause"
        >
          Pause
        </button>
        <button
          v-if="state?.state === 'PAUSED'"
          class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white"
          @click="resume"
        >
          Resume
        </button>
        <button
          v-if="state?.state === 'TRACKING' || state?.state === 'PAUSED'"
          class="rounded bg-slate-200 px-3 py-1.5 text-sm"
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
          v-if="state?.state === 'NOT_TRACKING'"
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

    <NuxtLink
      to="/debug"
      class="block text-center text-sm text-slate-500 underline"
    >
      Debug: today's sessions
    </NuxtLink>
  </main>
</template>
