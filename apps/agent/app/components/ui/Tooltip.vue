<template>
  <div
    class="group relative shrink-0 rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500"
    tabindex="0"
    :aria-describedby="id"
  >
    <slot />
    <span
      :id="id"
      role="tooltip"
      class="pointer-events-none invisible absolute left-0 top-full z-20 mt-2 w-56 rounded-2xl bg-gray-900 p-3 text-xs leading-snug text-white opacity-0 shadow-xl transition-opacity group-hover:visible group-hover:opacity-100 group-focus:visible group-focus:opacity-100 dark:bg-gray-100 dark:text-gray-900"
    >
      {{ text }}
    </span>
  </div>
</template>

<script setup lang="ts">
// Wraps something and explains it in a small bubble on hover or keyboard focus.
defineProps<{ text: string }>()

const id = useId()
</script>
