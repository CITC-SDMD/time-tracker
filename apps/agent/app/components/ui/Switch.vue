<template>
  <button
    type="button"
    role="switch"
    :aria-checked="model"
    :aria-label="label"
    class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500"
    :class="model ? 'bg-primary-500' : 'bg-gray-300 dark:bg-white/20'"
    @click="model = !model"
  >
    <span
      class="inline-block h-5 w-5 rounded-full bg-white shadow transition-transform"
      :class="model ? 'translate-x-5.5' : 'translate-x-0.5'"
    />
  </button>
</template>

<script setup lang="ts">
// An on/off toggle. `label` is what a screen reader announces.
defineProps<{ label: string }>()

const model = defineModel<boolean>({ default: false })
</script>
