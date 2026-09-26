<template>
  <div
    class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b border-primary-500/30 bg-primary-500/10 px-4 py-2 text-sm/6 text-gray-900 sm:px-6 lg:px-8 dark:text-white"
    role="status"
  >
    <p>
      Viewing <span class="font-semibold">{{ organization?.name ?? 'an organization' }}</span> as platform staff
      <span v-if="readOnly">(look only, nothing can be changed)</span>
      <span v-else>(you can change things here as its admin)</span>
    </p>
    <UiLink to="/platform">
      Back to organizations
    </UiLink>
  </div>
</template>

<script setup lang="ts">
// Shown on every page of an opened office (docs/DEVELOPMENT_PLAN.md §9.4), so a superadmin never mistakes it for
// their own. Reading is not logged; changes are audited in that organization with their name.
const { organization } = useOffice()
const { readOnly } = useAccess()
</script>
