<template>
  <section class="rounded-2xl border border-gray-200/70 bg-white/90 p-3.5 shadow-sm shadow-gray-900/5 dark:border-white/10 dark:bg-gray-900/80 dark:shadow-none">
    <header
      v-if="title || $slots.action"
      class="mb-2 flex items-center justify-between gap-2"
    >
      <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400">
        {{ title }}
      </h2>
      <slot name="action" />
    </header>
    <slot />
  </section>
</template>

<script setup lang="ts">
// The rounded white card every screen is built from.
defineProps<{ title?: string }>()
</script>
