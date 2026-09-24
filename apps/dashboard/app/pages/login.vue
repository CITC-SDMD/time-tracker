<script setup lang="ts">
// Sanctum SPA login (docs/DEVELOPMENT_PLAN.md §9.2). The "admins only" check and
// route guard land in Phase 2, once apps/api exists to actually authenticate against.
const { login } = useAuth()
const email = ref('')
const password = ref('')
const error = ref<string | null>(null)
const loading = ref(false)

async function onSubmit() {
  loading.value = true
  error.value = null
  try {
    await login(email.value, password.value)
    await navigateTo('/')
  } catch {
    error.value = 'Could not log in. Check your email and password.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <main class="mx-auto flex min-h-screen max-w-sm flex-col justify-center gap-4 p-4">
    <header>
      <h1 class="text-lg font-semibold">Time Tracker — Admin</h1>
      <p class="text-sm text-slate-500">Sign in with your office admin account.</p>
    </header>

    <form class="space-y-3 rounded-lg border border-slate-200 bg-white p-4" @submit.prevent="onSubmit">
      <label class="block text-sm">
        <span class="text-slate-500">Email</span>
        <input
          v-model="email"
          type="email"
          required
          autocomplete="username"
          class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
        />
      </label>
      <label class="block text-sm">
        <span class="text-slate-500">Password</span>
        <input
          v-model="password"
          type="password"
          required
          autocomplete="current-password"
          class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
        />
      </label>
      <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
      <button
        type="submit"
        :disabled="loading"
        class="w-full rounded bg-slate-900 py-1.5 text-sm font-medium text-white disabled:opacity-50"
      >
        {{ loading ? 'Signing in…' : 'Sign in' }}
      </button>
    </form>
  </main>
</template>
