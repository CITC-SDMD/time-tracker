<template>
  <main class="mx-auto max-w-md space-y-3 p-4">
    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-lg font-semibold">
          My screenshots
        </h1>
        <p class="text-sm text-slate-500">
          What was captured of your main screen today.
        </p>
      </div>
      <NuxtLink
        to="/"
        class="text-sm text-slate-500 underline"
      >
        Back
      </NuxtLink>
    </header>

    <p
      v-if="status && !status.enabled"
      class="rounded-lg bg-slate-50 p-3 text-sm text-slate-600"
    >
      Screenshots are switched off by your office. Pictures taken earlier are still listed below.
    </p>
    <p
      v-else-if="status"
      class="text-sm text-slate-500"
    >
      A picture is taken about every {{ status.intervalMinutes }} minutes while you are tracking<span v-if="status.random">, at a random moment</span>.
      <span v-if="status.waiting > 0">{{ status.waiting }} waiting to be sent.</span>
    </p>

    <p
      v-if="error"
      class="text-sm text-red-600"
      role="alert"
    >
      {{ error }}
    </p>
    <p
      v-else-if="loaded && items.length === 0"
      class="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-500"
    >
      Nothing yet today. Pictures appear here a few minutes after they are taken.
    </p>

    <ul
      v-if="items.length"
      class="grid grid-cols-2 gap-3"
      aria-label="Your screenshots today"
    >
      <li
        v-for="(item, index) in items"
        :key="item.id"
      >
        <ScreenshotThumb
          :id="item.id"
          :time="timeOf(item)"
          @open="viewing = index"
        />
      </li>
    </ul>

    <ImageModal
      :id="currentId"
      :open="viewing !== null"
      :title="currentTitle"
      :has-previous="(viewing ?? 0) > 0"
      :has-next="viewing !== null && viewing < items.length - 1"
      @close="viewing = null"
      @step="step"
    />
  </main>
</template>

<script setup lang="ts">
import { invoke } from '@tauri-apps/api/core'

const { me } = useAuth()
const { status, refresh } = useScreenshots()

const timezone = computed(() => me.value?.officeSettings.timezone ?? 'UTC')
const items = ref<ScreenshotItem[]>([])
const loaded = ref(false)
const error = ref<string | null>(null)
const viewing = ref<number | null>(null)

function timeOf(item: ScreenshotItem): string {
  return clockTime(item.takenAt, timezone.value)
}

const current = computed(() => (viewing.value === null ? null : (items.value[viewing.value] ?? null)))
const currentId = computed(() => current.value?.id ?? null)
const currentTitle = computed(() => current.value ? `Your screen at ${timeOf(current.value)}` : '')

function step(by: number) {
  if (viewing.value === null)
    return
  const next = viewing.value + by
  if (next >= 0 && next < items.value.length)
    viewing.value = next
}

async function load() {
  try {
    items.value = await invoke<ScreenshotItem[]>('list_my_screenshots', { day: dayIn(timezone.value) })
    error.value = null
  }
  catch (e) {
    error.value = String(e) === 'offline' ? 'You are offline. Your pictures will show here when you are back online.' : 'Could not load your screenshots.'
  }
  finally {
    loaded.value = true
  }
}

let refreshTimer: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  load()
  refreshTimer = setInterval(() => {
    refresh()
    load()
  }, 60_000)
})
onBeforeUnmount(() => clearInterval(refreshTimer))
</script>
