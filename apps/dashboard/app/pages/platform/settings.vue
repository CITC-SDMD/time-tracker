<template>
  <div class="max-w-2xl">
    <UiPageHeader
      title="Platform settings"
      description="What only the platform decides, for every organization."
    />

    <UiSpinner v-if="!loaded && !loadError" />

    <UiAlert
      v-else-if="loadError"
      variant="danger"
    >
      {{ loadError }}
    </UiAlert>

    <UiCard
      v-else
      as="form"
      class="space-y-6"
      novalidate
      @submit.prevent="save"
    >
      <FormInput
        v-model="form.minAgentVersion"
        label="Minimum desktop app version"
        placeholder="0.1.0"
        hint="Older desktop apps keep tracking on the PC but stop syncing until they are updated, so do not set a version that has not been released. Use the form 1.2.3."
        :errors="v$.minAgentVersion.$errors"
        @blur="v$.minAgentVersion.$touch()"
      />

      <FormError v-if="error">
        {{ error }}
      </FormError>
      <UiAlert v-if="saved">
        Settings saved.
      </UiAlert>

      <div class="flex justify-end">
        <FormButton
          type="submit"
          :loading="saving"
          :disabled="!dirty"
        >
          {{ saving ? 'Saving…' : 'Save settings' }}
        </FormButton>
      </div>
    </UiCard>
  </div>
</template>

<script setup lang="ts">
import { useVuelidate } from '@vuelidate/core'
import { helpers, required } from '@vuelidate/validators'
import type { PlatformSettings } from 'shared'

definePageMeta({
  layout: 'user',
  platformPermission: 'platform.settings',
})

// The oldest desktop app version that may still sync, for every organization (docs/DEVELOPMENT_PLAN.md §9.4).
const { api } = useApi()

const loaded = ref(false)
const loadError = ref<string | null>(null)
const saving = ref(false)
const saved = ref(false)
const error = ref<string | null>(null)
const current = ref<PlatformSettings | null>(null)

const form = reactive({ minAgentVersion: '' })
const v$ = useVuelidate({
  minAgentVersion: {
    required: helpers.withMessage('Enter a version.', required),
    format: helpers.withMessage('Use the form 1.2.3.', helpers.regex(/^\d+\.\d+\.\d+$/)),
  },
}, form)

const dirty = computed(() => !!current.value && form.minAgentVersion.trim() !== current.value.minAgentVersion)

onMounted(async () => {
  try {
    current.value = await api<PlatformSettings>('/platform/settings')
    form.minAgentVersion = current.value.minAgentVersion
  }
  catch (e) {
    loadError.value = messageOf(e, 'Could not load the settings.')
  }
  finally {
    loaded.value = true
  }
})

async function save() {
  saved.value = false
  if (!(await v$.value.$validate()))
    return
  saving.value = true
  error.value = null
  try {
    current.value = await api<PlatformSettings>('/platform/settings', { method: 'PUT', body: { minAgentVersion: form.minAgentVersion.trim() } })
    form.minAgentVersion = current.value.minAgentVersion
    v$.value.$reset()
    saved.value = true
  }
  catch (e) {
    error.value = messageOf(e, 'Could not save the settings.')
  }
  finally {
    saving.value = false
  }
}
</script>
