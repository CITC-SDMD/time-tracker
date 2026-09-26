<template>
  <span
    class="inline-flex shrink-0 items-center justify-center rounded-2xl bg-primary-500 text-gray-950 shadow-lg shadow-primary-500/30"
    :class="small ? 'h-9 w-9 rounded-xl' : 'h-14 w-14'"
    aria-hidden="true"
  >
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="2.2"
      stroke-linecap="round"
      stroke-linejoin="round"
      :class="small ? 'h-5 w-5' : 'h-7 w-7'"
    >
      <circle
        cx="12"
        cy="12"
        r="9"
      />
      <path d="M12 7v5l3 2" />
    </svg>
  </span>
</template>

<script setup lang="ts">
// The app's mark: a clock in an amber rounded square.
defineProps<{ small?: boolean }>()
</script>
