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
// A boxed message: `success` for "done", `warning` for something to do first, `danger` for something that went wrong or a bad link.
const props = withDefaults(defineProps<{
  variant?: 'success' | 'warning' | 'danger'
}>(), {
  variant: 'success',
})

const VARIANTS = {
  success: 'border-green-200 bg-green-50 text-green-800 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-300',
  warning: 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200',
  danger: 'border-red-200 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300',
} as const
</script>
