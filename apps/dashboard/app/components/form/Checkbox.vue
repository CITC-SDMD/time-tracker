<template>
  <div>
    <label
      :for="id"
      class="flex items-start gap-3"
    >
      <input
        :id="id"
        v-model="model"
        type="checkbox"
        :name="name"
        :disabled="disabled"
        class="mt-1 size-4 rounded border-gray-300 text-primary-600 accent-primary-500 focus:ring-primary-600 disabled:opacity-50 dark:border-white/20"
      >
      <span class="text-sm/6 text-gray-900 dark:text-white">
        {{ label }}
        <span
          v-if="hint"
          class="block text-gray-500 dark:text-gray-400"
        >{{ hint }}</span>
      </span>
    </label>
  </div>
</template>

<script setup lang="ts">
// A labelled tick box. Use `v-model` with a true/false value; `hint` is the quiet explanation under the label.
defineProps<{
  label: string
  name?: string
  hint?: string
  disabled?: boolean
}>()

const model = defineModel<boolean>({ default: false })

const id = useId()
</script>
