<script setup lang="ts">
// Phase 0 spike screen: shows what the Rust layer detects, live.
const { activity, events, logPath, error } = useActivity()

function formatTime(iso: string) {
  return new Date(iso).toLocaleTimeString()
}
</script>

<template>
  <main class="mx-auto max-w-md space-y-4 p-4">
    <header>
      <h1 class="text-lg font-semibold">
        Time Tracker — Phase 0 spike
      </h1>
      <p class="text-sm text-slate-500">
        Live readout from the Rust layer. Closing this window hides it to the tray.
      </p>
    </header>

    <section class="rounded-lg border border-slate-200 bg-white p-4">
      <h2 class="mb-2 text-sm font-medium text-slate-500">
        Current activity
      </h2>
      <p
        v-if="error"
        class="text-sm text-red-600"
      >
        {{ error }}
      </p>
      <dl
        v-else-if="activity"
        class="grid grid-cols-[7rem_1fr] gap-y-1 text-sm"
      >
        <dt class="text-slate-500">
          Application
        </dt>
        <dd class="font-medium">
          {{ activity.application ?? '—' }}
        </dd>
        <dt class="text-slate-500">
          Process
        </dt>
        <dd>{{ activity.processName || '—' }}</dd>
        <dt class="text-slate-500">
          Window title
        </dt>
        <dd class="break-words">
          {{ activity.windowTitle || '—' }}
        </dd>
        <dt class="text-slate-500">
          Idle
        </dt>
        <dd>{{ activity.idleSeconds }} s</dd>
      </dl>
      <p
        v-else
        class="text-sm text-slate-500"
      >
        Loading…
      </p>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-4">
      <h2 class="mb-2 text-sm font-medium text-slate-500">
        System events (lock, sleep, …)
      </h2>
      <ul
        v-if="events.length"
        class="space-y-1 text-sm"
      >
        <li
          v-for="(e, i) in events"
          :key="i"
          class="flex justify-between"
        >
          <span class="font-medium">{{ e.kind }}</span>
          <span class="text-slate-500">{{ formatTime(e.at) }}</span>
        </li>
      </ul>
      <p
        v-else
        class="text-sm text-slate-500"
      >
        None yet. Try Win+L, or sleep the PC.
      </p>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-4">
      <h2 class="mb-1 text-sm font-medium text-slate-500">
        Background log file
      </h2>
      <p class="break-all font-mono text-xs">
        {{ logPath || '…' }}
      </p>
    </section>
  </main>
</template>
