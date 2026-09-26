<template>
  <AuditLog
    endpoint="/platform/audit"
    title="Platform audit log"
    description="What superadmins did, in any organization or on the platform itself, newest first."
  />
</template>

<script setup lang="ts">
definePageMeta({
  layout: 'user',
  platformPermission: 'platform.audit.view',
})
</script>
