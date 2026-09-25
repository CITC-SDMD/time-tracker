<template>
  <div class="max-w-2xl">
    <UiPageHeader
      title="Your profile"
      description="Your details and your password."
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
            {{ me ? ROLE_LABEL[me.role] : '' }}
          </dd>
        </div>
        <div>
          <dt class="font-medium text-gray-900 dark:text-gray-100">
            Reports to
          </dt>
          <dd class="text-gray-500 dark:text-gray-400">
            {{ me?.managerName ?? 'No one' }}
          </dd>
        </div>
        <div>
          <dt class="font-medium text-gray-900 dark:text-gray-100">
            Office timezone
          </dt>
          <dd class="text-gray-500 dark:text-gray-400">
            {{ timezone }}
          </dd>
        </div>
      </dl>
      <p class="text-sm/6 text-gray-500 dark:text-gray-400">
        Your email and role are set by your manager.
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
  </div>
</template>

<script setup lang="ts">
import { useVuelidate } from '@vuelidate/core'
import { helpers, maxLength, minLength, required, sameAs } from '@vuelidate/validators'
import { ROLE_LABEL, type Me } from 'shared'

definePageMeta({
  layout: 'user',
})

// A signed-in person's own details and password (docs/DEVELOPMENT_PLAN.md §10: PATCH /me,
// PUT /me/password). Available to every manager role; name is the only detail they can change.
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
