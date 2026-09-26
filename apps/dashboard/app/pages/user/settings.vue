<template>
  <div class="max-w-2xl">
    <UiPageHeader
      title="Organization settings"
      description="These apply to the whole organization. Desktop apps pick up changes within a few minutes."
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
        label="Organization timezone"
        :options="timezoneOptions"
        :errors="v$.timezone.$errors"
        @blur="v$.timezone.$touch()"
      />

      <div class="space-y-4 border-t border-gray-200 pt-6 dark:border-white/10">
        <h2 class="text-base/7 font-semibold text-gray-900 dark:text-white">
          Screenshots
        </h2>
        <FormSelect
          v-model="form.screenshotInterval"
          label="Take a screenshot of each person's main screen"
          :options="SCREENSHOT_OPTIONS"
          :errors="v$.screenshotInterval.$errors"
          @blur="v$.screenshotInterval.$touch()"
        />
        <FormCheckbox
          v-model="form.screenshotRandom"
          label="At a random moment in each block"
          hint="One picture at an unpredictable time inside each block, instead of exactly on the interval."
          :disabled="form.screenshotInterval === '0'"
        />
        <p class="text-sm/6 text-gray-500 dark:text-gray-400">
          Only while tracking is on (idle time included), never while paused, stopped or locked. People with the right permission see the pictures
          of the people they reach, and each person sees their own. Everything is kept on the storage server.
          Space used so far: <b>{{ formatBytes(current?.screenshotStorageBytes ?? 0) }}</b>.
        </p>
      </div>

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
import type { AdminOrganizationSettings } from 'shared'

definePageMeta({
  layout: 'user',
  permission: 'settings.manage',
  alias: ['/platform/organizations/:orgId/office/settings'],
})

// The organization's settings (docs/DEVELOPMENT_PLAN.md §9.1, §12 Phase 6): for whoever holds settings.manage. The API
// refuses everyone else, and the sidebar does not show this page to them. (The oldest allowed desktop app version is
// the platform's, on the platform settings page.)
const { api } = useApi()
const { me } = useAuth()
const office = useOffice()
const { formatBytes } = useFormat()

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
const current = ref<AdminOrganizationSettings | null>(null)

const form = reactive({
  idleMinutes: '',
  windowTitleMode: 'full',
  timezone: '',
  consentVersion: '',
  screenshotInterval: '0',
  screenshotRandom: false,
})

const SCREENSHOT_OPTIONS = [
  { value: '0', label: 'Off' },
  { value: '5', label: 'Every 5 minutes' },
  { value: '10', label: 'Every 10 minutes' },
  { value: '15', label: 'Every 15 minutes' },
  { value: '30', label: 'Every 30 minutes' },
]

const rules = computed(() => ({
  idleMinutes: {
    required: helpers.withMessage('Enter the idle limit in minutes.', required),
    integer: helpers.withMessage('Use a whole number of minutes.', integer),
    between: helpers.withMessage('Choose between 1 and 30 minutes.', between(1, 30)),
  },
  windowTitleMode: { required: helpers.withMessage('Choose one.', required) },
  screenshotInterval: { allowed: helpers.withMessage('Choose one of the options.', (value: string) => SCREENSHOT_OPTIONS.some(o => o.value === value)) },
  timezone: { required: helpers.withMessage('Choose the organization timezone.', required) },
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

function fill(settings: AdminOrganizationSettings) {
  current.value = settings
  form.idleMinutes = String(Math.round(settings.idleThresholdSeconds / 60))
  form.windowTitleMode = settings.windowTitleMode
  form.timezone = settings.timezone
  form.consentVersion = String(settings.consentVersion)
  form.screenshotInterval = String(settings.screenshotIntervalMinutes)
  form.screenshotRandom = settings.screenshotRandom
  autoRaised.value = false
}

// Turning screenshots on needs a higher consent version, so everyone accepts the new notice first:
// it is raised for the person here (and put back if they change their mind before saving).
const autoRaised = ref(false)

watch(() => form.screenshotInterval, (value) => {
  const c = current.value
  if (!c)
    return
  if (c.screenshotIntervalMinutes === 0 && Number(value) > 0 && Number(form.consentVersion) <= c.consentVersion) {
    form.consentVersion = String(c.consentVersion + 1)
    autoRaised.value = true
  }
  else if (autoRaised.value && (Number(value) === 0 || c.screenshotIntervalMinutes > 0)) {
    form.consentVersion = String(c.consentVersion)
    autoRaised.value = false
  }
})

const dirty = computed(() => {
  const c = current.value
  return !!c && (
    Number(form.idleMinutes) * 60 !== c.idleThresholdSeconds
    || form.windowTitleMode !== c.windowTitleMode
    || form.timezone !== c.timezone
    || Number(form.consentVersion) !== c.consentVersion
    || Number(form.screenshotInterval) !== c.screenshotIntervalMinutes
    || form.screenshotRandom !== c.screenshotRandom
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
    fill(await api<AdminOrganizationSettings>('/admin/settings'))
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
    const updated = await api<AdminOrganizationSettings>('/admin/settings', {
      method: 'PUT',
      body: {
        idleThresholdSeconds: Number(form.idleMinutes) * 60,
        windowTitleMode: form.windowTitleMode,
        timezone: form.timezone,
        consentVersion: Number(form.consentVersion),
        screenshotIntervalMinutes: Number(form.screenshotInterval),
        screenshotRandom: form.screenshotRandom,
      },
    })
    fill(updated)
    v$.value.$reset()
    // every time on the dashboard follows the organization timezone, so use the new one straight away
    if (office.organization.value)
      office.organization.value = { ...office.organization.value, timezone: updated.timezone }
    if (me.value?.settings)
      me.value = { ...me.value, settings: updated, organization: me.value.organization && { ...me.value.organization, timezone: updated.timezone } }
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
