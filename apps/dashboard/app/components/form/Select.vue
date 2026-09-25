<template>
  <div>
    <label
      :for="id"
      class="block text-sm/6 font-medium text-gray-900 dark:text-gray-100"
    >{{ label }}</label>
    <div class="mt-2">
      <select
        :id="id"
        v-model="model"
        :name="name"
        :disabled="disabled"
        :aria-invalid="invalid"
        :aria-describedby="invalid ? `${id}-error` : undefined"
        class="block w-full rounded-md bg-white px-3 py-1.5 text-base text-gray-900 outline-1 -outline-offset-1 focus:outline-2 focus:-outline-offset-2 disabled:opacity-50 sm:text-sm/6 dark:bg-gray-900 dark:text-white"
        :class="invalid
          ? 'outline-red-500 focus:outline-red-500 dark:outline-red-400 dark:focus:outline-red-400'
          : 'outline-gray-300 focus:outline-primary-600 dark:outline-white/10 dark:focus:outline-primary-500'"
        @blur="$emit('blur')"
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
    </div>
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

// A labelled drop-down list. Same contract as FormInput: `v-model` for the value, Vuelidate's
// `v$.field.$errors` as `errors`, `v$.field.$touch()` on `@blur`. `placeholder` adds an empty first choice.
const props = withDefaults(defineProps<{
  label: string
  options: Array<{ value: string, label: string }>
  name?: string
  placeholder?: string
  disabled?: boolean
  errors?: ErrorObject[]
}>(), {
  errors: () => [],
})

defineEmits<{ blur: [] }>()

const model = defineModel<string>({ default: '' })
const id = useId()
const invalid = computed(() => props.errors.length > 0)
</script>
