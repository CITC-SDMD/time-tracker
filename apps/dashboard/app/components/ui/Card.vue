<template>
  <component
    :is="as"
    class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:shadow-none dark:ring-white/10"
  >
    <slot />
  </component>
</template>

<script setup lang="ts">
// The bordered box that groups a form or a block of content. Pass `as="form"` to make it the
// <form> itself (then `@submit.prevent` goes on the component).
withDefaults(defineProps<{
  as?: string
}>(), {
  as: 'div',
})
</script>
