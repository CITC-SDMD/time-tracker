<template>
  <div
    class="rounded-lg border p-4 text-sm"
    :class="VARIANTS[props.variant]"
    :role="variant === 'danger' ? 'alert' : 'status'"
  >
    <slot />
  </div>
</template>

<script setup lang="ts">
// A boxed message: `success` for "done", `danger` for something that went wrong or a bad link.
const props = withDefaults(defineProps<{
  variant?: 'success' | 'danger'
}>(), {
  variant: 'success',
})

const VARIANTS = {
  success: 'border-green-200 bg-green-50 text-green-800 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-300',
  danger: 'border-red-200 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300',
} as const
</script>
