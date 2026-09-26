<template>
  <TransitionRoot
    as="template"
    :show="open"
    @after-leave="giveFocusBack"
  >
    <Dialog
      class="relative z-50"
      @close="close"
    >
      <TransitionChild
        as="template"
        enter="ease-out duration-200"
        enter-from="opacity-0"
        enter-to="opacity-100"
        leave="ease-in duration-150"
        leave-from="opacity-100"
        leave-to="opacity-0"
      >
        <div class="fixed inset-0 bg-gray-900/60" />
      </TransitionChild>

      <div class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex min-h-full items-end justify-center p-4 sm:items-center">
          <TransitionChild
            as="template"
            enter="ease-out duration-200"
            enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            enter-to="opacity-100 translate-y-0 sm:scale-100"
            leave="ease-in duration-150"
            leave-from="opacity-100 translate-y-0 sm:scale-100"
            leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
          >
            <DialogPanel
              class="w-full rounded-lg bg-white p-6 shadow-xl dark:bg-gray-900 dark:outline dark:-outline-offset-1 dark:outline-white/10"
              :class="wide ? 'max-w-5xl' : 'max-w-lg'"
            >
              <DialogTitle class="text-base/7 font-semibold text-gray-900 dark:text-white">
                {{ title }}
              </DialogTitle>
              <div class="mt-3">
                <slot />
              </div>
              <div
                v-if="$slots.footer"
                class="mt-6 flex flex-wrap justify-end gap-3"
              >
                <slot name="footer" />
              </div>
            </DialogPanel>
          </TransitionChild>
        </div>
      </div>
    </Dialog>
  </TransitionRoot>
</template>

<script setup lang="ts">
import { Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot } from '@headlessui/vue'

// A window over the page. Open it with `v-model="open"`; the body is the default slot and the
// buttons go in the `footer` slot. Escape or a click outside closes it, unless `persistent`
// (used while a request is running, so it cannot be dismissed half way).
const props = defineProps<{
  title: string
  persistent?: boolean
  /** a wide window, for a picture */
  wide?: boolean
}>()

const open = defineModel<boolean>({ default: false })

// keyboard users end up back on the button that opened the window, not at the top of the page
let opener: HTMLElement | null = null
watch(open, (isOpen) => {
  if (isOpen)
    opener = document.activeElement instanceof HTMLElement ? document.activeElement : null
}, { flush: 'sync' })

function giveFocusBack() {
  const target = opener
  opener = null
  // after the dialog's own focus handling has finished
  setTimeout(() => target?.isConnected && target.focus(), 0)
}

function close() {
  if (!props.persistent)
    open.value = false
}
</script>
