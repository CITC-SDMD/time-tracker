<template>
  <NuxtLink
    :to="to"
    class="group flex gap-x-3 rounded-md p-2 text-sm/6 font-medium"
    :class="current ? 'bg-gray-100 text-primary-600 dark:bg-white/5 dark:text-primary-400' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/5'"
    :aria-current="current ? 'page' : undefined"
  >
    <slot />
  </NuxtLink>
</template>

<script setup lang="ts">
// One item in the sidebar. `current` highlights the page the person is on.
defineProps<{
  to: string
  current?: boolean
}>()
</script>
