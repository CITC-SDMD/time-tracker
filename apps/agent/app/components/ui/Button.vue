<template>
  <component
    :is="to ? NuxtLink : 'button'"
    :to="to"
    :type="to ? undefined : type"
    :class="[base, VARIANTS[variant], variant === 'link' ? 'px-1 py-0.5' : 'px-5 py-2.5', block ? 'w-full' : '']"
  >
    <slot />
  </component>
</template>

<script setup lang="ts">
import { NuxtLink } from '#components'

// A pill button. `primary` is amber with black text, `soft` is a quiet grey, `danger` is soft red,
// `link` is plain text. With `to` it is a link that looks the same.
withDefaults(defineProps<{
  variant?: 'primary' | 'soft' | 'danger' | 'link'
  block?: boolean
  type?: 'button' | 'submit'
  to?: string
}>(), { variant: 'soft', type: 'button' })

const base = 'inline-flex items-center justify-center rounded-full text-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-not-allowed disabled:opacity-50'

const VARIANTS = {
  primary: 'bg-primary-500 font-semibold text-gray-950 hover:bg-primary-400',
  soft: 'bg-gray-100 font-medium text-gray-900 hover:bg-gray-200 dark:bg-white/10 dark:text-white dark:hover:bg-white/15',
  danger: 'bg-red-100 font-medium text-red-700 hover:bg-red-200 dark:bg-red-500/15 dark:text-red-300 dark:hover:bg-red-500/25',
  link: 'text-gray-500 underline-offset-2 hover:text-gray-900 hover:underline dark:text-gray-400 dark:hover:text-white',
}
</script>
