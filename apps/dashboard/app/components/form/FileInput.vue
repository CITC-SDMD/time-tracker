<template>
  <div>
    <label
      :for="id"
      class="block text-sm/6 font-medium text-gray-900 dark:text-gray-100"
    >{{ label }}</label>
    <div class="mt-2">
      <input
        :id="id"
        ref="input"
        type="file"
        :accept="accept"
        :name="name"
        :disabled="disabled"
        :aria-invalid="invalid"
        :aria-describedby="invalid ? `${id}-error` : undefined"
        class="block w-full text-sm/6 text-gray-900 file:mr-4 file:cursor-pointer file:rounded-md file:border-0 file:bg-primary-600 file:px-3 file:py-1.5 file:text-sm/6 file:font-semibold file:text-white hover:file:bg-primary-500 disabled:opacity-50 dark:text-gray-100"
        @change="onChange"
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

// A labelled file picker. `v-model` is the chosen File (or null). Errors come from Vuelidate like every other field:
// pass `v$.field.$errors` as `errors`.
const props = withDefaults(defineProps<{
  label: string
  accept?: string
  name?: string
  hint?: string
  disabled?: boolean
  errors?: ErrorObject[]
}>(), {
  errors: () => [],
})

defineEmits<{ blur: [] }>()

const model = defineModel<File | null>({ default: null })
const id = useId()
const input = useTemplateRef<HTMLInputElement>('input')
const invalid = computed(() => props.errors.length > 0)

function onChange(event: Event) {
  model.value = (event.target as HTMLInputElement).files?.[0] ?? null
}

// clearing the model from outside (a reset form) empties the picker too
watch(model, (file) => {
  if (file === null && input.value)
    input.value.value = ''
})
</script>
