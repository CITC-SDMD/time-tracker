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
        enter="ease-in-out duration-300"
        enter-from="opacity-0"
        enter-to="opacity-100"
        leave="ease-in-out duration-200"
        leave-from="opacity-100"
        leave-to="opacity-0"
      >
        <div class="fixed inset-0 bg-gray-900/60" />
      </TransitionChild>

      <div class="fixed inset-0 overflow-hidden">
        <div class="pointer-events-none fixed inset-y-0 right-0 flex max-w-full pl-10">
          <TransitionChild
            as="template"
            enter="transform transition ease-in-out duration-300"
            enter-from="translate-x-full"
            enter-to="translate-x-0"
            leave="transform transition ease-in-out duration-200"
            leave-from="translate-x-0"
            leave-to="translate-x-full"
          >
            <DialogPanel class="pointer-events-auto flex w-screen max-w-xl flex-col bg-white shadow-xl dark:bg-gray-900 dark:outline dark:-outline-offset-1 dark:outline-white/10">
              <div class="flex items-center justify-between gap-4 border-b border-gray-200 px-6 py-4 dark:border-white/10">
                <DialogTitle class="text-base/7 font-semibold text-gray-900 dark:text-white">
                  {{ title }}
                </DialogTitle>
                <FormButton
                  variant="plain"
                  class="-m-2 p-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                  :disabled="persistent"
                  @click="close"
                >
                  <span class="sr-only">Close panel</span>
                  <XMarkIcon
                    class="size-6"
                    aria-hidden="true"
                  />
                </FormButton>
              </div>
              <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                <slot />
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
import { XMarkIcon } from '@heroicons/vue/24/outline'

// A panel that slides in from the right, for longer forms (a permission list, a profile). Open it with `v-model="open"`;
// the body is the default slot and scrolls on its own while the title stays put. Escape or a click outside closes it,
// unless `persistent` (used while a request is running, so it cannot be dismissed half way).
const props = defineProps<{
  title: string
  persistent?: boolean
}>()

const open = defineModel<boolean>({ default: false })

// keyboard users end up back on the button that opened the panel, not at the top of the page
let opener: HTMLElement | null = null
watch(open, (isOpen) => {
  if (isOpen)
    opener = document.activeElement instanceof HTMLElement ? document.activeElement : null
}, { flush: 'sync' })

function giveFocusBack() {
  const target = opener
  opener = null
  setTimeout(() => target?.isConnected && target.focus(), 0)
}

function close() {
  if (!props.persistent)
    open.value = false
}
</script>
