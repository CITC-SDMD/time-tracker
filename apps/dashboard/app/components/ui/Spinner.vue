<template>
  <div
    class="flex items-center justify-center gap-2 py-8 text-sm/6 text-gray-500 dark:text-gray-400"
    role="status"
  >
    <svg
      class="size-5 animate-spin text-primary-500"
      viewBox="0 0 24 24"
      fill="none"
      aria-hidden="true"
    >
      <circle
        class="opacity-25"
        cx="12"
        cy="12"
        r="10"
        stroke="currentColor"
        stroke-width="4"
      />
      <path
        class="opacity-75"
        fill="currentColor"
        d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"
      />
    </svg>
    <span>{{ label }}</span>
  </div>
</template>

<script setup lang="ts">
// "Loading…" shown while a page or a table waits for the server.
withDefaults(defineProps<{
  label?: string
}>(), {
  label: 'Loading…',
})
</script>
