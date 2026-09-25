<template>
  <div>
    <UiPageHeader
      title="Reports"
      description="Totals for a range of days, in office time. Download any of them as a spreadsheet."
    >
      <template #actions>
        <FormButton
          variant="secondary"
          :loading="downloading"
          :disabled="!valid || !loaded"
          @click="download"
        >
          Download CSV
        </FormButton>
      </template>
    </UiPageHeader>

    <UiCard class="mb-6">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <FormInput
          v-model="filters.from"
          label="From"
          type="date"
          :errors="v$.from.$errors"
          @blur="v$.from.$touch()"
        />
        <FormInput
          v-model="filters.to"
          label="To"
          type="date"
          :errors="v$.to.$errors"
          @blur="v$.to.$touch()"
        />
        <FormSelect
          v-model="filters.uid"
          label="Person"
          :options="personOptions"
          placeholder="Everyone"
        />
      </div>
      <div class="mt-4 flex flex-wrap gap-2">
        <FormButton
          v-for="preset in PRESETS"
          :key="preset.label"
          variant="secondary"
          size="sm"
          @click="applyPreset(preset.days)"
        >
          {{ preset.label }}
        </FormButton>
      </div>
    </UiCard>

    <UiAlert
      v-if="error && loaded"
      variant="danger"
      class="mb-6"
    >
      {{ error }}
    </UiAlert>

    <UiTabs
      v-model="tab"
      :tabs="TABS"
      class="mb-6"
    />

    <section
      v-if="tab !== 'apps' && loaded && rows.length"
      class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3"
      aria-label="Totals for the range"
    >
      <UiStatCard
        label="Tracked"
        :value="formatDuration(sum('trackedSeconds'))"
      />
      <UiStatCard
        label="Active"
        :value="formatDuration(sum('activeSeconds'))"
      />
      <UiStatCard
        label="Idle"
        :value="formatDuration(sum('idleSeconds'))"
      />
    </section>

    <UiTable
      :columns="COLUMNS[tab as Tab]"
      :rows="rows"
      :loading="!loaded"
      :error="loaded ? null : error"
      :id-key="tab === 'daily' ? 'rowId' : tab === 'apps' ? 'app' : 'userId'"
      empty-title="Nothing tracked in this range"
      empty-description="Try a longer range, or check that people's desktop apps have synced."
    >
      <template #cell-day="{ row }">
        {{ formatDay(row.day) }}
      </template>
      <template #cell-name="{ row }">
        <UiLink :to="`/user/employees/${row.userId}`">
          {{ row.name }}
        </UiLink>
      </template>
      <template #cell-role="{ row }">
        {{ ROLE_LABEL[row.role as Role] }}
      </template>
      <template #cell-managerName="{ row }">
        {{ row.managerName ?? '—' }}
      </template>
      <template
        v-for="key in DURATION_KEYS"
        :key="key"
        #[`cell-${key}`]="{ row }"
      >
        {{ formatDuration(row[key]) }}
      </template>
      <template #cell-firstActivityAt="{ row }">
        {{ formatTime(row.firstActivityAt) }}
      </template>
      <template #cell-lastActivityAt="{ row }">
        {{ formatTime(row.lastActivityAt) }}
      </template>
    </UiTable>
  </div>
</template>

<script setup lang="ts">
import { useVuelidate } from '@vuelidate/core'
import { helpers, required } from '@vuelidate/validators'
import { ROLE_LABEL, type EmployeeListItem, type ReportAppRow, type ReportDailyRow, type ReportTeamRow, type Role } from 'shared'

definePageMeta({
  layout: 'user',
})

// Daily, app-usage and team reports for the people the signed-in person can see (docs/DEVELOPMENT_PLAN.md
// §12 Phase 11), read from the daily totals and limited to 92 days at a time.
const { api } = useApi()
const { formatDuration, formatTime, formatDay, today, shiftDay } = useFormat()

type Tab = 'daily' | 'apps' | 'team'

const MAX_DAYS = 92

const TABS = [
  { key: 'daily', label: 'Daily per person' },
  { key: 'apps', label: 'App usage' },
  { key: 'team', label: 'Team totals' },
]

const PRESETS = [
  { label: 'Last 7 days', days: 7 },
  { label: 'Last 30 days', days: 30 },
  { label: 'Last 90 days', days: 90 },
]

const COLUMNS: Record<Tab, Array<{ key: string, label: string, align?: 'left' | 'right' }>> = {
  daily: [
    { key: 'day', label: 'Date' },
    { key: 'name', label: 'Name' },
    { key: 'role', label: 'Role' },
    { key: 'trackedSeconds', label: 'Tracked', align: 'right' },
    { key: 'activeSeconds', label: 'Active', align: 'right' },
    { key: 'idleSeconds', label: 'Idle', align: 'right' },
    { key: 'firstActivityAt', label: 'First activity', align: 'right' },
    { key: 'lastActivityAt', label: 'Last activity', align: 'right' },
  ],
  apps: [
    { key: 'app', label: 'Application' },
    { key: 'seconds', label: 'Active time', align: 'right' },
    { key: 'people', label: 'People', align: 'right' },
  ],
  team: [
    { key: 'name', label: 'Name' },
    { key: 'role', label: 'Role' },
    { key: 'managerName', label: 'Manager' },
    { key: 'daysTracked', label: 'Days tracked', align: 'right' },
    { key: 'trackedSeconds', label: 'Tracked', align: 'right' },
    { key: 'activeSeconds', label: 'Active', align: 'right' },
    { key: 'idleSeconds', label: 'Idle', align: 'right' },
    { key: 'averageTrackedSeconds', label: 'Average a day', align: 'right' },
  ],
}

const DURATION_KEYS = ['trackedSeconds', 'activeSeconds', 'idleSeconds', 'averageTrackedSeconds', 'seconds'] as const

const tab = ref<string>('daily')
const filters = reactive({ from: shiftDay(today(), -6), to: today(), uid: '' })

const rules = {
  from: { required: helpers.withMessage('Choose the first day.', required) },
  to: {
    required: helpers.withMessage('Choose the last day.', required),
    order: helpers.withMessage('"To" cannot be before "From".', () => !filters.from || !filters.to || filters.to >= filters.from),
    length: helpers.withMessage(`Choose at most ${MAX_DAYS} days.`, () => !filters.from || !filters.to || filters.to <= shiftDay(filters.from, MAX_DAYS - 1)),
  },
}
const v$ = useVuelidate(rules, filters)
const valid = computed(() => !v$.value.$invalid)

const people = ref<EmployeeListItem[]>([])
const personOptions = computed(() => people.value.map(p => ({ value: p.id, label: p.name })))

const daily = ref<Array<ReportDailyRow & { rowId: string }>>([])
const apps = ref<ReportAppRow[]>([])
const team = ref<ReportTeamRow[]>([])
const loaded = ref(false)
const error = ref<string | null>(null)
const downloading = ref(false)

// the table shows one of three row shapes, chosen by the tab; its cells are typed by the slots below
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const rows = computed<Array<Record<string, any>>>(() => tab.value === 'apps' ? apps.value : tab.value === 'team' ? team.value : daily.value)

function sum(key: 'trackedSeconds' | 'activeSeconds' | 'idleSeconds'): number {
  return rows.value.reduce((total, row) => total + (row[key] ?? 0), 0)
}

function applyPreset(days: number) {
  filters.to = today()
  filters.from = shiftDay(filters.to, -(days - 1))
}

function query() {
  return { from: filters.from, to: filters.to, ...(filters.uid ? { uid: filters.uid } : {}) }
}

// the latest request wins when the tab or the dates change quickly
let requestId = 0

async function load() {
  if (!valid.value)
    return
  const mine = ++requestId
  const which = tab.value as Tab
  loaded.value = false
  error.value = null
  try {
    if (which === 'daily') {
      const result = await api<ReportDailyRow[]>('/reports/daily', { query: query() })
      if (mine === requestId)
        daily.value = result.map(r => ({ ...r, rowId: `${r.day}-${r.userId}` }))
    }
    else if (which === 'apps') {
      const result = await api<ReportAppRow[]>('/reports/apps', { query: query() })
      if (mine === requestId)
        apps.value = result
    }
    else {
      const result = await api<ReportTeamRow[]>('/reports/team', { query: query() })
      if (mine === requestId)
        team.value = result
    }
  }
  catch (e) {
    if (mine === requestId)
      error.value = messageOf(e, 'Could not load the report.')
  }
  finally {
    if (mine === requestId)
      loaded.value = true
  }
}

async function download() {
  downloading.value = true
  error.value = null
  try {
    const blob = await api<Blob>(`/reports/${tab.value}`, { query: { ...query(), format: 'csv' }, responseType: 'blob' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `${tab.value}-report-${filters.from}-to-${filters.to}.csv`
    link.click()
    URL.revokeObjectURL(url)
  }
  catch (e) {
    error.value = messageOf(e, 'Could not download the file.')
  }
  finally {
    downloading.value = false
  }
}

watch([() => filters.from, () => filters.to, () => filters.uid, tab], () => {
  v$.value.$touch()
  load()
})

onMounted(async () => {
  try {
    people.value = await api<EmployeeListItem[]>('/employees', { query: { includeDeactivated: 1 } })
  }
  catch {
    // the person filter just stays empty; the report itself reports its own errors
  }
  await load()
})
</script>
