<template>
  <header class="flex items-center justify-between gap-2">
    <div class="flex min-w-0 items-center gap-2">
      <UiButton
        v-if="back"
        :to="back"
        variant="soft"
        class="px-3! py-1.5!"
        aria-label="Back"
      >
        ←
      </UiButton>
      <div class="min-w-0">
        <h1 class="truncate text-lg font-semibold leading-tight">
          {{ title }}
        </h1>
        <p
          v-if="subtitle"
          class="truncate text-xs text-gray-500 dark:text-gray-400"
        >
          {{ subtitle }}
        </p>
      </div>
    </div>
    <div class="flex shrink-0 items-center gap-2">
      <slot />
    </div>
  </header>
</template>

<script setup lang="ts">
// Title row at the top of a screen: an optional back button, the title, and actions on the right.
defineProps<{ title: string, subtitle?: string, back?: string }>()
</script>
