<template>
  <div class="rounded-2xl border border-gray-200/70 bg-white/90 px-2 py-2 text-center shadow-sm shadow-gray-900/5 dark:border-white/10 dark:bg-gray-900/80 dark:shadow-none">
    <dt class="text-xs text-gray-500 dark:text-gray-400">
      {{ label }}
    </dt>
    <dd class="font-mono text-sm font-medium tabular-nums">
      {{ value }}
    </dd>
  </div>
</template>

<script setup lang="ts">
// One small number with its label (Tracked, Active, Idle).
defineProps<{ label: string, value: string }>()
</script>
