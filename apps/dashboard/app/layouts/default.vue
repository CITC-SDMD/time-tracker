<script setup lang="ts">
import { ROLE_LABEL } from 'shared'

const { me, isOic, logout } = useAuth()

const links = computed(() => [
  { to: '/', label: 'Overview' },
  { to: '/employees/manage', label: 'Manage employees' },
  ...(isOic.value
    ? [{ to: '/settings', label: 'Settings' }, { to: '/audit', label: 'Audit log' }]
    : []),
])
</script>

<template>
  <div>
    <header class="border-b border-slate-200 bg-white">
      <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
        <p class="font-semibold">
          Time Tracker
        </p>
        <nav class="flex flex-wrap gap-4 text-sm">
          <NuxtLink
            v-for="link in links"
            :key="link.to"
            :to="link.to"
            class="text-slate-600 hover:text-slate-900"
            exact-active-class="font-medium text-slate-900 underline"
          >
            {{ link.label }}
          </NuxtLink>
        </nav>
        <div class="ml-auto flex items-center gap-3 text-sm">
          <span class="text-slate-500">{{ me?.name }} · {{ me ? ROLE_LABEL[me.role] : '' }}</span>
          <button
            class="rounded bg-slate-100 px-3 py-1 hover:bg-slate-200"
            @click="logout"
          >
            Log out
          </button>
        </div>
      </div>
    </header>
    <slot />
  </div>
</template>
