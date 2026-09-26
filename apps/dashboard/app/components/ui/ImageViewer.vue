<template>
  <UiModal
    v-model="open"
    :title="title"
    wide
  >
    <div v-if="current">
      <div class="relative flex min-h-48 items-center justify-center rounded-lg bg-gray-100 dark:bg-white/5">
        <UiSpinner v-if="loading && !failed" />
        <p
          v-if="failed"
          class="p-6 text-sm/6 text-gray-500 dark:text-gray-400"
        >
          This picture could not be loaded.
        </p>
        <img
          :key="current.id"
          :src="`/api/v1/screenshots/${current.id}/image`"
          :alt="`Screen of ${name} at ${formatTime(current.takenAt)}`"
          class="max-h-[70vh] w-full rounded-lg object-contain"
          :class="loading || failed ? 'absolute opacity-0' : ''"
          @load="loading = false"
          @error="failed = true; loading = false"
        >
      </div>
    </div>
    <template #footer>
      <p class="mr-auto self-center text-sm/6 tabular-nums text-gray-500 dark:text-gray-400">
        {{ (index ?? 0) + 1 }} of {{ items.length }}
      </p>
      <FormButton
        variant="secondary"
        :disabled="!hasPrevious"
        @click="step(-1)"
      >
        Previous
      </FormButton>
      <FormButton
        variant="secondary"
        :disabled="!hasNext"
        @click="step(1)"
      >
        Next
      </FormButton>
      <FormButton @click="open = false">
        Close
      </FormButton>
    </template>
  </UiModal>
</template>

<script setup lang="ts">
import type { ScreenshotItem } from 'shared'

// One screenshot at full size, over the page. `index` says which of `items` is shown, or `null`
// for closed. Left and right arrows (or the buttons) move through the day; Escape closes it and
// focus goes back to the thumbnail that opened it.
const props = defineProps<{
  items: ScreenshotItem[]
  /** whose screen it is */
  name: string
}>()

const index = defineModel<number | null>({ default: null })

const { formatTime, formatDateTime } = useFormat()

const open = computed({
  get: () => index.value !== null,
  set: (value: boolean) => {
    if (!value)
      index.value = null
  },
})

const current = computed(() => (index.value === null ? null : (props.items[index.value] ?? null)))
const title = computed(() => current.value ? `${props.name}, ${formatDateTime(current.value.takenAt)}` : props.name)
const hasPrevious = computed(() => (index.value ?? 0) > 0)
const hasNext = computed(() => index.value !== null && index.value < props.items.length - 1)

const loading = ref(true)
const failed = ref(false)

watch(current, () => {
  loading.value = true
  failed.value = false
})

function step(by: number) {
  if (index.value === null)
    return
  const next = index.value + by
  if (next >= 0 && next < props.items.length)
    index.value = next
}

// arrow keys work wherever the focus is inside the open window
function onKey(event: KeyboardEvent) {
  if (index.value === null)
    return
  if (event.key === 'ArrowLeft')
    step(-1)
  else if (event.key === 'ArrowRight')
    step(1)
}

onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>
