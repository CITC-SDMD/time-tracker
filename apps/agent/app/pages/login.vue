<script setup lang="ts">
const { login } = useAuth()

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
  </main>
</template>
