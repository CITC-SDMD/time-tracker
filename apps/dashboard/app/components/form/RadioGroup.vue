<template>
  <fieldset>
    <legend class="text-sm/6 font-medium text-gray-900 dark:text-gray-100">
      {{ label }}
    </legend>
    <div class="mt-2 space-y-3">
      <label
        v-for="option in options"
        :key="option.value"
        class="flex items-start gap-3"
      >
        <input
          v-model="model"
          type="radio"
          :name="name"
          :value="option.value"
          :disabled="disabled"
          class="mt-1 size-4 border-gray-300 text-primary-600 accent-primary-500 focus:ring-primary-600 dark:border-white/20"
        >
        <span class="text-sm/6 text-gray-900 dark:text-white">
          {{ option.label }}
          <span
            v-if="option.description"
            class="block text-gray-500 dark:text-gray-400"
          >{{ option.description }}</span>
        </span>
      </label>
    </div>
    <p
      v-if="errors.length"
      class="mt-2 text-sm/6 text-red-600 dark:text-red-400"
    >
      {{ errors[0]?.$message }}
    </p>
  </fieldset>
</template>

<script setup lang="ts">
import type { ErrorObject } from '@vuelidate/core'

// A short list where exactly one choice is picked (e.g. full window titles or app names only).
// `v-model` holds the chosen `value`; errors come from Vuelidate like the other form components.
withDefaults(defineProps<{
  label: string
  name: string
  options: Array<{ value: string, label: string, description?: string }>
  disabled?: boolean
  errors?: ErrorObject[]
}>(), {
  errors: () => [],
})

const model = defineModel<string>({ default: '' })
</script>
