<template>
  <UiCard>
    <p class="text-sm/6 font-medium text-gray-500 dark:text-gray-400">
      {{ label }}
    </p>
    <p class="mt-1 text-3xl/9 font-semibold tracking-tight text-gray-900 dark:text-white">
      {{ value }}
    </p>
    <p
      v-if="hint"
      class="mt-1 text-xs/5 text-gray-500 dark:text-gray-400"
    >
      {{ hint }}
    </p>
  </UiCard>
</template>

<script setup lang="ts">
// One number with a caption, for the summary row at the top of a page.
defineProps<{
  label: string
  value: string | number
  hint?: string
}>()
</script>
