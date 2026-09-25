<template>
  <FormButton
    variant="plain"
    class="rounded-md p-2 text-gray-500 hover:text-gray-700 focus-visible:outline-2 focus-visible:outline-primary-500 dark:text-gray-400 dark:hover:text-white"
    :aria-label="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
    :title="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
    @click="toggle"
  >
    <SunIcon
      v-if="theme === 'dark'"
      class="size-5"
      aria-hidden="true"
    />
    <MoonIcon
      v-else
      class="size-5"
      aria-hidden="true"
    />
  </FormButton>
</template>

<script setup lang="ts">
import { MoonIcon, SunIcon } from '@heroicons/vue/24/outline'

// Switches between light and dark mode (see useTheme).
const { theme, init, toggle } = useTheme()

onMounted(init)
</script>
