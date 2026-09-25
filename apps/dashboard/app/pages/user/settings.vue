<template>
  <div class="max-w-2xl">
    <UiPageHeader
      title="Office settings"
      description="These apply to the whole office. Desktop apps pick up changes within a few minutes."
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
        v-model="form.idleMinutes"
        label="Idle limit (minutes)"
        type="number"
        hint="No keyboard or mouse for this long counts as idle time, not active time. Between 1 and 30."
        :errors="v$.idleMinutes.$errors"
        @blur="v$.idleMinutes.$touch()"
      />

      <FormRadioGroup
        v-model="form.windowTitleMode"
        label="What is recorded about the window in use"
        name="window-title-mode"
        :options="WINDOW_TITLE_OPTIONS"
        :errors="v$.windowTitleMode.$errors"
      />

      <FormSelect
        v-model="form.timezone"
        label="Office timezone"
        :options="timezoneOptions"
        :errors="v$.timezone.$errors"
        @blur="v$.timezone.$touch()"
      />

      <FormInput
        v-model="form.minAgentVersion"
        label="Minimum desktop app version"
        placeholder="0.1.0"
        hint="Older desktop apps keep tracking on the PC but stop syncing until they are updated, so do not set a version that has not been released. Use the form 1.2.3."
        :errors="v$.minAgentVersion.$errors"
        @blur="v$.minAgentVersion.$touch()"
      />

      <div>
        <FormInput
          v-model="form.consentVersion"
          label="Consent version"
          type="number"
          :errors="v$.consentVersion.$errors"
          @blur="v$.consentVersion.$touch()"
        />
        <UiAlert
          v-if="consentRaised"
          class="mt-3"
          variant="danger"
        >
          Raising the consent version makes every person accept the tracking notice again before their desktop app tracks anything.
        </UiAlert>
        <p
          v-else
          class="mt-2 text-sm/6 text-gray-500 dark:text-gray-400"
        >
          Raise this only when the tracking notice changes. It can never go down.
        </p>
      </div>

      <FormError v-if="error">
        {{ error }}
      </FormError>
      <UiAlert v-if="saved">
        Settings saved.
      </UiAlert>

      <div class="flex justify-end gap-3">
        <FormButton
          variant="secondary"
          :disabled="saving || !dirty"
          @click="reset"
        >
          Discard changes
        </FormButton>
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
import { between, helpers, integer, minValue, required } from '@vuelidate/validators'
import type { OfficeSettings } from 'shared'

definePageMeta({
  layout: 'user',
})

// Office-wide settings (docs/DEVELOPMENT_PLAN.md §9.1, §12 Phase 6): OIC only. The API refuses
// everyone else, and the sidebar does not show this page to them.
const { api } = useApi()
const { me } = useAuth()

const WINDOW_TITLE_OPTIONS = [
  { value: 'full', label: 'Full window titles', description: 'App name and the title of the window, e.g. "Budget.xlsx - Excel".' },
  { value: 'app_only', label: 'App names only', description: 'Just the app, e.g. "Excel". Window titles are not kept.' },
]

const loaded = ref(false)
const loadError = ref<string | null>(null)
const saving = ref(false)
const saved = ref(false)
const error = ref<string | null>(null)

// the values as they are on the server, to know what changed
const current = ref<OfficeSettings | null>(null)

const form = reactive({
  idleMinutes: '',
  windowTitleMode: 'full',
  timezone: '',
  minAgentVersion: '',
  consentVersion: '',
})

const rules = computed(() => ({
  idleMinutes: {
    required: helpers.withMessage('Enter the idle limit in minutes.', required),
    integer: helpers.withMessage('Use a whole number of minutes.', integer),
    between: helpers.withMessage('Choose between 1 and 30 minutes.', between(1, 30)),
  },
  windowTitleMode: { required: helpers.withMessage('Choose one.', required) },
  timezone: { required: helpers.withMessage('Choose the office timezone.', required) },
  minAgentVersion: {
    required: helpers.withMessage('Enter a version.', required),
    format: helpers.withMessage('Use the form 1.2.3.', helpers.regex(/^\d+\.\d+\.\d+$/)),
  },
  consentVersion: {
    required: helpers.withMessage('Enter the consent version.', required),
    integer: helpers.withMessage('Use a whole number.', integer),
    minValue: helpers.withMessage(`It cannot go below the current version (${current.value?.consentVersion ?? 1}).`, minValue(current.value?.consentVersion ?? 1)),
  },
}))
const v$ = useVuelidate(rules, form)

const timezoneOptions = computed(() => {
  const zones = new Set<string>(['UTC', ...Intl.supportedValuesOf('timeZone')])
  if (form.timezone)
    zones.add(form.timezone)
  return [...zones].sort().map(zone => ({ value: zone, label: zone.replaceAll('_', ' ') }))
})

const consentRaised = computed(() => Number(form.consentVersion) > (current.value?.consentVersion ?? 0))

function fill(settings: OfficeSettings) {
  current.value = settings
  form.idleMinutes = String(Math.round(settings.idleThresholdSeconds / 60))
  form.windowTitleMode = settings.windowTitleMode
  form.timezone = settings.timezone
  form.minAgentVersion = settings.minAgentVersion
  form.consentVersion = String(settings.consentVersion)
}

const dirty = computed(() => {
  const c = current.value
  return !!c && (
    Number(form.idleMinutes) * 60 !== c.idleThresholdSeconds
    || form.windowTitleMode !== c.windowTitleMode
    || form.timezone !== c.timezone
    || form.minAgentVersion.trim() !== c.minAgentVersion
    || Number(form.consentVersion) !== c.consentVersion
  )
})

function reset() {
  if (current.value)
    fill(current.value)
  v$.value.$reset()
  error.value = null
  saved.value = false
}

onMounted(async () => {
  try {
    fill(await api<OfficeSettings>('/admin/settings'))
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
    const updated = await api<OfficeSettings>('/admin/settings', {
      method: 'PUT',
      body: {
        idleThresholdSeconds: Number(form.idleMinutes) * 60,
        windowTitleMode: form.windowTitleMode,
        timezone: form.timezone,
        minAgentVersion: form.minAgentVersion.trim(),
        consentVersion: Number(form.consentVersion),
      },
    })
    fill(updated)
    v$.value.$reset()
    // every time on the dashboard follows the office timezone, so use the new one straight away
    if (me.value)
      me.value = { ...me.value, officeSettings: updated }
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
