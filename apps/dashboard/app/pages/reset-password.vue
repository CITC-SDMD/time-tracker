<template>
  <main class="mx-auto flex min-h-screen max-w-sm flex-col justify-center gap-4 p-4">
    <header>
      <h1 class="text-lg font-semibold">
        Choose your password
      </h1>
      <p
        v-if="email"
        class="text-sm text-gray-500 dark:text-gray-400"
      >
        For {{ email }}
      </p>
    </header>

    <UiAlert
      v-if="!linkComplete"
      variant="danger"
    >
      This link is incomplete. Open the link from your email again, or
      <UiLink to="/forgot-password">
        ask for a new one
      </UiLink>.
    </UiAlert>

    <UiAlert v-else-if="done">
      <p>Your password is set. Any device that was signed in has been signed out.</p>
      <p class="mt-2">
        Sign in to the desktop app with your email and this password.
      </p>
      <UiLink
        to="/"
        class="mt-2 inline-block"
      >
        Managers: sign in to the dashboard
      </UiLink>
    </UiAlert>

    <UiCard
      v-else
      as="form"
      class="space-y-4"
      novalidate
      @submit.prevent="onSubmit"
    >
      <FormInput
        v-model="form.password"
        label="New password (at least 10 characters)"
        type="password"
        autocomplete="new-password"
        :errors="v$.password.$errors"
        @blur="v$.password.$touch()"
      />
      <FormInput
        v-model="form.confirmation"
        label="Type it again"
        type="password"
        autocomplete="new-password"
        :errors="v$.confirmation.$errors"
        @blur="v$.confirmation.$touch()"
      />
      <FormError v-if="error">
        {{ error }}
        <UiLink
          v-if="error.includes('expired')"
          to="/forgot-password"
        >
          Get a new link
        </UiLink>
      </FormError>
      <FormButton
        type="submit"
        block
        :loading="loading"
      >
        {{ loading ? 'Saving…' : 'Set password' }}
      </FormButton>
    </UiCard>
  </main>
</template>

<script setup lang="ts">
// Set or reset a password from an emailed link (welcome or "Forgot password"). Works for every
// role: employees who only use the desktop app choose their password here too.
import { useVuelidate } from '@vuelidate/core'
import { helpers, minLength, required, sameAs } from '@vuelidate/validators'

definePageMeta({ layout: false })

const route = useRoute()
const { web } = useApi()

// The email link carries the token and the email as one URL-safe value (`?link=`), so nothing after
// an "&" can be lost on the way. The older `?token=&email=` form still works.
function readLink(): { token: string, email: string } {
  const raw = String(route.query.link ?? '')
  if (raw) {
    try {
      const base64 = raw.replace(/-/g, '+').replace(/_/g, '/')
      const bytes = Uint8Array.from(atob(base64), c => c.charCodeAt(0))
      const parsed = JSON.parse(new TextDecoder().decode(bytes)) as { token?: string, email?: string }
      return { token: parsed.token ?? '', email: parsed.email ?? '' }
    }
    catch {
      return { token: '', email: '' }
    }
  }
  return { token: String(route.query.token ?? ''), email: String(route.query.email ?? '') }
}

const link = readLink()
const token = computed(() => link.token)
const email = computed(() => link.email)
const linkComplete = computed(() => !!token.value && !!email.value)

const form = reactive({ password: '', confirmation: '' })
const rules = {
  password: {
    required: helpers.withMessage('Choose a password.', required),
    minLength: helpers.withMessage('Use at least 10 characters.', minLength(10)),
  },
  confirmation: {
    required: helpers.withMessage('Type the password again.', required),
    sameAs: helpers.withMessage('The two passwords are not the same.', sameAs(toRef(form, 'password'))),
  },
}
const v$ = useVuelidate(rules, form)
const done = ref(false)
const error = ref<string | null>(null)
const loading = ref(false)

async function onSubmit() {
  if (!(await v$.value.$validate()))
    return
  loading.value = true
  error.value = null
  try {
    await web('/sanctum/csrf-cookie')
    await web('/auth/reset-password', {
      method: 'POST',
      body: {
        token: token.value,
        email: email.value,
        password: form.password,
        password_confirmation: form.confirmation,
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
