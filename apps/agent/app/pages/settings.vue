<template>
  <main class="flex h-dvh flex-col gap-2.5 overflow-hidden p-3">
    <UiPageHeader
      title="Settings"
      back="/"
    />

    <p
      v-if="error"
      class="rounded-2xl bg-red-100 p-2.5 text-xs text-red-700 dark:bg-red-500/15 dark:text-red-300"
      role="alert"
    >
      {{ error }}
    </p>

    <UiCard title="Set by your office">
      <dl class="grid grid-cols-[6.5rem_1fr] gap-y-1 text-sm">
        <dt class="text-gray-500 dark:text-gray-400">
          Idle after
        </dt>
        <dd>{{ idleLimit }} without keyboard or mouse</dd>
        <dt class="text-gray-500 dark:text-gray-400">
          Window titles
        </dt>
        <dd>{{ titleMode }}</dd>
      </dl>
    </UiCard>

    <UiCard>
      <div class="flex items-center justify-between gap-3 text-sm">
        <span>
          <span class="block font-medium">Launch at startup</span>
          <span class="block text-xs text-gray-500 dark:text-gray-400">Starts in the tray when you sign in to Windows.</span>
        </span>
        <UiSwitch
          :model-value="launchAtStartup"
          label="Launch at startup"
          @update:model-value="toggleStartup"
        />
      </div>
    </UiCard>

    <UiCard>
      <div class="flex items-center justify-between gap-3 text-sm">
        <span>
          <span class="block font-medium">What this app tracks</span>
          <span class="block text-xs text-gray-500 dark:text-gray-400">The notice you accepted, in two short pages.</span>
        </span>
        <UiButton
          to="/notice"
          class="px-4! py-1.5!"
        >
          Read
        </UiButton>
      </div>
    </UiCard>

    <UiCard>
      <div class="space-y-2 text-sm">
        <p class="flex items-center justify-between gap-3">
          <span>
            Version <span class="font-mono">{{ version }}</span>
          </span>
          <UiButton
            disabled
            class="px-4! py-1.5!"
            title="Updates arrive with the installer"
          >
            Check for updates
          </UiButton>
        </p>
        <p
          v-if="isDev"
          class="flex gap-4"
        >
          <UiButton
            variant="link"
            @click="run(() => invoke('open_log_folder'))"
          >
            Open log folder
          </UiButton>
          <UiButton
            variant="link"
            to="/debug"
          >
            Today's sessions
          </UiButton>
        </p>
      </div>
    </UiCard>

    <UiButton
      variant="primary"
      block
      class="mt-auto"
      :disabled="signingOut"
      @click="signOut"
    >
      {{ signingOut ? 'Sending your data…' : 'Log out' }}
    </UiButton>
  </main>
</template>

<script setup lang="ts">
import { invoke } from '@tauri-apps/api/core'

const { me, signOut, signingOut } = useAuth()

// the log folder and the raw sessions table are developer's tools: only dev builds link to them
const isDev = import.meta.dev

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

async function toggleStartup(wanted: boolean) {
  await run(async () => {
    await invoke('set_launch_at_startup', { enabled: wanted })
    launchAtStartup.value = wanted
  })
}
</script>
