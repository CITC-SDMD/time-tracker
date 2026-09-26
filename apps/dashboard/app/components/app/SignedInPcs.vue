<template>
  <UiCard class="space-y-4">
    <h2 class="text-base/7 font-semibold text-gray-900 dark:text-white">
      {{ title }}
    </h2>
    <p class="text-sm/6 text-gray-500 dark:text-gray-400">
      {{ description }}
    </p>

    <UiSpinner v-if="loading" />
    <FormError v-else-if="loadError">
      {{ loadError }}
    </FormError>
    <UiEmptyState
      v-else-if="devices.length === 0"
      title="No PC is signed in"
    />
    <ul
      v-else
      class="divide-y divide-gray-200 dark:divide-white/10"
    >
      <li
        v-for="device in devices"
        :key="device.deviceId"
        class="flex items-center justify-between gap-4 py-3"
      >
        <div class="min-w-0">
          <p class="truncate text-sm/6 font-medium text-gray-900 dark:text-white">
            {{ device.computerName ?? 'A PC' }}
            <span
              v-if="device.agentVersion"
              class="font-normal text-gray-500 dark:text-gray-400"
            >
              · app {{ device.agentVersion }}
            </span>
          </p>
          <p class="text-xs/5 text-gray-500 dark:text-gray-400">
            Last used {{ device.lastUsedAt ? formatDateTime(device.lastUsedAt) : 'never' }} · signed in {{ formatDateTime(device.signedInAt) }}
          </p>
        </div>
        <FormButton
          variant="danger"
          size="sm"
          @click="askSignOut(device)"
        >
          Sign out
        </FormButton>
      </li>
    </ul>

    <UiConfirmDialog
      v-model="confirmOpen"
      title="Sign this PC out?"
      :message="`${target?.computerName ?? 'This PC'} will ask for the password the next time the app needs to send data. Nothing already tracked is lost; it is sent after the next sign-in.`"
      confirm-label="Sign out"
      danger
      :loading="signingOut"
      :error="signOutError"
      @confirm="signOut"
    />
  </UiCard>
</template>

<script setup lang="ts">
import type { SignedInPc } from 'shared'

// The PCs a person is signed in on, each with a button that signs it out (a lost laptop, a PC that changed hands).
// `url` is the list: /me/devices for yourself, /admin/employees/{id}/devices for someone you manage; a sign-out is
// a DELETE on `${url}/{deviceId}`.
const props = defineProps<{
  url: string
  title: string
  description: string
}>()

const { api } = useApi()
const { formatDateTime } = useFormat()

const devices = ref<SignedInPc[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)

async function load() {
  loadError.value = null
  try {
    devices.value = await api<SignedInPc[]>(props.url)
  }
  catch (e) {
    loadError.value = messageOf(e, 'Could not load the signed-in PCs.')
  }
  finally {
    loading.value = false
  }
}
onMounted(load)
watch(() => props.url, load)

const confirmOpen = ref(false)
const target = ref<SignedInPc | null>(null)
const signingOut = ref(false)
const signOutError = ref<string | null>(null)

function askSignOut(device: SignedInPc) {
  target.value = device
  signOutError.value = null
  confirmOpen.value = true
}

async function signOut() {
  if (!target.value)
    return
  signingOut.value = true
  signOutError.value = null
  try {
    await api(`${props.url}/${target.value.deviceId}`, { method: 'DELETE' })
    confirmOpen.value = false
    await load()
  }
  catch (e) {
    signOutError.value = messageOf(e, 'Could not sign that PC out.')
  }
  finally {
    signingOut.value = false
  }
}
</script>
