<template>
  <div class="flex grow flex-col gap-y-5 overflow-y-auto px-6 pb-4">
    <div class="flex h-16 shrink-0 flex-col justify-center">
      <span class="truncate text-lg font-semibold tracking-tight text-primary-600 dark:text-primary-500">
        {{ brand }}
      </span>
      <span
        v-if="subtitle"
        class="truncate text-xs/4 text-gray-500 dark:text-gray-400"
      >
        {{ subtitle }}
      </span>
    </div>
    <nav
      class="flex flex-1 flex-col"
      aria-label="Main"
    >
      <ul
        role="list"
        class="flex flex-1 flex-col gap-y-7"
      >
        <li>
          <ul
            role="list"
            class="-mx-2 space-y-1"
          >
            <li
              v-for="item in items"
              :key="item.name"
            >
              <UiNavLink
                :to="item.to"
                :current="isCurrent(route.path, item.to)"
                @click="emit('navigate')"
              >
                <component
                  :is="item.icon"
                  class="size-6 shrink-0"
                  aria-hidden="true"
                />
                {{ item.name }}
              </UiNavLink>
            </li>
          </ul>
        </li>
      </ul>
    </nav>
  </div>
</template>

<script setup lang="ts">
import type { Component } from 'vue'

// The sidebar of the signed-in person: their organization's name and the pages their permissions allow (the profile
// is in the user menu at the top). Used by the desktop sidebar and the mobile drawer of layouts/user.vue.
defineProps<{
  brand: string
  subtitle?: string
  items: Array<{ name: string, to: string, icon: Component }>
}>()

const emit = defineEmits<{ navigate: [] }>()

const route = useRoute()
</script>
