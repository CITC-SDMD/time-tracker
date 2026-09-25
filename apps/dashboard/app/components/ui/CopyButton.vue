<template>
  <FormButton
    variant="secondary"
    size="sm"
    @click="copy"
  >
    {{ copied ? 'Copied' : label }}
  </FormButton>
</template>

<script setup lang="ts">
// Puts `text` on the clipboard and says "Copied" for a moment (used for set-password links).
const props = withDefaults(defineProps<{
  text: string
  label?: string
}>(), {
  label: 'Copy',
})

const copied = ref(false)

async function copy() {
  try {
    await navigator.clipboard.writeText(props.text)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  }
  catch {
    // the browser refused (no permission or an insecure page): the text stays selectable on screen
  }
}
</script>
