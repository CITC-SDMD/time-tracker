<template>
  <fieldset :disabled="disabled">
    <legend class="text-sm/6 font-medium text-gray-900 dark:text-gray-100">
      {{ label }}
    </legend>
    <div class="mt-2 max-h-64 space-y-3 overflow-y-auto">
      <FormCheckbox
        v-for="person in people"
        :key="person.id"
        :model-value="model.includes(person.id)"
        :label="person.name"
        @update:model-value="toggle(person.id, $event)"
      />
      <p
        v-if="people.length === 0"
        class="text-sm/6 text-gray-500 dark:text-gray-400"
      >
        Nobody is in your reach to assign this to.
      </p>
    </div>
    <p
      v-if="errors.length"
      class="mt-3 text-sm/6 text-red-600 dark:text-red-400"
    >
      {{ errors[0]?.$message }}
    </p>
  </fieldset>
</template>

<script setup lang="ts">
import type { ErrorObject } from '@vuelidate/core'

// Who a task is assigned to: a plain tick list of the people in the caller's reach (there is no combobox
// component in the app yet, and task lists are small). `v-model` holds the ids that are ticked, as strings —
// same contract as FormPermissionPicker.
withDefaults(defineProps<{
  label: string
  people: Array<{ id: string, name: string }>
  disabled?: boolean
  errors?: ErrorObject[]
}>(), {
  errors: () => [],
})

const model = defineModel<string[]>({ default: () => [] })

function toggle(id: string, on: boolean) {
  model.value = on ? [...model.value.filter(v => v !== id), id] : model.value.filter(v => v !== id)
}
</script>
