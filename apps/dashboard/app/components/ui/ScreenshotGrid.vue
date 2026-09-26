<template>
  <ul
    class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6"
    aria-label="Screenshots of the day"
  >
    <li
      v-for="(item, index) in items"
      :key="item.id"
    >
      <FormButton
        variant="plain"
        class="group block w-full rounded-lg text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500"
        :aria-label="`Open the screenshot of ${name} at ${formatTime(item.takenAt)}`"
        @click="$emit('open', index)"
      >
        <img
          :src="url(`/screenshots/${item.id}/thumb`)"
          :alt="`Screen of ${name} at ${formatTime(item.takenAt)}`"
          :width="320"
          :height="Math.round(320 * item.height / item.width)"
          loading="lazy"
          class="aspect-video w-full rounded-lg bg-gray-100 object-cover ring-1 ring-gray-950/10 group-hover:ring-primary-500 dark:bg-white/5 dark:ring-white/10"
        >
        <span class="mt-1 block text-xs/5 tabular-nums text-gray-500 dark:text-gray-400">{{ formatTime(item.takenAt) }}</span>
      </FormButton>
    </li>
  </ul>
</template>

<script setup lang="ts">
import type { ScreenshotItem } from 'shared'

// The day's screenshots as a grid of thumbnails, oldest first. A thumbnail comes from the API on
// the same address as the page, so the signed-in session authorises it. Choosing one says which.
defineProps<{
  items: ScreenshotItem[]
  /** whose screen it is, for the picture descriptions */
  name: string
}>()

defineEmits<{ open: [index: number] }>()

const { formatTime } = useFormat()
// inside an opened office the pictures come through that office's address (useApi)
const { url } = useApi()
</script>
