<script setup lang="ts">
// "Forgot password" (docs/DEVELOPMENT_PLAN.md §9.2). Open to anyone, employees included. The
// answer is the same whether or not the email has an account.
definePageMeta({ layout: false })

const { web } = useApi()
const email = ref('')
const sent = ref(false)
const error = ref<string | null>(null)
const loading = ref(false)

async function onSubmit() {
  loading.value = true
  error.value = null
  try {
    await web('/sanctum/csrf-cookie')
    await web('/auth/forgot-password', { method: 'POST', body: { email: email.value.trim() } })
    sent.value = true
  }
  catch (e) {
    error.value = messageOf(e, 'Could not send the link. Check your connection and try again.')
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
        Forgot your password?
      </h1>
      <p class="text-sm text-slate-500">
        Enter your office email and we will send you a link to choose a new one.
      </p>
    </header>

    <p
      v-if="sent"
      class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800"
      role="status"
    >
      If that email has an account, we sent a link to it. It works for one hour. Check your spam folder if you do not see it.
    </p>

    <form
      v-else
      class="space-y-3 rounded-lg border border-slate-200 bg-white p-4"
      @submit.prevent="onSubmit"
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
      <p
        v-if="error"
        class="text-sm text-red-600"
        role="alert"
      >
        {{ error }}
      </p>
      <button
        type="submit"
        :disabled="loading"
        class="w-full rounded bg-slate-900 py-1.5 text-sm font-medium text-white disabled:opacity-50"
      >
        {{ loading ? 'Sending…' : 'Send the link' }}
      </button>
    </form>

    <NuxtLink
      to="/login"
      class="text-center text-sm text-slate-500 underline"
    >
      Back to sign in
    </NuxtLink>
  </main>
</template>
