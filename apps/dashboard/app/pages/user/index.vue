<template>
  <div>
    <UiPageHeader
      title="Overview"
      :description="scope === 'organization' ? 'Everyone in the organization, right now and today.' : scope === 'team' ? 'You and everyone who reports to you, right now and today.' : 'You, right now and today.'"
    >
      <template #actions>
        <FormButton
          variant="secondary"
          :loading="refreshing"
          @click="load"
        >
          Refresh
        </FormButton>
      </template>
    </UiPageHeader>

    <UiAlert
      v-if="error && loaded"
      variant="danger"
      class="mb-6"
    >
      {{ error }}
    </UiAlert>

    <section
      class="grid grid-cols-1 gap-4 sm:grid-cols-3"
      aria-label="Who is working now"
    >
      <UiStatCard
        label="Tracking now"
        :value="cards.tracking"
      />
      <UiStatCard
        label="Idle now"
        :value="cards.idle"
      />
      <UiStatCard
        label="Not tracking"
        :value="cards.notTracking"
        hint="Includes offline and paused"
      />
    </section>

    <section
      class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3"
      aria-label="Today's totals"
    >
      <UiStatCard
        label="Tracked today"
        :value="formatDuration(totals.tracked)"
      />
      <UiStatCard
        label="Active today"
        :value="formatDuration(totals.active)"
      />
      <UiStatCard
        label="Idle today"
        :value="formatDuration(totals.idle)"
      />
    </section>

    <h2 class="mb-3 mt-10 text-base/7 font-semibold text-gray-900 dark:text-white">
      People
    </h2>
    <UiTable
      :columns="COLUMNS"
      :rows="employees"
      :loading="!loaded"
      :error="loaded ? null : error"
      :row-link="(row) => office.to(`/user/employees/${row.id}`)"
      empty-title="No one to show yet"
      empty-description="People you add will appear here once they log in to the desktop app."
    >
      <template #cell-name="{ row }">
        <UiLink :to="office.to(`/user/employees/${row.id}`)">
          {{ row.name }}
        </UiLink>
      </template>
      <template #cell-role="{ row }">
        {{ row.role }}
      </template>
      <template #cell-status="{ row }">
        <UiBadge
          dot
          :variant="LIVE_STATUS_VARIANT[row.status as EmployeeListItem['status']]"
        >
          {{ statusText(row as EmployeeListItem) }}
        </UiBadge>
        <UiBadge
          v-if="row.environment && ENVIRONMENT_LABEL[row.environment as Environment]"
          class="ml-2"
          variant="warning"
        >
          {{ ENVIRONMENT_LABEL[row.environment as Environment] }}
        </UiBadge>
      </template>
      <template #cell-trackedSeconds="{ row }">
        {{ formatDuration(row.trackedSeconds) }}
      </template>
      <template #cell-activeSeconds="{ row }">
        {{ formatDuration(row.activeSeconds) }}
      </template>
      <template #cell-idleSeconds="{ row }">
        {{ formatDuration(row.idleSeconds) }}
      </template>
      <template #cell-lastActivityAt="{ row }">
        {{ formatTime(row.lastActivityAt) }}
      </template>
    </UiTable>
  </div>
</template>

<script setup lang="ts">
import type { EmployeeListItem, Environment } from 'shared'

definePageMeta({
  layout: 'user',
  alias: ['/platform/organizations/:orgId/office'],
})

// Who is working now and today's totals, over the signed-in person's own reach: a role that reaches the whole
// organization sees everyone, one that reaches a team only that team, and someone with neither only themselves
// (§12 Phase 6). Refreshes every 60 s while the browser tab is visible.
const { api } = useApi()
const { scope } = useAccess()
const office = useOffice()
const { formatDuration, formatTime } = useFormat()

const COLUMNS = [
  { key: 'name', label: 'Name' },
  { key: 'role', label: 'Role' },
  { key: 'status', label: 'Status' },
  { key: 'trackedSeconds', label: 'Tracked', align: 'right' as const },
  { key: 'activeSeconds', label: 'Active', align: 'right' as const },
  { key: 'idleSeconds', label: 'Idle', align: 'right' as const },
  { key: 'currentApp', label: 'Current app' },
  { key: 'lastActivityAt', label: 'Last activity' },
]

const employees = ref<EmployeeListItem[]>([])
const loaded = ref(false)
const refreshing = ref(false)
const error = ref<string | null>(null)

function statusText(e: EmployeeListItem): string {
  return e.status === 'offline'
    ? `Offline (last seen ${formatTime(e.lastActivityAt)})`
    : LIVE_STATUS_LABEL[e.status]
}

const cards = computed(() => {
  const list = employees.value
  const tracking = list.filter(e => e.status === 'active').length
  const idle = list.filter(e => e.status === 'idle').length
  return { tracking, idle, notTracking: list.length - tracking - idle }
})

const totals = computed(() => ({
  tracked: employees.value.reduce((sum, e) => sum + e.trackedSeconds, 0),
  active: employees.value.reduce((sum, e) => sum + e.activeSeconds, 0),
  idle: employees.value.reduce((sum, e) => sum + e.idleSeconds, 0),
}))

async function load() {
  refreshing.value = true
  try {
    employees.value = await api<EmployeeListItem[]>('/employees')
    error.value = null
  }
  catch (e) {
    error.value = messageOf(e, 'Could not load the overview.')
  }
  finally {
    loaded.value = true
    refreshing.value = false
  }
}

let timer: ReturnType<typeof setInterval> | undefined

function onVisible() {
  if (document.visibilityState === 'visible')
    load()
}

onMounted(() => {
  load()
  timer = setInterval(() => {
    if (document.visibilityState === 'visible')
      load()
  }, 60_000)
  document.addEventListener('visibilitychange', onVisible)
})

onBeforeUnmount(() => {
  clearInterval(timer)
  document.removeEventListener('visibilitychange', onVisible)
})
</script>
