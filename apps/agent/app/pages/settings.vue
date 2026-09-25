<template>
  <main class="mx-auto max-w-md space-y-3 p-4">
    <header class="flex items-center justify-between">
      <h1 class="text-lg font-semibold">
        Settings
      </h1>
      <NuxtLink
        to="/"
        class="text-sm text-slate-500 underline"
      >
        Back
      </NuxtLink>
    </header>

    <p
      v-if="error"
      class="text-sm text-red-600"
      role="alert"
    >
      {{ error }}
    </p>

    <section class="rounded-lg border border-slate-200 bg-white p-4">
      <h2 class="mb-2 text-sm font-medium text-slate-500">
        Set by your office
      </h2>
      <dl class="grid grid-cols-[8rem_1fr] gap-y-1 text-sm">
        <dt class="text-slate-500">
          Idle after
        </dt>
        <dd>{{ idleLimit }} without keyboard or mouse</dd>
        <dt class="text-slate-500">
          Window titles
        </dt>
        <dd>{{ titleMode }}</dd>
      </dl>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-4 text-sm">
      <label class="flex items-center justify-between gap-3">
        <span>
          <span class="block font-medium">Launch at startup</span>
          <span class="block text-xs text-slate-500">Starts in the tray when you sign in to Windows.</span>
        </span>
        <input
          type="checkbox"
          class="h-4 w-4"
          :checked="launchAtStartup"
          @change="toggleStartup"
        >
      </label>
    </section>

    <WhatWeTrack />

    <section class="space-y-2 rounded-lg border border-slate-200 bg-white p-4 text-sm">
      <p class="flex items-center justify-between">
        <span>
          Version <span class="font-mono">{{ version }}</span>
        </span>
        <button
          disabled
          class="rounded bg-slate-100 px-3 py-1 text-slate-400"
          title="Updates arrive with the installer"
        >
          Check for updates
        </button>
      </p>
      <p class="flex gap-4">
        <button
          class="underline"
          @click="run(() => invoke('open_log_folder'))"
        >
          Open log folder
        </button>
        <NuxtLink
          to="/debug"
          class="text-slate-500 underline"
        >
          Today's sessions
        </NuxtLink>
      </p>
    </section>

    <button
      :disabled="signingOut"
      class="w-full rounded bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-50"
      @click="signOut"
    >
      {{ signingOut ? 'Sending your data…' : 'Log out' }}
    </button>
  </main>
</template>

<script setup lang="ts">
import { invoke } from '@tauri-apps/api/core'

const { me, signOut, signingOut } = useAuth()

const version = ref('')
const launchAtStartup = ref(false)
const error = ref<string | null>(null)

const idleLimit = computed(() => {
  const seconds = me.value?.officeSettings.idleThresholdSeconds ?? 0
  return seconds % 60 === 0 ? `${seconds / 60} minutes` : `${seconds} seconds`
})
const titleMode = computed(() =>
  me.value?.officeSettings.windowTitleMode === 'app_only'
    ? 'App names only'
    : 'App names and window titles',
)

async function run(action: () => Promise<unknown>) {
  try {
    await action()
    error.value = null
  }
  catch (e) {
    error.value = String(e)
  }
}

onMounted(() => run(async () => {
  version.value = await invoke<string>('get_app_version')
  launchAtStartup.value = await invoke<boolean>('get_launch_at_startup')
}))

async function toggleStartup() {
  const wanted = !launchAtStartup.value
  await run(async () => {
    await invoke('set_launch_at_startup', { enabled: wanted })
    launchAtStartup.value = wanted
  })
}
</script>
