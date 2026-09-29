<template>
  <main class="flex h-dvh flex-col gap-2.5 overflow-hidden p-3">
    <UiPageHeader
      title="What this app tracks"
      back="/settings"
    />

    <div class="min-h-0 flex-1 overflow-y-auto">
      <WhatWeTrack :page="page" />
    </div>

    <UiPager
      class="mt-auto"
      :label="`${page} of 2`"
      :has-previous="page === 2"
      :has-next="page === 1"
      @previous="page = 1"
      @next="page = 2"
    />
  </main>
</template>

<script setup lang="ts">
// The same notice as the consent screen, read-only, opened from Settings.
const page = ref<1 | 2>(1)
</script>
