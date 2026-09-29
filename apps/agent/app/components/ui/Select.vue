<template>
  <label class="block text-sm">
    <span
      v-if="label"
      class="ml-3 text-gray-500 dark:text-gray-400"
    >{{ label }}</span>
    <div class="relative mt-1">
      <select
        v-model="model"
        class="h-9 w-full appearance-none rounded-full border border-gray-200 bg-white pl-4 pr-9 text-sm text-gray-950 outline-none transition-colors focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30 dark:border-white/10 dark:bg-gray-950 dark:text-white"
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
      <svg
        class="pointer-events-none absolute right-3.5 top-1/2 size-3.5 -translate-y-1/2 text-gray-400 dark:text-gray-500"
        viewBox="0 0 20 20"
        fill="currentColor"
        aria-hidden="true"
      >
        <path
          fill-rule="evenodd"
          d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z"
          clip-rule="evenodd"
        />
      </svg>
    </div>
  </label>
</template>

<script setup lang="ts">
// A labelled pill dropdown, styled like UiInput. `v-model` is a plain string; an empty string is the
// placeholder/no-choice value (there is no bare `null` on a native <select>). The browser's own arrow is
// turned off (`appearance-none`) and drawn ourselves so it matches the app's own icons instead of whatever
// the OS gives native controls (on Windows/WebView2 this can also collide with the system's own spelling/
// text-suggestion overlay on a focused control, which looks like stray text inside the pill).
defineProps<{
  label?: string
  options: Array<{ value: string, label: string }>
  placeholder?: string
}>()

const model = defineModel<string>({ default: '' })
</script>
