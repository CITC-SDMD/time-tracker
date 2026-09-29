<template>
  <label class="block text-sm">
    <span
      v-if="label"
      class="ml-3 text-gray-500 dark:text-gray-400"
    >{{ label }}</span>
    <select
      v-model="model"
      class="mt-1 h-9 w-full rounded-full border border-gray-200 bg-white px-4 text-sm text-gray-950 outline-none transition-colors focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30 dark:border-white/10 dark:bg-gray-950 dark:text-white"
    >
      <option
        v-if="placeholder"
        value=""
      >
        {{ placeholder }}
      </option>
      <option
        v-for="option in options"
        :key="option.value"
        :value="option.value"
      >
        {{ option.label }}
      </option>
    </select>
  </label>
</template>

<script setup lang="ts">
// A labelled pill dropdown, styled like UiInput. `v-model` is a plain string; an empty string is the
// placeholder/no-choice value (there is no bare `null` on a native <select>).
defineProps<{
  label?: string
  options: Array<{ value: string, label: string }>
  placeholder?: string
}>()

const model = defineModel<string>({ default: '' })
</script>
