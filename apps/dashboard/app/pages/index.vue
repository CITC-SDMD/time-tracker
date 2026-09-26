<template>
  <div class="flex h-screen flex-1">
    <div class="flex flex-1 flex-col justify-center px-4 py-12 sm:px-6 lg:flex-none lg:px-20 xl:px-24">
      <div class="mx-auto w-full max-w-sm lg:w-96">
        <div>
          <img
            class="h-10 w-auto dark:hidden"
            src="https://tailwindcss.com/plus-assets/img/logos/mark.svg?color=amber&shade=500"
            alt="Your Company"
          >
          <img
            class="h-10 w-auto not-dark:hidden"
            src="https://tailwindcss.com/plus-assets/img/logos/mark.svg?color=amber&shade=400"
            alt="Your Company"
          >
          <h2 class="mt-8 text-2xl/9 font-bold tracking-tight text-gray-900 dark:text-white">
            Sign in to your account
          </h2>
        </div>

        <form
          class="mt-10 space-y-6"
          novalidate
          @submit.prevent="onSubmit"
        >
          <FormInput
            v-model="form.email"
            label="Email address"
            type="email"
            name="email"
            autocomplete="username"
            :errors="v$.email.$errors"
            @blur="v$.email.$touch()"
          />
          <FormInput
            v-model="form.password"
            label="Password"
            type="password"
            name="password"
            autocomplete="current-password"
            :errors="v$.password.$errors"
            @blur="v$.password.$touch()"
          />

          <div class="flex items-center justify-end text-sm/6">
            <UiLink to="/forgot-password">
              Forgot password?
            </UiLink>
          </div>

          <FormError v-if="error">
            {{ error }}
          </FormError>

          <FormButton
            type="submit"
            block
            :loading="loading"
          >
            {{ loading ? 'Signing in…' : 'Sign in' }}
          </FormButton>
        </form>
      </div>
    </div>
    <div class="relative hidden w-0 flex-1 lg:block">
      <img
        class="absolute inset-0 size-full object-cover"
        src="https://images.unsplash.com/photo-1496917756835-20cb06e75b4e?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=crop&w=1908&q=80"
        alt=""
      >
    </div>
  </div>
</template>

<script setup lang="ts">
import { useVuelidate } from '@vuelidate/core'
import { email as emailRule, helpers, required } from '@vuelidate/validators'

definePageMeta({ layout: false })

const { login } = useAuth()
const form = reactive({ email: '', password: '' })
const rules = {
  email: {
    required: helpers.withMessage('Enter your email address.', required),
    email: helpers.withMessage('Enter a valid email address.', emailRule),
  },
  password: { required: helpers.withMessage('Enter your password.', required) },
}
const v$ = useVuelidate(rules, form)
const error = ref<string | null>(null)
const loading = ref(false)

async function onSubmit() {
  if (!(await v$.value.$validate()))
    return
  loading.value = true
  error.value = null
  try {
    const signedIn = await login(form.email.trim(), form.password)
    await navigateTo(homeFor(signedIn))
  }
  catch (e) {
    error.value = messageOf(e, 'Could not log in. Check your connection and try again.')
  }
  finally {
    loading.value = false
  }
}
</script>
