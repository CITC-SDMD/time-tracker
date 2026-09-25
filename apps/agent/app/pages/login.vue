<script setup lang="ts">
import { invoke } from '@tauri-apps/api/core'

const { login } = useAuth()
const notice = useState<string | null>('loginNotice', () => null)

const email = ref('')
const password = ref('')
const busy = ref(false)
const error = ref<string | null>(null)

async function submit() {
  busy.value = true
  error.value = null
  try {
    await login(email.value.trim(), password.value)
    await navigateTo('/')
  }
  catch (e) {
    error.value = describeLoginError(e)
  }
  finally {
    busy.value = false
  }
}

// The reset link is emailed by the server; the page for asking for one is on the dashboard.
async function forgotPassword() {
  try {
    await invoke('open_forgot_password')
  }
  catch (e) {
    error.value = `Could not open the browser: ${String(e)}`
  }
}
</script>

<template>
  <main class="mx-auto max-w-sm space-y-4 p-6">
    <header>
      <h1 class="text-lg font-semibold">
        Time Tracker
      </h1>
      <p class="text-sm text-slate-500">
        Log in with your office account.
      </p>
    </header>

    <p
      v-if="notice"
      class="rounded-lg bg-amber-50 p-3 text-sm text-amber-700"
    >
      {{ notice }}
    </p>

    <form
      class="space-y-3"
      @submit.prevent="submit"
    >
      <label class="block text-sm">
        <span class="text-slate-500">Email</span>
        <input
          v-model="email"
          type="email"
          required
          autocomplete="username"
          class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
        >
      </label>
      <label class="block text-sm">
        <span class="text-slate-500">Password</span>
        <input
          v-model="password"
          type="password"
          required
          autocomplete="current-password"
          class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
        >
      </label>

      <p
        v-if="error"
        class="text-sm text-red-600"
        role="alert"
      >
        {{ error }}
      </p>

      <button
        type="submit"
        :disabled="busy"
        class="w-full rounded bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-50"
      >
        {{ busy ? 'Logging in…' : 'Log in' }}
      </button>
    </form>

    <p class="text-center text-sm">
      <button
        type="button"
        class="text-slate-500 underline"
        title="Opens the password page in your browser"
        @click="forgotPassword"
      >
        Forgot password?
      </button>
    </p>
  </main>
</template>
