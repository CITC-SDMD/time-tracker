<template>
  <div>
    <UiPageHeader
      :title="title"
      :description="description"
    />

    <UiCard class="mb-6">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <FormSelect
          v-model="filters.action"
          label="Action"
          :options="ACTION_OPTIONS"
          placeholder="All actions"
        />
        <FormInput
          v-model="filters.q"
          label="Person"
          type="search"
          placeholder="Name of who did it or who it was done to"
        />
        <FormInput
          v-model="filters.from"
          label="From"
          type="date"
        />
        <FormInput
          v-model="filters.to"
          label="To"
          type="date"
          :errors="v$.to.$errors"
        />
      </div>
      <div
        v-if="filtered"
        class="mt-4"
      >
        <FormButton
          variant="link"
          @click="clearFilters"
        >
          Clear filters
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

    <UiTable
      :columns="COLUMNS"
      :rows="entries"
      :loading="!loaded"
      :error="loaded ? null : error"
      :empty-title="filtered ? 'No entries match these filters' : 'Nothing has been logged yet'"
    >
      <template #cell-at="{ row }">
        {{ formatDateTime(row.at) }}
      </template>
      <template #cell-action="{ row }">
        {{ auditActionLabel(row.action) }}
      </template>
      <template #cell-details="{ row }">
        {{ auditDetails(row as AuditLogEntry) || '—' }}
      </template>
      <template #cell-targetName="{ row }">
        {{ row.targetName ?? '—' }}
      </template>
    </UiTable>

    <div
      v-if="nextCursor !== null"
      class="mt-4 text-center"
    >
      <FormButton
        variant="secondary"
        :loading="loadingMore"
        @click="loadMore"
      >
        Load older entries
      </FormButton>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useVuelidate } from '@vuelidate/core'
import { helpers } from '@vuelidate/validators'
import type { AuditLogEntry, AuditLogPage } from 'shared'

// An audit log (docs/DEVELOPMENT_PLAN.md §9.1, §12 Phase 6), 50 entries a page, filtered by the server so
// "Load older entries" keeps working inside a filter. It is the organization's own log, or, on the platform pages, the
// platform's: the two pages differ only in the address they read from.
const props = defineProps<{
  endpoint: string
  title: string
  description: string
}>()

const { api } = useApi()
const { formatDateTime } = useFormat()

const COLUMNS = [
  { key: 'at', label: 'When' },
  { key: 'actorName', label: 'Who' },
  { key: 'action', label: 'Did' },
  { key: 'targetName', label: 'To' },
  { key: 'details', label: 'Details', nowrap: false },
]

const ACTION_OPTIONS = Object.entries(AUDIT_ACTION_LABEL).map(([value, label]) => ({ value, label }))

const filters = reactive({ action: '', q: '', from: '', to: '' })
const v$ = useVuelidate({
  to: { order: helpers.withMessage('"To" cannot be before "From".', () => !filters.from || !filters.to || filters.to >= filters.from) },
}, filters)

const entries = ref<AuditLogEntry[]>([])
const nextCursor = ref<number | null>(null)
const loaded = ref(false)
const loadingMore = ref(false)
const error = ref<string | null>(null)

const filtered = computed(() => !!(filters.action || filters.q || filters.from || filters.to))

function query(cursor?: number) {
  return {
    ...(filters.action ? { action: filters.action } : {}),
    ...(filters.q.trim() ? { q: filters.q.trim() } : {}),
    ...(filters.from ? { from: filters.from } : {}),
    ...(filters.to ? { to: filters.to } : {}),
    ...(cursor ? { cursor } : {}),
  }
}

// a newer request replaces an older one still in flight (typing quickly in the search box)
let requestId = 0

async function load() {
  const mine = ++requestId
  try {
    const page = await api<AuditLogPage>(props.endpoint, { query: query() })
    if (mine !== requestId)
      return
    entries.value = page.entries
    nextCursor.value = page.nextCursor
    error.value = null
  }
  catch (e) {
    if (mine === requestId)
      error.value = messageOf(e, 'Could not load the audit log.')
  }
  finally {
    if (mine === requestId)
      loaded.value = true
  }
}

async function loadMore() {
  if (nextCursor.value === null)
    return
  loadingMore.value = true
  try {
    const page = await api<AuditLogPage>(props.endpoint, { query: query(nextCursor.value) })
    entries.value.push(...page.entries)
    nextCursor.value = page.nextCursor
  }
  catch (e) {
    error.value = messageOf(e, 'Could not load older entries.')
  }
  finally {
    loadingMore.value = false
  }
}

function clearFilters() {
  filters.action = ''
  filters.q = ''
  filters.from = ''
  filters.to = ''
}

let debounce: ReturnType<typeof setTimeout> | undefined

watch(filters, () => {
  clearTimeout(debounce)
  v$.value.$touch()
  if (v$.value.$invalid)
    return
  debounce = setTimeout(load, 300)
})

onMounted(load)
onBeforeUnmount(() => clearTimeout(debounce))
</script>
