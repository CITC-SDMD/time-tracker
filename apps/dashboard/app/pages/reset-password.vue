<script setup lang="ts">
// Set or reset a password from an emailed link (welcome or "Forgot password"). Works for every
// role: employees who only use the desktop app choose their password here too.
definePageMeta({ layout: false })

const route = useRoute()
const { web } = useApi()

const token = computed(() => String(route.query.token ?? ''))
const email = computed(() => String(route.query.email ?? ''))
const linkComplete = computed(() => !!token.value && !!email.value)

const password = ref('')
const confirmation = ref('')
const done = ref(false)
const error = ref<string | null>(null)
const loading = ref(false)

const mismatch = computed(() => confirmation.value !== '' && confirmation.value !== password.value)

async function onSubmit() {
  if (mismatch.value) {
    error.value = 'The two passwords are not the same.'
    return
  }
  loading.value = true
  error.value = null
  try {
    await web('/sanctum/csrf-cookie')
    await web('/auth/reset-password', {
      method: 'POST',
      body: {
        token: token.value,
        email: email.value,
        password: password.value,
        password_confirmation: confirmation.value,
      },
    })
    done.value = true
  }
  catch (e) {
    error.value = messageOf(e, 'Could not set the password. Check your connection and try again.')
  }
  finally {
    loading.value = false
  }
}
</script>

<template>
  <main class="mx-auto flex min-h-screen max-w-sm flex-col justify-center gap-4 p-4">
    <header>
      <h1 class="text-lg font-semibold">
        Choose your password
      </h1>
      <p
        v-if="email"
        class="text-sm text-slate-500"
      >
        For {{ email }}
      </p>
    </header>

    <p
      v-if="!linkComplete"
      class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700"
      role="alert"
    >
      This link is incomplete. Open the link from your email again, or
      <NuxtLink
        to="/forgot-password"
        class="underline"
      >
        ask for a new one
      </NuxtLink>.
    </p>

    <div
      v-else-if="done"
      class="space-y-3 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800"
      role="status"
    >
      <p>Your password is set. Any device that was signed in has been signed out.</p>
      <p>Sign in to the desktop app with your email and this password.</p>
      <NuxtLink
        to="/login"
        class="inline-block underline"
      >
        Managers: sign in to the dashboard
      </NuxtLink>
    </div>

    <form
      v-else
      class="space-y-3 rounded-lg border border-slate-200 bg-white p-4"
      @submit.prevent="onSubmit"
    >
      <label class="block text-sm">
        <span class="text-slate-500">New password (at least 10 characters)</span>
        <input
          v-model="password"
          type="password"
          required
          minlength="10"
          autocomplete="new-password"
          class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
        >
      </label>
      <label class="block text-sm">
        <span class="text-slate-500">Type it again</span>
        <input
          v-model="confirmation"
          type="password"
          required
          minlength="10"
          autocomplete="new-password"
          class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
        >
      </label>
      <p
        v-if="error"
        class="text-sm text-red-600"
        role="alert"
      >
        {{ error }}
        <NuxtLink
          v-if="error.includes('expired')"
          to="/forgot-password"
          class="underline"
        >
          Get a new link
        </NuxtLink>
      </p>
      <button
        type="submit"
        :disabled="loading"
        class="w-full rounded bg-slate-900 py-1.5 text-sm font-medium text-white disabled:opacity-50"
      >
        {{ loading ? 'Saving…' : 'Set password' }}
      </button>
    </form>
  </main>
</template>
