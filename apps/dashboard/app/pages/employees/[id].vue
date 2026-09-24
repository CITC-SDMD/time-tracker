<script setup lang="ts">
// Employee detail page — built out in Phase 6: date picker (Today/Yesterday/pick a
// date), totals, app breakdown, timeline, and a paginated session list.
const route = useRoute()
</script>

<template>
  <main class="mx-auto max-w-4xl space-y-4 p-4">
    <header>
      <h1 class="text-lg font-semibold">
        Employee {{ route.params.id }}
      </h1>
      <p class="text-sm text-slate-500">
        Totals, app breakdown and timeline land here in Phase 6.
      </p>
    </header>
  </main>
</template>
