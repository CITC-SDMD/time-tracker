<template>
  <div
    class="relative shrink-0"
    :style="{ width: `${size}px`, height: `${size}px` }"
  >
    <svg
      :width="size"
      :height="size"
      viewBox="0 0 120 120"
      class="-rotate-90"
      aria-hidden="true"
    >
      <circle
        cx="60"
        cy="60"
        :r="RADIUS"
        fill="none"
        stroke-width="9"
        class="stroke-gray-200 dark:stroke-white/10"
      />
      <circle
        cx="60"
        cy="60"
        :r="RADIUS"
        fill="none"
        stroke-width="9"
        stroke-linecap="round"
        class="stroke-primary-500 transition-[stroke-dashoffset] duration-700"
        :stroke-dasharray="CIRCUMFERENCE"
        :stroke-dashoffset="offset"
      />
    </svg>
    <div class="absolute inset-0 flex flex-col items-center justify-center">
      <slot />
    </div>
  </div>
</template>

<script setup lang="ts">
// A circular progress ring with room for text in the middle. `percent` is 0 to 100.
const props = withDefaults(defineProps<{ percent: number, size?: number }>(), { size: 120 })

const RADIUS = 52
const CIRCUMFERENCE = 2 * Math.PI * RADIUS

const offset = computed(() => CIRCUMFERENCE * (1 - Math.min(100, Math.max(0, props.percent)) / 100))
</script>
