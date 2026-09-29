<template>
  <main class="flex h-dvh flex-col gap-2.5 overflow-hidden p-3">
    <UiPageHeader
      title="Tasks"
      :subtitle="currentTitle ? `Working on: ${currentTitle}` : 'Pick what you are working on.'"
      back="/"
    />

    <p
      v-if="error"
      class="rounded-2xl bg-red-100 p-2.5 text-xs text-red-700 dark:bg-red-500/15 dark:text-red-300"
      role="alert"
    >
      {{ error }}
    </p>

    <ul class="min-h-0 flex-1 space-y-2 overflow-y-auto">
      <li
        v-for="row in liveRows"
        :key="row.id ?? 'none'"
      >
        <UiCard class="flex items-center justify-between gap-3">
          <div class="min-w-0">
            <p
              class="truncate text-sm font-medium"
              :class="isPicked(row) ? 'text-primary-600 dark:text-primary-400' : 'text-gray-900 dark:text-white'"
            >
              {{ row.title }}
            </p>
            <p class="font-mono text-xs tabular-nums text-gray-500 dark:text-gray-400">
              {{ formatClock(row.seconds) }}
            </p>
          </div>
          <UiButton
            :variant="isLive(row) ? 'danger' : 'primary'"
            class="shrink-0 px-4! py-1.5!"
            :disabled="busy"
            @click="isLive(row) ? doStop() : doPlay(row.id)"
          >
            {{ isLive(row) ? 'Stop' : 'Play' }}
          </UiButton>
        </UiCard>
      </li>
    </ul>
  </main>
</template>

<script setup lang="ts">
const { state, start, resume, stop, refresh: refreshTracking } = useTracking()
const { times, refreshTimes, select, error } = useTasks()

const busy = ref(false)
const fetchedAt = ref(Date.now())
const now = ref(Date.now())

function isPicked(row: { id: string | null }): boolean {
  return (state.value?.currentTaskId ?? null) === row.id
}

function isLive(row: { id: string | null }): boolean {
  return isPicked(row) && state.value?.state === 'tracking'
}

const currentTitle = computed(() => times.value.find(t => isPicked(t))?.title ?? null)

// the picked row's total ticks between fetches the same way the main screen's clock does
const liveRows = computed(() => times.value.map((t) => {
  const running = isLive(t) ? (now.value - fetchedAt.value) / 1000 : 0
  return { ...t, seconds: t.trackedSeconds + running }
}))

async function refreshAll() {
  await Promise.all([refreshTracking(), refreshTimes()])
  fetchedAt.value = Date.now()
  now.value = fetchedAt.value
}

/** Play on a row: pick it, then start (from stopped) or resume (from paused) -- the pick happens first, so
 * the very first session opened is already tagged, and is a no-op while not tracking either way. */
async function doPlay(taskId: string | null) {
  busy.value = true
  try {
    await select(taskId)
    if (state.value?.state === 'not_tracking')
      await start()
    else if (state.value?.state === 'paused')
      await resume()
    await refreshAll()
  }
  finally {
    busy.value = false
  }
}

/** Stop on the currently-live row: the same global stop as the main screen, not just clearing the task. */
async function doStop() {
  busy.value = true
  try {
    await stop()
    await refreshAll()
  }
  finally {
    busy.value = false
  }
}

let timer: ReturnType<typeof setInterval> | undefined
let ticker: ReturnType<typeof setInterval> | undefined
const visible = () => document.visibilityState === 'visible'

onMounted(async () => {
  await refreshAll()
  timer = setInterval(() => {
    if (visible())
      refreshAll()
  }, 1000)
  ticker = setInterval(() => {
    now.value = Date.now()
  }, 200)
})

onBeforeUnmount(() => {
  clearInterval(timer)
  clearInterval(ticker)
})
</script>
