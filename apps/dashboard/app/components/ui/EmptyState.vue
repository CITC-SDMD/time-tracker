<template>
  <div class="px-4 py-10 text-center">
    <p class="text-sm/6 font-semibold text-gray-900 dark:text-white">
      {{ title }}
    </p>
    <p
      v-if="description"
      class="mt-1 text-sm/6 text-gray-500 dark:text-gray-400"
    >
      {{ description }}
    </p>
    <div
      v-if="$slots.default"
      class="mt-4"
    >
      <slot />
    </div>
  </div>
</template>

<script setup lang="ts">
// What a list or table shows when there is nothing to list ("No one matches your filters").
defineProps<{
  title: string
  description?: string
}>()
</script>
