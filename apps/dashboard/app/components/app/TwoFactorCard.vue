<template>
  <UiCard class="space-y-4">
    <h2 class="text-base/7 font-semibold text-gray-900 dark:text-white">
      Two-factor sign-in
    </h2>
    <p class="text-sm/6 text-gray-500 dark:text-gray-400">
      Adds a 6-digit code from an authenticator app (Google Authenticator, Microsoft Authenticator, Authy and others) to your password, so a stolen password alone is not enough. It applies to signing in to this dashboard.
    </p>

    <UiSpinner v-if="loading" />

    <!-- recovery codes: shown once, right after turning it on or asking for new ones -->
    <div
      v-else-if="recoveryCodes"
      class="space-y-4"
    >
      <UiAlert>
        Save these recovery codes somewhere safe, apart from your phone. Each one signs you in once if you lose your phone. They are not shown again.
      </UiAlert>
      <ul class="grid grid-cols-2 gap-2 font-mono text-sm/6 text-gray-900 dark:text-white">
        <li
          v-for="code in recoveryCodes"
          :key="code"
        >
          {{ code }}
        </li>
      </ul>
      <div class="flex justify-end gap-3">
        <UiCopyButton
          :text="recoveryCodes.join(', ')"
          label="Copy the codes"
        />
        <FormButton @click="recoveryCodes = null">
          I have saved them
        </FormButton>
      </div>
    </div>

    <!-- on -->
    <form
      v-else-if="enabled"
      class="space-y-4"
      novalidate
      @submit.prevent
    >
      <UiAlert>
        Two-factor sign-in is on. {{ recoveryCodesLeft }} recovery {{ recoveryCodesLeft === 1 ? 'code is' : 'codes are' }} left.
      </UiAlert>
      <FormInput
        v-model="manage.currentPassword"
        label="Your password"
        type="password"
        autocomplete="current-password"
        :errors="manageV$.currentPassword.$errors"
        @blur="manageV$.currentPassword.$touch()"
      />
      <FormInput
        v-model="manage.code"
        label="Code from your app, or a recovery code"
        autocomplete="one-time-code"
        hint="Needed to turn it off or to get new recovery codes."
        :errors="manageV$.code.$errors"
        @blur="manageV$.code.$touch()"
      />
      <FormError v-if="error">
        {{ error }}
      </FormError>
      <div class="flex justify-end gap-3">
        <FormButton
          variant="secondary"
          :loading="busy"
          @click="newCodes"
        >
          New recovery codes
        </FormButton>
        <FormButton
          variant="danger"
          :loading="busy"
          @click="turnOff"
        >
          Turn off
        </FormButton>
      </div>
    </form>

    <!-- setting up: scan, then confirm with the first code -->
    <form
      v-else-if="setup"
      class="space-y-4"
      novalidate
      @submit.prevent="confirm"
    >
      <p class="text-sm/6 text-gray-700 dark:text-gray-300">
        Scan this picture with your authenticator app, or type the key in by hand. Then enter the 6-digit code the app shows.
      </p>
      <img
        v-if="qr"
        :src="qr"
        alt="QR code for your authenticator app"
        class="size-48 rounded-md bg-white p-2"
      >
      <p class="break-all font-mono text-sm/6 text-gray-900 dark:text-white">
        {{ setup.secret }}
      </p>
      <FormInput
        v-model="confirmForm.code"
        label="6-digit code"
        autocomplete="one-time-code"
        inputmode="numeric"
        :errors="confirmV$.code.$errors"
        @blur="confirmV$.code.$touch()"
      />
      <FormError v-if="error">
        {{ error }}
      </FormError>
      <div class="flex justify-end gap-3">
        <FormButton
          variant="secondary"
          @click="cancelSetup"
        >
          Cancel
        </FormButton>
        <FormButton
          type="submit"
          :loading="busy"
        >
          Turn on
        </FormButton>
      </div>
    </form>

    <!-- off -->
    <form
      v-else
      class="space-y-4"
      novalidate
      @submit.prevent="start"
    >
      <FormInput
        v-model="startForm.currentPassword"
        label="Your password"
        type="password"
        autocomplete="current-password"
        hint="We ask for it so nobody else can set this up on your account."
        :errors="startV$.currentPassword.$errors"
        @blur="startV$.currentPassword.$touch()"
      />
      <FormError v-if="error">
        {{ error }}
      </FormError>
      <div class="flex justify-end">
        <FormButton
          type="submit"
          :loading="busy"
        >
          Set up two-factor sign-in
        </FormButton>
      </div>
    </form>
  </UiCard>
</template>

<script setup lang="ts">
import { useVuelidate } from '@vuelidate/core'
import { helpers, required } from '@vuelidate/validators'
import QRCode from 'qrcode'

// Turning two-factor sign-in on and off (docs/SECURITY_REVIEW.md): GET/POST/DELETE /me/two-factor and its confirm and
// recovery-codes routes. Each step that changes anything asks for the password, and turning it off also for a code.
const { api } = useApi()
const { me } = useAuth()

const loading = ref(true)
const enabled = ref(false)
const recoveryCodesLeft = ref(0)
const setup = ref<{ secret: string, uri: string } | null>(null)
const qr = ref<string | null>(null)
const recoveryCodes = ref<string[] | null>(null)
const busy = ref(false)
const error = ref<string | null>(null)

const startForm = reactive({ currentPassword: '' })
const startV$ = useVuelidate({ currentPassword: { required: helpers.withMessage('Enter your password.', required) } }, startForm, { $scope: false })
const confirmForm = reactive({ code: '' })
const confirmV$ = useVuelidate({ code: { required: helpers.withMessage('Enter the code from your app.', required) } }, confirmForm, { $scope: false })
const manage = reactive({ currentPassword: '', code: '' })
const manageV$ = useVuelidate({
  currentPassword: { required: helpers.withMessage('Enter your password.', required) },
  code: { required: helpers.withMessage('Enter a code from your app or a recovery code.', required) },
}, manage, { $scope: false })

async function load() {
  try {
    const status = await api<{ enabled: boolean, recoveryCodesLeft: number }>('/me/two-factor')
    enabled.value = status.enabled
    recoveryCodesLeft.value = status.recoveryCodesLeft
  }
  catch (e) {
    error.value = messageOf(e, 'Could not load your two-factor settings.')
  }
  finally {
    loading.value = false
  }
}
onMounted(load)

/** runs one call with the shared busy and error handling; returns its answer, or null after showing the error */
async function run<T>(call: () => Promise<T>, fallback: string): Promise<T | null> {
  busy.value = true
  error.value = null
  try {
    return await call()
  }
  catch (e) {
    error.value = messageOf(e, fallback)
    return null
  }
  finally {
    busy.value = false
  }
}

function markEnabled(value: boolean) {
  enabled.value = value
  if (me.value)
    me.value = { ...me.value, twoFactorEnabled: value }
}

async function start() {
  if (!(await startV$.value.$validate()))
    return
  const answer = await run(() => api<{ secret: string, uri: string }>('/me/two-factor', { method: 'POST', body: { currentPassword: startForm.currentPassword } }), 'Could not start the setup.')
  if (!answer)
    return
  setup.value = answer
  qr.value = await QRCode.toDataURL(answer.uri, { margin: 1, width: 192 })
  startForm.currentPassword = ''
  startV$.value.$reset()
}

function cancelSetup() {
  setup.value = null
  qr.value = null
  error.value = null
  confirmForm.code = ''
  confirmV$.value.$reset()
}

async function confirm() {
  if (!(await confirmV$.value.$validate()))
    return
  const answer = await run(() => api<{ recoveryCodes: string[] }>('/me/two-factor/confirm', { method: 'POST', body: { code: confirmForm.code.trim() } }), 'That code did not work.')
  if (!answer)
    return
  recoveryCodes.value = answer.recoveryCodes
  recoveryCodesLeft.value = answer.recoveryCodes.length
  markEnabled(true)
  cancelSetup()
}

async function newCodes() {
  if (!(await manageV$.value.$validate()))
    return
  const answer = await run(() => api<{ recoveryCodes: string[] }>('/me/two-factor/recovery-codes', { method: 'POST', body: { currentPassword: manage.currentPassword, code: manage.code.trim() } }), 'Could not make new recovery codes.')
  if (!answer)
    return
  recoveryCodes.value = answer.recoveryCodes
  recoveryCodesLeft.value = answer.recoveryCodes.length
  resetManage()
}

async function turnOff() {
  if (!(await manageV$.value.$validate()))
    return
  const answer = await run(() => api('/me/two-factor', { method: 'DELETE', body: { currentPassword: manage.currentPassword, code: manage.code.trim() } }), 'Could not turn it off.')
  if (answer === null)
    return
  markEnabled(false)
  recoveryCodesLeft.value = 0
  resetManage()
}

function resetManage() {
  manage.currentPassword = ''
  manage.code = ''
  manageV$.value.$reset()
}
</script>
