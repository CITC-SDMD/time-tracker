<template>
  <button
    ref="root"
    type="button"
    class="group block w-full rounded text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
    :aria-label="`Open the screenshot taken at ${time}`"
    @click="$emit('open')"
  >
    <span class="block aspect-video overflow-hidden rounded bg-slate-100 ring-1 ring-slate-200 group-hover:ring-slate-400">
      <img
        v-if="picture"
        :src="picture"
        :alt="`Your screen at ${time}`"
        class="size-full object-cover"
      >
      <span
        v-else
        class="flex size-full items-center justify-center text-xs text-slate-400"
      >{{ failed ? 'Not available' : 'Loading…' }}</span>
    </span>
    <span class="mt-1 block text-xs tabular-nums text-slate-500">{{ time }}</span>
  </button>
</template>

<script setup lang="ts">
import { invoke } from '@tauri-apps/api/core'

// One small picture of the person's own day. It is fetched from the server when it scrolls into view.
const props = defineProps<{
  id: string
  time: string
}>()

defineEmits<{ open: [] }>()

const picture = ref<string | null>(null)
const failed = ref(false)
const root = useTemplateRef<HTMLElement>('root')

let observer: IntersectionObserver | undefined

async function load() {
  try {
    picture.value = await invoke<string>('get_my_screenshot', { id: props.id, kind: 'thumb' })
  }
  catch {
    failed.value = true
  }
}

onMounted(() => {
  if (!root.value || typeof IntersectionObserver === 'undefined') {
    load()
    return
  }
  observer = new IntersectionObserver((entries) => {
    if (entries.some(e => e.isIntersecting)) {
      observer?.disconnect()
      load()
    }
  })
  observer.observe(root.value)
})

onBeforeUnmount(() => observer?.disconnect())
</script>
