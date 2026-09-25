<template>
  <div>
    <UiLink
      to="/user"
      variant="muted"
      class="text-sm/6"
    >
      ← Overview
    </UiLink>

    <UiAlert
      v-if="forbidden"
      variant="danger"
      class="mt-4"
    >
      You cannot view this person. They are not in your part of the organisation.
    </UiAlert>

    <UiSpinner
      v-else-if="!person && !error"
    />

    <UiAlert
      v-else-if="!person && error"
      variant="danger"
      class="mt-4"
    >
      {{ error }}
    </UiAlert>

    <template v-else-if="person">
      <UiPageHeader
        class="mt-4"
        :title="person.name"
      >
        <template #below>
          <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm/6 text-gray-500 dark:text-gray-400">
            <span>{{ ROLE_LABEL[person.role] }}</span>
            <span v-if="person.managerName">· Reports to {{ person.managerName }}</span>
            <UiBadge
              dot
              :variant="LIVE_STATUS_VARIANT[person.status]"
            >
              {{ LIVE_STATUS_LABEL[person.status] }}
            </UiBadge>
            <UiBadge
              v-if="person.accountStatus === 'inactive'"
              variant="neutral"
            >
              Deactivated
            </UiBadge>
          </p>
        </template>
      </UiPageHeader>

      <UiCard class="mb-6">
        <div class="flex flex-wrap items-end gap-3">
          <FormButton
            :variant="isToday ? 'primary' : 'secondary'"
            @click="day = today()"
          >
            Today
          </FormButton>
          <FormButton
            :variant="isYesterday ? 'primary' : 'secondary'"
            @click="day = shiftDay(today(), -1)"
          >
            Yesterday
          </FormButton>
          <div class="w-44">
            <FormInput
              v-model="day"
              label="Pick a date"
              type="date"
            />
          </div>
          <p class="pb-1.5 text-sm/6 text-gray-500 dark:text-gray-400">
            {{ formatDay(day) }} · office time
          </p>
        </div>
      </UiCard>

      <UiAlert
        v-if="error"
        variant="danger"
        class="mb-6"
      >
        {{ error }}
      </UiAlert>

      <UiSpinner v-if="loading" />

      <template v-else>
        <section
          class="grid grid-cols-1 gap-4 sm:grid-cols-3"
          aria-label="Totals for the day"
        >
          <UiStatCard
            label="Tracked"
            :value="formatDuration(summary?.trackedSeconds ?? 0)"
          />
          <UiStatCard
            label="Active"
            :value="formatDuration(activeSeconds)"
          />
          <UiStatCard
            label="Idle"
            :value="formatDuration(summary?.idleSeconds ?? 0)"
          />
        </section>

        <UiEmptyState
          v-if="!summary && segments.length === 0"
          title="No tracked time on this day"
          description="Nothing was recorded, or the desktop app has not synced yet."
        />

        <template v-else>
          <h2 class="mb-3 mt-10 text-base/7 font-semibold text-gray-900 dark:text-white">
            Apps
          </h2>
          <UiCard>
            <ul
              v-if="apps.length"
              class="space-y-3"
            >
              <li
                v-for="a in apps"
                :key="a.name"
              >
                <div class="flex justify-between text-sm/6">
                  <span class="text-gray-900 dark:text-white">{{ a.name }}</span>
                  <span class="tabular-nums text-gray-500 dark:text-gray-400">{{ formatDuration(a.seconds) }}</span>
                </div>
                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                  <div
                    class="h-full rounded-full bg-primary-500"
                    :style="{ width: `${activeSeconds ? Math.round((a.seconds / activeSeconds) * 100) : 0}%` }"
                  />
                </div>
              </li>
            </ul>
            <p
              v-else
              class="text-sm/6 text-gray-500 dark:text-gray-400"
            >
              No application time on this day.
            </p>
          </UiCard>

          <h2 class="mb-3 mt-10 text-base/7 font-semibold text-gray-900 dark:text-white">
            Timeline
          </h2>
          <UiCard>
            <TimelineBar
              :segments="segments"
              :first-at="firstAt"
              :last-at="lastAt"
            />
          </UiCard>

          <h2 class="mb-3 mt-10 text-base/7 font-semibold text-gray-900 dark:text-white">
            Sessions
          </h2>
          <UiTable
            :columns="COLUMNS"
            :rows="visibleSegments"
            id-key="startedAt"
            empty-title="No sessions on this day"
          >
            <template #cell-time="{ row }">
              {{ formatTime(row.startedAt) }}–{{ formatTime(row.endedAt) }}
            </template>
            <template #cell-label="{ row }">
              <UiBadge
                v-if="row.kind === 'idle'"
                variant="warning"
              >
                {{ row.label }}
              </UiBadge>
              <span v-else>{{ row.label }}</span>
            </template>
            <template #cell-title="{ row }">
              <span class="block max-w-md truncate">{{ row.title ?? '—' }}</span>
            </template>
            <template #cell-durationSeconds="{ row }">
              {{ formatDuration(row.durationSeconds) }}
            </template>
          </UiTable>
          <div
            v-if="segments.length > shown"
            class="mt-4 text-center"
          >
            <FormButton
              variant="secondary"
              @click="shown += PAGE"
            >
              Show more ({{ segments.length - shown }} left)
            </FormButton>
          </div>
        </template>
      </template>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ROLE_LABEL, type DailySummary, type EmployeeListItem, type TimelineResponse, type TimelineSegment } from 'shared'

definePageMeta({
  layout: 'user',
})

// One person's day (docs/DEVELOPMENT_PLAN.md §12 Phase 6): totals, top apps, timeline and the
// list of blocks. Reachable only for people in the caller's own visible set (self included) — the
// API says 403 otherwise, and this page then shows nothing but that.
const route = useRoute()
const { api } = useApi()
const { formatDuration, formatTime, formatDay, today, shiftDay } = useFormat()

const COLUMNS = [
  { key: 'time', label: 'Time' },
  { key: 'label', label: 'App' },
  { key: 'title', label: 'Window', nowrap: false },
  { key: 'durationSeconds', label: 'Duration', align: 'right' as const },
]

const id = computed(() => String(route.params.id))
const person = ref<EmployeeListItem | null>(null)
const forbidden = ref(false)
const error = ref<string | null>(null)
const loading = ref(false)

const day = ref(today())
const summary = ref<DailySummary | null>(null)
const segments = ref<TimelineSegment[]>([])
const firstAt = ref<string | null>(null)
const lastAt = ref<string | null>(null)

const PAGE = 50
const shown = ref(PAGE)
const visibleSegments = computed(() => segments.value.slice(0, shown.value))

const TOP_APPS = 5

const apps = computed(() => {
  const s = summary.value
  if (!s)
    return []
  const all = Object.entries(s.apps)
    .map(([key, seconds]) => ({ name: s.appNames[key] ?? key, seconds }))
    .sort((a, b) => b.seconds - a.seconds)
  const top = all.slice(0, TOP_APPS)
  const otherSeconds = all.slice(TOP_APPS).reduce((sum, a) => sum + a.seconds, 0)
  return otherSeconds > 0 ? [...top, { name: 'Other', seconds: otherSeconds }] : top
})

const activeSeconds = computed(() => summary.value?.activeSeconds ?? 0)

let loadToken = 0

async function load() {
  const token = ++loadToken
  loading.value = true
  error.value = null
  try {
    // Ask the API for the day first; 403 here is the "outside your hierarchy" answer.
    const [summaries, firstPage] = await Promise.all([
      api<DailySummary[]>(`/employees/${id.value}/summary`, { query: { from: day.value, to: day.value } }),
      api<TimelineResponse>(`/employees/${id.value}/timeline`, { query: { day: day.value } }),
    ])
    const all = [...firstPage.segments]
    let cursor = firstPage.nextCursor
    // A very busy day is more than one page; fetch the rest so the bar and list are complete.
    while (cursor !== null) {
      const next = await api<TimelineResponse>(`/employees/${id.value}/timeline`, { query: { day: day.value, cursor } })
      all.push(...next.segments)
      cursor = next.nextCursor
    }
    if (token !== loadToken)
      return
    summary.value = summaries[0] ?? null
    segments.value = all
    firstAt.value = firstPage.firstActivityAt
    lastAt.value = firstPage.lastActivityAt
    shown.value = PAGE
    forbidden.value = false
  }
  catch (e) {
    if (token !== loadToken)
      return
    summary.value = null
    segments.value = []
    if (statusOf(e) === 403)
      forbidden.value = true
    else
      error.value = messageOf(e, 'Could not load this day.')
  }
  finally {
    if (token === loadToken)
      loading.value = false
  }
}

async function loadPerson() {
  try {
    const list = await api<EmployeeListItem[]>('/employees', { query: { includeDeactivated: 1 } })
    person.value = list.find(e => e.id === id.value) ?? null
    if (!person.value)
      forbidden.value = true
  }
  catch (e) {
    error.value = messageOf(e, 'Could not load this person.')
  }
}

const isToday = computed(() => day.value === today())
const isYesterday = computed(() => day.value === shiftDay(today(), -1))

onMounted(async () => {
  await loadPerson()
  if (!forbidden.value && person.value)
    await load()
})

watch(day, () => {
  if (day.value && !forbidden.value && person.value)
    load()
})
</script>
