<template>
  <NuxtLink
    :to="to"
    :class="VARIANTS[variant]"
  >
    <slot />
  </NuxtLink>
</template>

<script setup lang="ts">
// A link between pages. `primary` is the amber text link; `muted` is the quiet underlined one.
withDefaults(defineProps<{
  to: string
  variant?: 'primary' | 'muted'
}>(), {
  variant: 'primary',
})

const VARIANTS = {
  primary: 'font-semibold text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300',
  muted: 'text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200',
} as const
</script>
