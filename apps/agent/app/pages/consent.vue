<script setup lang="ts">
const { acceptConsent, logout } = useAuth()

const busy = ref(false)
const error = ref<string | null>(null)

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

    <WhatWeTrack />

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
