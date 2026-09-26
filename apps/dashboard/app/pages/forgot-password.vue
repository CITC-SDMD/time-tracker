<template>
  <main class="mx-auto flex min-h-screen max-w-sm flex-col justify-center gap-4 p-4">
    <header>
      <h1 class="text-lg font-semibold">
        Forgot your password?
      </h1>
      <p class="text-sm text-gray-500 dark:text-gray-400">
        Enter your email address and we will send you a link to choose a new one.
      </p>
    </header>

    <UiAlert v-if="sent">
      If that email has an account, we sent a link to it. It works for one hour. Check your spam folder if you do not see it.
    </UiAlert>

    <UiCard
      v-else
      as="form"
      class="space-y-4"
      novalidate
      @submit.prevent="onSubmit"
    >
      <FormInput
        v-model="form.email"
        label="Email"
        type="email"
        autocomplete="username"
        :errors="v$.email.$errors"
        @blur="v$.email.$touch()"
      />
      <FormError v-if="error">
        {{ error }}
      </FormError>
      <FormButton
        type="submit"
        block
        :loading="loading"
      >
        {{ loading ? 'Sending…' : 'Send the link' }}
      </FormButton>
    </UiCard>

    <UiLink
      to="/"
      variant="muted"
      class="text-center text-sm"
    >
      Back to sign in
    </UiLink>
  </main>
</template>

<script setup lang="ts">
// "Forgot password" (docs/DEVELOPMENT_PLAN.md §9.2). Open to anyone, employees included. The
// answer is the same whether or not the email has an account.
import { useVuelidate } from '@vuelidate/core'
import { email as emailRule, helpers, required } from '@vuelidate/validators'

definePageMeta({ layout: false })

const { web } = useApi()

const form = reactive({ email: '' })
const rules = {
  email: {
    required: helpers.withMessage('Enter your email address.', required),
    email: helpers.withMessage('Enter a valid email address.', emailRule),
  },
}
const v$ = useVuelidate(rules, form)
const sent = ref(false)
const error = ref<string | null>(null)
const loading = ref(false)

async function onSubmit() {
  if (!(await v$.value.$validate()))
    return
  loading.value = true
  error.value = null
  try {
    await web('/sanctum/csrf-cookie')
    await web('/auth/forgot-password', { method: 'POST', body: { email: form.email.trim() } })
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
