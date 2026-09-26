<template>
  <main class="flex h-dvh flex-col gap-2.5 overflow-hidden p-3">
    <UiPageHeader
      title="My screenshots"
      subtitle="What was captured of your main screen today."
      back="/"
    />

    <p
      v-if="status && !status.enabled"
      class="rounded-2xl bg-gray-100 p-2.5 text-xs text-gray-600 dark:bg-white/10 dark:text-gray-300"
    >
      Screenshots are switched off by your organization. Pictures taken earlier are still listed below.
    </p>
    <p
      v-else-if="status"
      class="rounded-2xl bg-gray-100 p-2.5 text-xs text-gray-600 dark:bg-white/10 dark:text-gray-300"
    >
      A picture is taken about every {{ status.intervalMinutes }} minutes while you are tracking<span v-if="status.random">, at a random moment</span>.
      <span v-if="status.waiting > 0">{{ status.waiting }} waiting to be sent.</span>
    </p>

    <p
      v-if="error"
      class="rounded-2xl bg-red-100 p-2.5 text-xs text-red-700 dark:bg-red-500/15 dark:text-red-300"
      role="alert"
    >
      {{ error }}
    </p>
    <UiCard v-else-if="loaded && items.length === 0">
      <p class="text-sm text-gray-500 dark:text-gray-400">
        Nothing yet today. Pictures appear here a few minutes after they are taken.
      </p>
    </UiCard>

    <ul
      v-if="items.length"
      class="grid grid-cols-2 gap-3"
      aria-label="Your screenshots today"
    >
      <li
        v-for="(item, index) in pageItems"
        :key="item.id"
      >
        <ScreenshotThumb
          :id="item.id"
          :time="timeOf(item)"
          @open="viewing = pageStart + index"
        />
      </li>
    </ul>

    <UiPager
      v-if="pageCount > 1"
      class="mt-auto"
      :label="`${page + 1} of ${pageCount}`"
      :has-previous="page > 0"
      :has-next="page < pageCount - 1"
      @previous="page--"
      @next="page++"
    />

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

const timezone = computed(() => me.value?.settings.timezone ?? 'UTC')
const items = ref<ScreenshotItem[]>([])
const loaded = ref(false)
const error = ref<string | null>(null)
const viewing = ref<number | null>(null)

// six pictures a page (two columns, three rows), so the screen never scrolls
const PER_PAGE = 6
const page = ref(0)
const pageCount = computed(() => Math.max(1, Math.ceil(items.value.length / PER_PAGE)))
const pageStart = computed(() => page.value * PER_PAGE)
const pageItems = computed(() => items.value.slice(pageStart.value, pageStart.value + PER_PAGE))

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
  if (next >= 0 && next < items.value.length) {
    viewing.value = next
    // the page follows the picture being looked at
    page.value = Math.floor(next / PER_PAGE)
  }
}

async function load() {
  try {
    // newest first
    items.value = (await invoke<ScreenshotItem[]>('list_my_screenshots', { day: dayIn(timezone.value) })).reverse()
    error.value = null
    if (page.value >= pageCount.value)
      page.value = pageCount.value - 1
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
