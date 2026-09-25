<template>
  <NuxtLayout>
    <UiThemeToggle
      v-if="PUBLIC_PAGES.includes(route.path)"
      class="fixed right-4 top-4 z-50"
    />
    <NuxtPage />
  </NuxtLayout>
</template>

<script setup lang="ts">
// the sign-in, forgot-password and reset pages have no layout, so the theme toggle sits in their corner
const PUBLIC_PAGES = ['/', '/forgot-password', '/reset-password']
const route = useRoute()

// the tab and screen readers name the page: "People · Time Tracker"
useHead({
  title: computed(() => titleFor(route.path)),
  titleTemplate: title => title ? `${title} · Time Tracker` : 'Time Tracker',
})
</script>
