<template>
  <div
    v-if="open"
    class="fixed inset-0 z-50 flex flex-col bg-black/85 p-3 backdrop-blur-sm"
    role="dialog"
    aria-modal="true"
    :aria-label="title"
    @click.self="$emit('close')"
  >
    <p class="mb-2 text-sm font-medium text-white">
      {{ title }}
    </p>
    <div class="flex min-h-0 flex-1 items-center justify-center">
      <img
        v-if="picture"
        :src="picture"
        :alt="title"
        class="max-h-full max-w-full rounded-2xl"
      >
      <p
        v-else
        class="text-sm text-white"
      >
        {{ failed ? 'This picture could not be loaded.' : 'Loading…' }}
      </p>
    </div>
    <div class="mt-2 flex justify-between gap-2">
      <UiButton
        :disabled="!hasPrevious"
        @click="$emit('step', -1)"
      >
        Previous
      </UiButton>
      <UiButton
        ref="closeButton"
        variant="primary"
        @click="$emit('close')"
      >
        Close
      </UiButton>
      <UiButton
        :disabled="!hasNext"
        @click="$emit('step', 1)"
      >
        Next
      </UiButton>
    </div>
  </div>
</template>

<script setup lang="ts">
import { invoke } from '@tauri-apps/api/core'

// One of the person's own screenshots at full size, over the page. Left and right arrows (or the buttons)
// move through the day, Escape closes it.
const props = defineProps<{
  open: boolean
  /** the picture to show, or `null` */
  id: string | null
  title: string
  hasPrevious: boolean
  hasNext: boolean
}>()

const emit = defineEmits<{ close: [], step: [by: number] }>()

const picture = ref<string | null>(null)
const failed = ref(false)
const closeButton = useTemplateRef<{ $el: HTMLElement }>('closeButton')

let request = 0

watch(() => [props.open, props.id] as const, async ([open, id]) => {
  picture.value = null
  failed.value = false
  if (!open || !id)
    return
  const mine = ++request
  try {
    const data = await invoke<string>('get_my_screenshot', { id, kind: 'image' })
    if (mine === request)
      picture.value = data
  }
  catch {
    if (mine === request)
      failed.value = true
  }
}, { immediate: true })

watch(() => props.open, async (open) => {
  if (open) {
    await nextTick()
    closeButton.value?.$el.focus()
  }
})

function onKey(event: KeyboardEvent) {
  if (!props.open)
    return
  if (event.key === 'ArrowLeft' && props.hasPrevious)
    emit('step', -1)
  else if (event.key === 'ArrowRight' && props.hasNext)
    emit('step', 1)
  else if (event.key === 'Escape')
    emit('close')
}

onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>
