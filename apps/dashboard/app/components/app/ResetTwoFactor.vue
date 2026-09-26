<template>
  <span>
    <FormButton
      :variant="variant"
      :size="size"
      @click="open = true"
    >
      Reset two-factor
    </FormButton>
    <UiConfirmDialog
      v-model="open"
      :title="`Reset two-factor sign-in for ${name}?`"
      message="Use this when they lost their phone and their recovery codes. Their authenticator setup is removed and they are signed out of the dashboard; they can sign in with their password and turn it on again."
      confirm-label="Reset"
      danger
      :loading="busy"
      :error="error"
      @confirm="reset"
    />
    <UiAlert
      v-if="done"
      class="mt-3"
    >
      Two-factor sign-in was reset for {{ name }}.
    </UiAlert>
  </span>
</template>

<script setup lang="ts">
// "Reset two-factor" for someone who lost their phone (docs/SECURITY_REVIEW.md): a DELETE on `url`, then a short note.
const props = withDefaults(defineProps<{
  url: string
  name: string
  variant?: 'secondary' | 'link' | 'danger'
  size?: 'md' | 'sm'
}>(), {
  variant: 'secondary',
  size: 'md',
})

const { api } = useApi()
const open = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)
const done = ref(false)

async function reset() {
  busy.value = true
  error.value = null
  try {
    await api(props.url, { method: 'DELETE' })
    open.value = false
    done.value = true
  }
  catch (e) {
    error.value = messageOf(e, 'Could not reset it.')
  }
  finally {
    busy.value = false
  }
}
</script>
