<template>
  <main class="flex h-dvh flex-col gap-2.5 overflow-hidden p-3">
    <UiPageHeader
      title="What this app tracks"
      subtitle="Please read this before tracking starts."
    />

    <div class="min-h-0 flex-1 overflow-y-auto">
      <WhatWeTrack :page="page" />
    </div>

    <p
      v-if="error"
      class="rounded-2xl bg-red-100 p-2.5 text-xs text-red-700 dark:bg-red-500/15 dark:text-red-300"
      role="alert"
    >
      {{ error }}
    </p>

    <div class="mt-auto space-y-2">
      <template v-if="page === 1">
        <UiButton
          variant="primary"
          block
          @click="page = 2"
        >
          Next
        </UiButton>
      </template>
      <template v-else>
        <UiButton
          variant="primary"
          block
          :disabled="busy"
          @click="accept"
        >
          I understand and accept
        </UiButton>
        <div class="flex gap-2">
          <UiButton
            class="flex-1"
            @click="page = 1"
          >
            Back
          </UiButton>
          <UiButton
            class="flex-1"
            :disabled="busy"
            @click="decline"
          >
            Log out
          </UiButton>
        </div>
      </template>
      <p class="text-center text-xs text-gray-500 dark:text-gray-400">
        {{ page }} of 2
      </p>
    </div>
  </main>
</template>

<script setup lang="ts">
const { acceptConsent, logout } = useAuth()

const page = ref<1 | 2>(1)
const busy = ref(false)
const error = ref<string | null>(null)

async function accept() {
  busy.value = true
  error.value = null
  try {
    await acceptConsent()
    await navigateTo('/')
  }
  catch (e) {
    error.value = describeLoginError(e)
  }
  finally {
    busy.value = false
  }
}

async function decline() {
  await logout()
  await navigateTo('/login')
}
</script>
