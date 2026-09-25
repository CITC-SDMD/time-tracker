<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs/5 font-medium"
    :class="VARIANTS[variant]"
  >
    <span
      v-if="dot"
      class="size-1.5 rounded-full"
      :class="DOTS[variant]"
      aria-hidden="true"
    />
    <slot />
  </span>
</template>

<script setup lang="ts">
// A small pill for a status or a role. `dot` adds the coloured dot in front of the text.
withDefaults(defineProps<{
  variant?: 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'primary'
  dot?: boolean
}>(), {
  variant: 'neutral',
})

const VARIANTS = {
  neutral: 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-300',
  success: 'bg-green-50 text-green-700 dark:bg-green-500/10 dark:text-green-300',
  warning: 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300',
  danger: 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300',
  info: 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300',
  primary: 'bg-primary-100 text-primary-800 dark:bg-primary-500/15 dark:text-primary-300',
} as const

const DOTS = {
  neutral: 'bg-gray-400',
  success: 'bg-green-500',
  warning: 'bg-amber-500',
  danger: 'bg-red-500',
  info: 'bg-blue-500',
  primary: 'bg-primary-500',
} as const
</script>
