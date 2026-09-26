<template>
  <div class="max-w-2xl">
    <UiPageHeader
      title="Your profile"
      description="Your details, email address and password."
    />

    <UiCard
      as="form"
      class="space-y-4"
      novalidate
      @submit.prevent="saveName"
    >
      <h2 class="text-base/7 font-semibold text-gray-900 dark:text-white">
        Details
      </h2>
      <FormInput
        v-model="nameForm.name"
        label="Full name"
        autocomplete="name"
        :errors="nameV$.name.$errors"
        @blur="nameV$.name.$touch()"
      />
      <dl class="grid grid-cols-1 gap-4 text-sm/6 sm:grid-cols-2">
        <div>
          <dt class="font-medium text-gray-900 dark:text-gray-100">
            Email
          </dt>
          <dd class="text-gray-500 dark:text-gray-400">
            {{ me?.email }}
          </dd>
        </div>
        <div>
          <dt class="font-medium text-gray-900 dark:text-gray-100">
            Role
          </dt>
          <dd class="text-gray-500 dark:text-gray-400">
            {{ me?.role?.name ?? 'Superadmin' }}
          </dd>
        </div>
        <template v-if="me?.organization">
          <div>
            <dt class="font-medium text-gray-900 dark:text-gray-100">
              Organization
            </dt>
            <dd class="text-gray-500 dark:text-gray-400">
              {{ me.organization.name }}
            </dd>
          </div>
          <div>
            <dt class="font-medium text-gray-900 dark:text-gray-100">
              Reports to
            </dt>
            <dd class="text-gray-500 dark:text-gray-400">
              {{ me.managerName ?? 'No one' }}
            </dd>
          </div>
          <div>
            <dt class="font-medium text-gray-900 dark:text-gray-100">
              Organization timezone
            </dt>
            <dd class="text-gray-500 dark:text-gray-400">
              {{ timezone }}
            </dd>
          </div>
        </template>
      </dl>
      <p class="text-sm/6 text-gray-500 dark:text-gray-400">
        {{ me?.isSuperadmin ? 'Your permissions are set by a superadmin who may manage superadmins.' : 'Your role is set by the people above you in your organization.' }}
      </p>
      <FormError v-if="nameError">
        {{ nameError }}
      </FormError>
      <UiAlert v-if="nameSaved">
        Your name was saved.
      </UiAlert>
      <div class="flex justify-end">
        <FormButton
          type="submit"
          :loading="savingName"
          :disabled="!nameChanged"
        >
          {{ savingName ? 'Saving…' : 'Save name' }}
        </FormButton>
      </div>
    </UiCard>

    <UiCard
      as="form"
      class="mt-8 space-y-4"
      novalidate
      @submit.prevent="saveEmail"
    >
      <h2 class="text-base/7 font-semibold text-gray-900 dark:text-white">
        Email address
      </h2>
      <p class="text-sm/6 text-gray-500 dark:text-gray-400">
        This is the address you sign in with, in the dashboard and in the desktop app. We tell your old address when it changes.
      </p>
      <FormInput
        v-model="emailForm.email"
        label="New email address"
        type="email"
        autocomplete="email"
        :errors="emailV$.email.$errors"
        @blur="emailV$.email.$touch()"
      />
      <FormInput
        v-model="emailForm.currentPassword"
        label="Your password"
        type="password"
        autocomplete="current-password"
        hint="We ask for it so nobody else can change your email address."
        :errors="emailV$.currentPassword.$errors"
        @blur="emailV$.currentPassword.$touch()"
      />
      <FormError v-if="emailError">
        {{ emailError }}
      </FormError>
      <UiAlert v-if="emailSaved">
        Your email address was changed. Sign in with the new address from now on; the desktop app will ask for it next time it needs a login.
      </UiAlert>
      <div class="flex justify-end">
        <FormButton
          type="submit"
          :loading="savingEmail"
          :disabled="!emailChanged"
        >
          {{ savingEmail ? 'Saving…' : 'Change email' }}
        </FormButton>
      </div>
    </UiCard>

    <UiCard
      as="form"
      class="mt-8 space-y-4"
      novalidate
      @submit.prevent="savePassword"
    >
      <h2 class="text-base/7 font-semibold text-gray-900 dark:text-white">
        Change password
      </h2>
      <FormInput
        v-model="passwordForm.currentPassword"
        label="Current password"
        type="password"
        autocomplete="current-password"
        :errors="passwordV$.currentPassword.$errors"
        @blur="passwordV$.currentPassword.$touch()"
      />
      <FormInput
        v-model="passwordForm.password"
        label="New password"
        type="password"
        autocomplete="new-password"
        hint="At least 10 characters."
        :errors="passwordV$.password.$errors"
        @blur="passwordV$.password.$touch()"
      />
      <FormInput
        v-model="passwordForm.confirmation"
        label="Repeat the new password"
        type="password"
        autocomplete="new-password"
        :errors="passwordV$.confirmation.$errors"
        @blur="passwordV$.confirmation.$touch()"
      />
      <FormError v-if="passwordError">
        {{ passwordError }}
      </FormError>
      <UiAlert v-if="passwordSaved">
        Your password was changed. The desktop app will ask you to log in again with the new one.
      </UiAlert>
      <div class="flex justify-end">
        <FormButton
          type="submit"
          :loading="savingPassword"
        >
          {{ savingPassword ? 'Saving…' : 'Change password' }}
        </FormButton>
      </div>
    </UiCard>

    <AppTwoFactorCard class="mt-8" />

    <AppSignedInPcs
      v-if="!me?.isSuperadmin"
      class="mt-8"
      url="/me/devices"
      title="Desktop app sign-ins"
      description="The PCs where you are signed in to the desktop app. Sign out a PC you no longer use or have lost: the app there asks for your password before it sends anything again. Signing in stays valid for a week after the last use."
    />
  </div>
</template>

<script setup lang="ts">
import { useVuelidate } from '@vuelidate/core'
import { email, helpers, maxLength, minLength, required, sameAs } from '@vuelidate/validators'
import type { Me } from 'shared'

definePageMeta({
  layout: 'user',
})

// A signed-in person's own details and password (docs/DEVELOPMENT_PLAN.md §10: PATCH /me,
// PUT /me/email, PUT /me/password, GET /me/devices). Available to every manager role: they change their own name, email and password.
const { api } = useApi()
const { me } = useAuth()
const { timezone } = useFormat()

// ---- name ------------------------------------------------------------------------------------

const nameForm = reactive({ name: me.value?.name ?? '' })
const nameV$ = useVuelidate({
  name: {
    required: helpers.withMessage('Enter your name.', required),
    maxLength: helpers.withMessage('Use at most 255 characters.', maxLength(255)),
  },
}, nameForm)

const savingName = ref(false)
const nameSaved = ref(false)
const nameError = ref<string | null>(null)
const nameChanged = computed(() => nameForm.name.trim() !== '' && nameForm.name.trim() !== me.value?.name)

async function saveName() {
  nameSaved.value = false
  if (!(await nameV$.value.$validate()))
    return
  savingName.value = true
  nameError.value = null
  try {
    me.value = await api<Me>('/me', { method: 'PATCH', body: { name: nameForm.name.trim() } })
    nameForm.name = me.value.name
    nameSaved.value = true
  }
  catch (e) {
    nameError.value = messageOf(e, 'Could not save your name.')
  }
  finally {
    savingName.value = false
  }
}

// ---- email -----------------------------------------------------------------------------------

const emailForm = reactive({ email: '', currentPassword: '' })
const emailV$ = useVuelidate({
  email: {
    required: helpers.withMessage('Enter your new email address.', required),
    email: helpers.withMessage('Enter a valid email address.', email),
    maxLength: helpers.withMessage('Use at most 255 characters.', maxLength(255)),
  },
  currentPassword: { required: helpers.withMessage('Enter your current password.', required) },
}, emailForm)

const savingEmail = ref(false)
const emailSaved = ref(false)
const emailError = ref<string | null>(null)
const emailChanged = computed(() => emailForm.email.trim() !== '' && emailForm.email.trim().toLowerCase() !== me.value?.email.toLowerCase())

async function saveEmail() {
  emailSaved.value = false
  if (!(await emailV$.value.$validate()))
    return
  savingEmail.value = true
  emailError.value = null
  try {
    me.value = await api<Me>('/me/email', { method: 'PUT', body: { email: emailForm.email.trim(), currentPassword: emailForm.currentPassword } })
    emailForm.email = ''
    emailForm.currentPassword = ''
    emailV$.value.$reset()
    emailSaved.value = true
  }
  catch (e) {
    emailError.value = messageOf(e, 'Could not change your email address.')
  }
  finally {
    savingEmail.value = false
  }
}

// ---- password --------------------------------------------------------------------------------

const passwordForm = reactive({ currentPassword: '', password: '', confirmation: '' })
const passwordV$ = useVuelidate({
  currentPassword: { required: helpers.withMessage('Enter your current password.', required) },
  password: {
    required: helpers.withMessage('Enter a new password.', required),
    minLength: helpers.withMessage('Use at least 10 characters.', minLength(10)),
  },
  confirmation: {
    required: helpers.withMessage('Repeat the new password.', required),
    sameAs: helpers.withMessage('The two passwords are not the same.', sameAs(computed(() => passwordForm.password))),
  },
}, passwordForm)

const savingPassword = ref(false)
const passwordSaved = ref(false)
const passwordError = ref<string | null>(null)

async function savePassword() {
  passwordSaved.value = false
  if (!(await passwordV$.value.$validate()))
    return
  savingPassword.value = true
  passwordError.value = null
  try {
    await api('/me/password', {
      method: 'PUT',
      body: {
        currentPassword: passwordForm.currentPassword,
        password: passwordForm.password,
        password_confirmation: passwordForm.confirmation,
      },
    })
    passwordForm.currentPassword = ''
    passwordForm.password = ''
    passwordForm.confirmation = ''
    passwordV$.value.$reset()
    passwordSaved.value = true
  }
  catch (e) {
    passwordError.value = messageOf(e, 'Could not change your password.')
  }
  finally {
    savingPassword.value = false
  }
}
</script>
