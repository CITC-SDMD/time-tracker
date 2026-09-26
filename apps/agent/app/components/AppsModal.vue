<template>
  <div
    v-if="open"
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
    role="dialog"
    aria-modal="true"
    aria-label="Apps today"
    @click.self="$emit('close')"
  >
    <div class="flex max-h-full w-full max-w-sm flex-col rounded-2xl bg-white p-4 shadow-xl dark:bg-gray-900">
      <h2 class="mb-2 text-sm font-semibold text-gray-500 dark:text-gray-400">
        Apps today
      </h2>
      <ul class="min-h-0 flex-1 space-y-1 overflow-y-auto text-sm">
        <li
          v-for="app in apps"
          :key="app.name"
          class="flex justify-between gap-2"
        >
          <span class="truncate">{{ app.name }}</span>
          <span class="shrink-0 tabular-nums text-gray-500 dark:text-gray-400">{{ formatDuration(app.seconds) }}</span>
        </li>
      </ul>
      <UiButton
        ref="closeButton"
        variant="primary"
        block
        class="mt-3"
        @click="$emit('close')"
      >
        Close
      </UiButton>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { AppTimeDto } from '~/composables/useTracking'

// Every app used today, opened from the "+N more" link on the Today screen. Escape closes it.
const props = defineProps<{ open: boolean, apps: AppTimeDto[] }>()

const emit = defineEmits<{ close: [] }>()

const closeButton = useTemplateRef<{ $el: HTMLElement }>('closeButton')

watch(() => props.open, async (open) => {
  if (open) {
    await nextTick()
    closeButton.value?.$el.focus()
  }
})

function onKey(event: KeyboardEvent) {
  if (props.open && event.key === 'Escape')
    emit('close')
}

onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>
