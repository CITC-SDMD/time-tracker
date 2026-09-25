<template>
  <div class="border-b border-gray-200 dark:border-white/10">
    <div
      class="-mb-px flex gap-x-6 overflow-x-auto"
      role="tablist"
    >
      <FormButton
        v-for="tab in tabs"
        :key="tab.key"
        variant="tab"
        :selected="model === tab.key"
        role="tab"
        :aria-selected="model === tab.key"
        @click="model = tab.key"
      >
        {{ tab.label }}
      </FormButton>
    </div>
  </div>
</template>

<script setup lang="ts">
// Switches between views of the same page. Use `v-model` with the key of the selected tab.
defineProps<{
  tabs: Array<{ key: string, label: string }>
}>()

const model = defineModel<string>({ required: true })
</script>
