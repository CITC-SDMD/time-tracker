<script setup lang="ts">
import { ROLE_LABEL, type DailySummary, type EmployeeListItem, type TimelineResponse, type TimelineSegment } from 'shared'

// One person's day (docs/DEVELOPMENT_PLAN.md §12 Phase 6): totals, top apps, timeline and the
// list of blocks. Reachable only for people in the caller's own visible set — the API says 403
// otherwise, and this page then shows nothing but that.
const route = useRoute()
const { api } = useApi()
const { formatDuration, formatTime, formatDay, today, shiftDay } = useFormat()

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
  if (!forbidden.value)
    await load()
})

watch(day, () => {
  if (day.value && !forbidden.value)
    load()
})
</script>

<template>
  <main class="mx-auto max-w-6xl space-y-4 p-4">
    <NuxtLink
      to="/"
      class="text-sm text-slate-500 underline"
    >
      ← Overview
    </NuxtLink>

    <p
      v-if="forbidden"
      class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700"
      role="alert"
    >
      You cannot view this person. They are not in your part of the organisation.
    </p>

    <template v-else>
      <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 class="text-lg font-semibold">
            {{ person?.name ?? '…' }}
          </h1>
          <p
            v-if="person"
            class="text-sm text-slate-500"
          >
            {{ ROLE_LABEL[person.role] }} · {{ formatDay(day) }}
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-sm">
          <button
            class="rounded px-3 py-1"
            :class="isToday ? 'bg-slate-900 text-white' : 'bg-slate-100 hover:bg-slate-200'"
            @click="day = today()"
          >
            Today
          </button>
          <button
            class="rounded px-3 py-1"
            :class="isYesterday ? 'bg-slate-900 text-white' : 'bg-slate-100 hover:bg-slate-200'"
            @click="day = shiftDay(today(), -1)"
          >
            Yesterday
          </button>
          <input
            v-model="day"
            type="date"
            :max="today()"
            class="rounded border border-slate-300 px-2 py-1"
            aria-label="Pick a date"
          >
        </div>
      </header>

      <p
        v-if="error"
        class="text-sm text-red-600"
        role="alert"
      >
        {{ error }}
      </p>

      <section class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-lg border border-slate-200 bg-white p-4">
          <p class="text-sm text-slate-500">
            Tracked
          </p>
          <p
            class="text-2xl font-semibold tabular-nums"
            data-testid="total-tracked"
          >
            {{ formatDuration(summary?.trackedSeconds ?? 0) }}
          </p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-4">
          <p class="text-sm text-slate-500">
            Active
          </p>
          <p
            class="text-2xl font-semibold tabular-nums"
            data-testid="total-active"
          >
            {{ formatDuration(summary?.activeSeconds ?? 0) }}
          </p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-4">
          <p class="text-sm text-slate-500">
            Idle
          </p>
          <p
            class="text-2xl font-semibold tabular-nums"
            data-testid="total-idle"
          >
            {{ formatDuration(summary?.idleSeconds ?? 0) }}
          </p>
        </div>
      </section>

      <section class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="mb-2 text-sm font-medium text-slate-500">
          Apps (active time)
        </h2>
        <p
          v-if="!apps.length"
          class="text-sm text-slate-500"
        >
          {{ loading ? 'Loading…' : 'No apps recorded on this day.' }}
        </p>
        <ul
          v-else
          class="space-y-2 text-sm"
        >
          <li
            v-for="a in apps"
            :key="a.name"
          >
            <div class="flex justify-between gap-2">
              <span class="truncate">{{ a.name }}</span>
              <span class="shrink-0 tabular-nums text-slate-500">{{ formatDuration(a.seconds) }}</span>
            </div>
            <div class="mt-1 h-1.5 rounded bg-slate-100">
              <div
                class="h-full rounded bg-slate-500"
                :style="{ width: `${activeSeconds ? Math.min(100, (a.seconds / activeSeconds) * 100) : 0}%` }"
              />
            </div>
          </li>
        </ul>
      </section>

      <section class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="mb-2 text-sm font-medium text-slate-500">
          Timeline
        </h2>
        <p
          v-if="!segments.length"
          class="text-sm text-slate-500"
        >
          {{ loading ? 'Loading…' : 'Nothing was tracked on this day.' }}
        </p>
        <template v-else>
          <TimelineBar
            :segments="segments"
            :first-at="firstAt"
            :last-at="lastAt"
          />

          <table class="mt-4 w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-slate-500">
              <tr>
                <th class="py-1 pr-3 font-medium">
                  Time
                </th>
                <th class="py-1 pr-3 font-medium">
                  App
                </th>
                <th class="py-1 pr-3 font-medium">
                  Window title
                </th>
                <th class="py-1 text-right font-medium">
                  Duration
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="s in segments.slice(0, shown)"
                :key="s.startedAt + s.label"
                class="border-b border-slate-100 last:border-0"
                :class="s.kind === 'IDLE' ? 'text-slate-500' : ''"
              >
                <td class="whitespace-nowrap py-1 pr-3 tabular-nums">
                  {{ formatTime(s.startedAt) }}–{{ formatTime(s.endedAt) }}
                </td>
                <td class="py-1 pr-3">
                  {{ s.label }}
                </td>
                <td class="max-w-xs truncate py-1 pr-3">
                  {{ s.title ?? '' }}
                </td>
                <td class="py-1 text-right tabular-nums">
                  {{ formatDuration(s.durationSeconds) }}
                </td>
              </tr>
            </tbody>
          </table>
          <button
            v-if="shown < segments.length"
            class="mt-3 rounded bg-slate-100 px-3 py-1 text-sm hover:bg-slate-200"
            @click="shown += PAGE"
          >
            Show more ({{ segments.length - shown }} left)
          </button>
        </template>
      </section>
    </template>
  </main>
</template>
