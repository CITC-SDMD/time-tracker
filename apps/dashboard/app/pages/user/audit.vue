<template>
  <AuditLog
    endpoint="/admin/audit"
    title="Audit log"
    description="Who did what in the organization, newest first. Names stay in the log after an account is deleted."
  />
</template>

<script setup lang="ts">
definePageMeta({
  layout: 'user',
  permission: 'audit.view',
  alias: ['/platform/organizations/:orgId/office/audit'],
})
</script>
