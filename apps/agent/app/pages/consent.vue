<script setup lang="ts">
// Wording follows docs/DEVELOPMENT_PLAN.md §16.
const { me, acceptConsent, logout } = useAuth()

const busy = ref(false)
const error = ref<string | null>(null)

const titlesTracked = computed(() => me.value?.officeSettings.windowTitleMode !== 'APP_ONLY')

async function accept() {
  busy.value = true
  error.value = null
  try {
    await acceptConsent()
    await navigateTo('/')
  }
  catch (e) {
    error.value = describeLoginError(e)
  }
  finally {
    busy.value = false
  }
}

async function decline() {
  await logout()
  await navigateTo('/login')
}
</script>

<template>
  <main class="mx-auto max-w-md space-y-4 p-4">
    <header>
      <h1 class="text-lg font-semibold">
        What this app tracks
      </h1>
      <p class="text-sm text-slate-500">
        Please read this before tracking starts.
      </p>
    </header>

    <section class="rounded-lg border border-slate-200 bg-white p-4 text-sm">
      <h2 class="mb-1 font-medium">
        What is tracked
      </h2>
      <ul class="list-disc space-y-1 pl-5">
        <li>When you start, pause, resume and stop tracking.</li>
        <li>Which app is in front, and for how long.</li>
        <li v-if="titlesTracked">
          The window title of that app.
        </li>
        <li v-else>
          Window titles are turned off by your office: only app names are kept.
        </li>
        <li>When you are idle (no mouse or keyboard use), and which app was on screen then.</li>
      </ul>

      <h2 class="mb-1 mt-4 font-medium">
        What is not tracked
      </h2>
      <p>
        Keystrokes, typed text, mouse movements, webcam, microphone, file contents and
        screenshots. Websites are not tracked either, unless that is turned on later with new consent.
      </p>

      <h2 class="mb-1 mt-4 font-medium">
        When
      </h2>
      <p>
        Only while tracking is on. Nothing is tracked while you are paused, not tracking,
        or your PC is locked or asleep.
      </p>

      <h2 class="mb-1 mt-4 font-medium">
        Who can see it
      </h2>
      <p>
        Your manager and whoever is above them in the hierarchy. You can always see your own data.
        It is kept permanently on the office server.
      </p>
    </section>

    <p
      v-if="error"
      class="text-sm text-red-600"
      role="alert"
    >
      {{ error }}
    </p>

    <div class="flex gap-2">
      <button
        :disabled="busy"
        class="rounded bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-50"
        @click="accept"
      >
        I understand and accept
      </button>
      <button
        :disabled="busy"
        class="rounded bg-slate-200 px-3 py-2 text-sm"
        @click="decline"
      >
        Log out
      </button>
    </div>
  </main>
</template>
