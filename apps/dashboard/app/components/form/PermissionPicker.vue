<template>
  <fieldset :disabled="disabled">
    <legend class="text-sm/6 font-medium text-gray-900 dark:text-gray-100">
      {{ label }}
    </legend>
    <div
      v-for="group in groups"
      :key="group.name"
      class="mt-4"
    >
      <p class="text-xs/5 font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
        {{ group.name }}
      </p>
      <div class="mt-2 space-y-3">
        <FormCheckbox
          v-for="permission in group.items"
          :key="permission.key"
          :model-value="model.includes(permission.key)"
          :label="permission.label"
          :hint="permission.description"
          :disabled="disabled || !isAvailable(permission.key)"
          @update:model-value="toggle(permission.key, $event)"
        />
      </div>
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

// A grid of tick boxes for the fixed list of permissions, grouped as the API groups them. `available` limits what can
// be ticked (nobody gives a permission they do not hold themselves); the others stay visible but greyed out.
// `v-model` holds the keys that are ticked; errors come from Vuelidate like the other form components.
const props = withDefaults(defineProps<{
  label: string
  /** the catalog: GET /permissions or /platform/permissions */
  permissions: Array<{ key: string, group: string, label: string, description: string }>
  /** the keys that may be ticked; everything when left out */
  available?: readonly string[]
  disabled?: boolean
  errors?: ErrorObject[]
}>(), {
  errors: () => [],
})

const model = defineModel<string[]>({ default: () => [] })

const groups = computed(() => {
  const byName = new Map<string, typeof props.permissions>()
  for (const permission of props.permissions)
    byName.set(permission.group, [...(byName.get(permission.group) ?? []), permission])
  return [...byName].map(([name, items]) => ({ name, items }))
})

function isAvailable(key: string): boolean {
  return !props.available || props.available.includes(key)
}

function toggle(key: string, on: boolean) {
  model.value = on ? [...model.value.filter(k => k !== key), key] : model.value.filter(k => k !== key)
}
</script>
