<template>
  <label class="block text-sm">
    <span class="ml-3 text-gray-500 dark:text-gray-400">{{ label }}</span>
    <input
      v-model="model"
      :type="type"
      :required="required"
      :autocomplete="autocomplete"
      class="mt-1 h-11 w-full rounded-full border border-gray-200 bg-white px-4 text-gray-950 outline-none transition-colors focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30 dark:border-white/10 dark:bg-gray-950 dark:text-white"
    >
    <span
      v-if="error"
      class="ml-3 mt-1 block text-xs text-red-600 dark:text-red-400"
      role="alert"
    >{{ error }}</span>
  </label>
</template>

<script setup lang="ts">
// A labelled pill text field.
defineProps<{
  label: string
  type?: string
  autocomplete?: string
  required?: boolean
  error?: string | null
}>()

const model = defineModel<string>({ default: '' })
</script>
