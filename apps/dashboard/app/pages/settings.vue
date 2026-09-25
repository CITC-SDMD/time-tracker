<script setup lang="ts">
import type { OfficeSettings } from 'shared'

// Office settings (docs/DEVELOPMENT_PLAN.md §12 Phase 6, OIC only). They apply to every desktop
// app the next time it syncs. The idle limit is entered in minutes and sent as seconds.
const { api } = useApi()
const { me } = useAuth()

const idleMinutes = ref(5)
const windowTitleMode = ref<OfficeSettings['windowTitleMode']>('FULL')
const timezone = ref('')
const minAgentVersion = ref('')
const saving = ref(false)
const saved = ref(false)
const error = ref<string | null>(null)

const timezones = computed(() => {
  const all = Intl.supportedValuesOf('timeZone')
  return timezone.value && !all.includes(timezone.value) ? [timezone.value, ...all] : all
})

function fill(s: OfficeSettings) {
  idleMinutes.value = Math.round(s.idleThresholdSeconds / 60)
  windowTitleMode.value = s.windowTitleMode
  timezone.value = s.timezone
  minAgentVersion.value = s.minAgentVersion
}

async function load() {
  try {
    fill(await api<OfficeSettings>('/admin/settings'))
  }
  catch (e) {
    error.value = messageOf(e, 'Could not load the settings.')
  }
}

async function save() {
  saving.value = true
  saved.value = false
  error.value = null
  try {
    const updated = await api<OfficeSettings>('/admin/settings', {
      method: 'PUT',
      body: {
        idleThresholdSeconds: idleMinutes.value * 60,
        windowTitleMode: windowTitleMode.value,
        timezone: timezone.value,
        minAgentVersion: minAgentVersion.value,
      },
    })
    fill(updated)
    // Keep the times shown on the other pages in step with a changed timezone.
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

onMounted(load)
</script>

<template>
  <main class="mx-auto max-w-2xl space-y-4 p-4">
    <header>
      <h1 class="text-lg font-semibold">
        Office settings
      </h1>
      <p class="text-sm text-slate-500">
        These apply to everyone, and reach each desktop app the next time it syncs.
      </p>
    </header>

    <form
      class="space-y-4 rounded-lg border border-slate-200 bg-white p-4 text-sm"
      @submit.prevent="save"
    >
      <label class="block">
        <span class="font-medium">Idle limit (minutes)</span>
        <span class="block text-xs text-slate-500">Someone with no keyboard or mouse use for this long is shown as idle. 1 to 30.</span>
        <input
          v-model.number="idleMinutes"
          type="number"
          min="1"
          max="30"
          step="1"
          required
          class="mt-1 w-28 rounded border border-slate-300 px-2 py-1.5"
        >
      </label>

      <fieldset>
        <legend class="font-medium">
          Window titles
        </legend>
        <label class="mt-1 flex items-center gap-2">
          <input
            v-model="windowTitleMode"
            type="radio"
            value="FULL"
          >
          App names and window titles
        </label>
        <label class="flex items-center gap-2">
          <input
            v-model="windowTitleMode"
            type="radio"
            value="APP_ONLY"
          >
          App names only
        </label>
      </fieldset>

      <label class="block">
        <span class="font-medium">Office timezone</span>
        <span class="block text-xs text-slate-500">Decides where each day starts and ends.</span>
        <select
          v-model="timezone"
          class="mt-1 rounded border border-slate-300 px-2 py-1.5"
        >
          <option
            v-for="tz in timezones"
            :key="tz"
            :value="tz"
          >
            {{ tz }}
          </option>
        </select>
      </label>

      <label class="block">
        <span class="font-medium">Minimum app version</span>
        <span class="block text-xs text-slate-500">Older desktop apps are asked to update.</span>
        <input
          v-model="minAgentVersion"
          required
          class="mt-1 w-40 rounded border border-slate-300 px-2 py-1.5 font-mono"
        >
      </label>

      <p
        v-if="error"
        class="text-red-600"
        role="alert"
      >
        {{ error }}
      </p>
      <p
        v-if="saved"
        class="text-green-700"
      >
        Saved.
      </p>

      <button
        type="submit"
        :disabled="saving"
        class="rounded bg-slate-900 px-4 py-1.5 text-white disabled:opacity-50"
      >
        {{ saving ? 'Saving…' : 'Save' }}
      </button>
    </form>
  </main>
</template>
