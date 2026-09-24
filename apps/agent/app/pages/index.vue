<script setup lang="ts">
// Phase 3: drives the local tracking engine directly, since there's no login screen
// yet (Phase 2 desktop-side work) to gate this behind.
const { state, summary, resumedNotice, error, start, pause, resume, stop } = useTracking()

function formatSeconds(seconds: number | undefined) {
  if (seconds === undefined)
    return '—'
  const m = Math.floor(seconds / 60)
  const s = seconds % 60
  return `${m}m ${s}s`
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
    </header>

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
      <h2 class="mb-2 text-sm font-medium text-slate-500">
        Today
      </h2>
      <dl class="grid grid-cols-[7rem_1fr] gap-y-1 text-sm">
        <dt class="text-slate-500">
          Tracked
        </dt>
        <dd>{{ formatSeconds(summary?.trackedSeconds) }}</dd>
        <dt class="text-slate-500">
          Active
        </dt>
        <dd>{{ formatSeconds(summary?.activeSeconds) }}</dd>
        <dt class="text-slate-500">
          Idle
        </dt>
        <dd>{{ formatSeconds(summary?.idleSeconds) }}</dd>
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
