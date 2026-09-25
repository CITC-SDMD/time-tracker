<template>
  <button
    :type="type"
    :disabled="disabled || loading"
    :class="classes"
  >
    <slot />
  </button>
</template>

<script setup lang="ts">
// Every button in the dashboard. `primary` is the amber call-to-action (black text: white on amber
// is hard to read), `secondary` is neutral, `danger` is for destructive actions, `link` is a quiet
// text button for row actions, `plain` has no styling of its own (icon buttons: pass the classes), and `tab` is a tab heading (see UiTabs; `selected` marks the open one).
// `loading` disables the button while a request runs. The default type is "button", so nothing
// submits a form by accident.
const props = withDefaults(defineProps<{
  type?: 'button' | 'submit'
  variant?: 'primary' | 'secondary' | 'danger' | 'link' | 'tab' | 'plain'
  size?: 'md' | 'sm'
  block?: boolean
  loading?: boolean
  disabled?: boolean
  selected?: boolean
}>(), {
  type: 'button',
  variant: 'primary',
  size: 'md',
})

const VARIANTS = {
  primary: 'bg-primary-500 text-gray-950 shadow-xs hover:bg-primary-400 focus-visible:outline-primary-600 dark:shadow-none dark:focus-visible:outline-primary-500',
  secondary: 'bg-gray-100 text-gray-900 hover:bg-gray-200 focus-visible:outline-gray-400 dark:bg-white/10 dark:text-white dark:hover:bg-white/20',
  danger: 'bg-red-50 text-red-700 hover:bg-red-100 focus-visible:outline-red-500 dark:bg-red-500/10 dark:text-red-300 dark:hover:bg-red-500/20',
  link: 'text-primary-700 hover:text-primary-600 focus-visible:outline-primary-600 dark:text-primary-400 dark:hover:text-primary-300',
} as const

const SIZES = {
  md: 'px-3 py-1.5',
  sm: 'px-2 py-1',
} as const

const classes = computed(() => {
  if (props.variant === 'plain')
    return []
  if (props.variant === 'tab') {
    return [
      'whitespace-nowrap border-b-2 px-1 py-3 text-sm/6 font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600',
      props.selected
        ? 'border-primary-500 text-gray-900 dark:text-white'
        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:border-white/20 dark:hover:text-gray-200',
    ]
  }
  return [
    'inline-flex items-center justify-center rounded-md text-sm/6 font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
    props.variant === 'link' ? 'px-1 py-0.5' : SIZES[props.size],
    VARIANTS[props.variant],
    props.block ? 'w-full' : '',
  ]
})
</script>
