<template>
  <nav
    class="flex items-center justify-between gap-2"
    aria-label="Pages"
  >
    <UiButton
      :disabled="!hasPrevious"
      @click="$emit('previous')"
    >
      Previous
    </UiButton>
    <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ label }}</span>
    <UiButton
      :disabled="!hasNext"
      @click="$emit('next')"
    >
      Next
    </UiButton>
  </nav>
</template>

<script setup lang="ts">
// Previous / "1 of 3" / Next, for lists that are shown a page at a time so nothing scrolls.
defineProps<{ label: string, hasPrevious: boolean, hasNext: boolean }>()

defineEmits<{ previous: [], next: [] }>()
</script>
