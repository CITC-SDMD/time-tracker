<template>
  <main class="flex h-dvh items-center justify-center overflow-hidden p-6">
    <div class="w-full max-w-sm space-y-5">
      <header class="flex flex-col items-center gap-3 text-center">
        <UiLogoMark />
        <div>
          <h1 class="text-2xl font-semibold leading-tight">
            Time Tracker
          </h1>
          <p class="text-sm text-gray-500 dark:text-gray-400">
            Log in with your office account.
          </p>
        </div>
      </header>

      <p
        v-if="notice"
        class="rounded-2xl bg-primary-100 p-3 text-sm text-primary-900 dark:bg-primary-500/15 dark:text-primary-200"
      >
        {{ notice }}
      </p>

      <UiCard class="p-5!">
        <form
          class="space-y-3"
          @submit.prevent="submit"
        >
          <UiInput
            v-model="email"
            label="Email"
            type="email"
            required
            autocomplete="username"
          />
          <UiInput
            v-model="password"
            label="Password"
            type="password"
            required
            autocomplete="current-password"
          />

          <p
            v-if="error"
            class="rounded-2xl bg-red-100 p-3 text-sm text-red-700 dark:bg-red-500/15 dark:text-red-300"
            role="alert"
          >
            {{ error }}
          </p>

          <UiButton
            type="submit"
            variant="primary"
            block
            :disabled="busy"
            class="mt-1 py-3!"
          >
            {{ busy ? 'Logging in…' : 'Log in' }}
          </UiButton>
        </form>
      </UiCard>

      <p class="text-center text-sm">
        <UiButton
          variant="link"
          title="Opens the password page in your browser"
          @click="forgotPassword"
        >
          Forgot password?
        </UiButton>
      </p>
    </div>
  </main>
</template>

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
