<template>
  <header class="mb-8 flex flex-wrap items-start justify-between gap-4">
    <div class="min-w-0">
      <h1 class="text-xl/8 font-semibold text-gray-900 dark:text-white">
        {{ title }}
      </h1>
      <p
        v-if="description"
        class="mt-1 text-sm/6 text-gray-500 dark:text-gray-400"
      >
        {{ description }}
      </p>
      <slot name="below" />
    </div>
    <div
      v-if="$slots.actions"
      class="flex shrink-0 items-center gap-3"
    >
      <slot name="actions" />
    </div>
  </header>
</template>

<script setup lang="ts">
// The title block at the top of every page: a heading, an optional line under it, and the page's
// main buttons on the right (`actions` slot).
defineProps<{
  title: string
  description?: string
}>()
</script>
