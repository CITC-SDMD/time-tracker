<template>
  <div>
    <label
      :for="id"
      class="block text-sm/6 font-medium text-gray-900 dark:text-gray-100"
    >{{ label }}</label>
    <div class="mt-2">
      <input
        :id="id"
        v-model="model"
        :type="type"
        :name="name"
        :autocomplete="autocomplete"
        :placeholder="placeholder"
        :minlength="minlength"
        :disabled="disabled"
        :aria-invalid="invalid"
        :aria-describedby="invalid ? `${id}-error` : undefined"
        class="block w-full rounded-md bg-white px-3 py-1.5 text-base text-gray-900 outline-1 -outline-offset-1 placeholder:text-gray-400 focus:outline-2 focus:-outline-offset-2 disabled:opacity-50 sm:text-sm/6 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
        :class="invalid
          ? 'outline-red-500 focus:outline-red-500 dark:outline-red-400 dark:focus:outline-red-400'
          : 'outline-gray-300 focus:outline-primary-600 dark:outline-white/10 dark:focus:outline-primary-500'"
        @blur="$emit('blur')"
      >
    </div>
    <p
      v-if="hint && !invalid"
      class="mt-2 text-sm/6 text-gray-500 dark:text-gray-400"
    >
      {{ hint }}
    </p>
    <p
      v-if="invalid"
      :id="`${id}-error`"
      class="mt-2 text-sm/6 text-red-600 dark:text-red-400"
    >
      {{ errors[0]?.$message }}
    </p>
  </div>
</template>

<script setup lang="ts">
import type { ErrorObject } from '@vuelidate/core'

// A labelled text field. Every input in the dashboard is this component, so labels, spacing,
// focus, errors and dark mode stay the same everywhere. Use `v-model` for the value.
// Field errors come from Vuelidate: pass `v$.field.$errors` as `errors` and call
// `v$.field.$touch()` on `@blur`. Vuelidate only lists errors once the field was touched.
const props = withDefaults(defineProps<{
  label: string
  type?: 'text' | 'email' | 'password' | 'number' | 'date' | 'search'
  name?: string
  autocomplete?: string
  placeholder?: string
  hint?: string
  minlength?: number
  disabled?: boolean
  errors?: ErrorObject[]
}>(), {
  type: 'text',
  errors: () => [],
})

defineEmits<{ blur: [] }>()

const model = defineModel<string>({ default: '' })
const id = useId()
const invalid = computed(() => props.errors.length > 0)
</script>
