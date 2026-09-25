<template>
  <UiModal
    v-model="open"
    :title="title"
    :persistent="loading"
  >
    <p class="text-sm/6 text-gray-600 dark:text-gray-300">
      <slot>{{ message }}</slot>
    </p>
    <FormError
      v-if="error"
      class="mt-3"
    >
      {{ error }}
    </FormError>
    <template #footer>
      <FormButton
        variant="secondary"
        :disabled="loading"
        @click="open = false"
      >
        Cancel
      </FormButton>
      <FormButton
        :variant="danger ? 'danger' : 'primary'"
        :loading="loading"
        @click="$emit('confirm')"
      >
        {{ confirmLabel }}
      </FormButton>
    </template>
  </UiModal>
</template>

<script setup lang="ts">
// "Are you sure?" for an action that changes an account. The caller runs the work on `confirm`,
// sets `loading` while it runs, and closes the dialog (or sets `error`) when it is done.
withDefaults(defineProps<{
  title: string
  message?: string
  confirmLabel?: string
  danger?: boolean
  loading?: boolean
  error?: string | null
}>(), {
  confirmLabel: 'Confirm',
})

defineEmits<{ confirm: [] }>()

const open = defineModel<boolean>({ default: false })
</script>
