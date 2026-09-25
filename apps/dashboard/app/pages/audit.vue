<script setup lang="ts">
import type { AuditLogEntry } from 'shared'

// Audit log (docs/DEVELOPMENT_PLAN.md §12 Phase 6, OIC only): who did what to whom across the
// whole hierarchy, newest first, 50 at a time.
const { api } = useApi()
const { formatDateTime } = useFormat()

interface AuditPage {
  entries: AuditLogEntry[]
  nextCursor: number | null
}

const entries = ref<AuditLogEntry[]>([])
const nextCursor = ref<number | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

const ACTION_LABEL: Record<string, string> = {
  'employee.created': 'Added an account',
  'employee.deactivated': 'Deactivated an account',
  'employee.reactivated': 'Reactivated an account',
  'employee.deleted': 'Deleted an account',
  'settings.updated': 'Changed the office settings',
  'timeline.viewed': 'Viewed a timeline',
  'password.reset': 'Set or reset their password',
}

function detailsText(entry: AuditLogEntry): string {
  const d = entry.details
  if (!d)
    return ''
  return Object.entries(d).map(([k, v]) => `${k}: ${String(v)}`).join(', ')
}

async function load(cursor: number | null = null) {
  loading.value = true
  try {
    const page = await api<AuditPage>('/admin/audit', { query: cursor ? { cursor } : {} })
    entries.value = cursor ? [...entries.value, ...page.entries] : page.entries
    nextCursor.value = page.nextCursor
    error.value = null
  }
  catch (e) {
    error.value = messageOf(e, 'Could not load the audit log.')
  }
  finally {
    loading.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <main class="mx-auto max-w-6xl space-y-4 p-4">
    <header>
      <h1 class="text-lg font-semibold">
        Audit log
      </h1>
      <p class="text-sm text-slate-500">
        Newest first. Times are in the office timezone.
      </p>
    </header>

    <p
      v-if="error"
      class="text-sm text-red-600"
      role="alert"
    >
      {{ error }}
    </p>

    <section class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table class="w-full min-w-[40rem] text-left text-sm">
        <thead class="border-b border-slate-200 text-slate-500">
          <tr>
            <th class="px-3 py-2 font-medium">
              When
            </th>
            <th class="px-3 py-2 font-medium">
              Who
            </th>
            <th class="px-3 py-2 font-medium">
              What
            </th>
            <th class="px-3 py-2 font-medium">
              About
            </th>
            <th class="px-3 py-2 font-medium">
              Details
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="e in entries"
            :key="e.id"
            class="border-b border-slate-100 last:border-0"
          >
            <td class="whitespace-nowrap px-3 py-2 tabular-nums">
              {{ formatDateTime(e.at) }}
            </td>
            <td class="px-3 py-2">
              {{ e.actorName }}
            </td>
            <td class="px-3 py-2">
              {{ ACTION_LABEL[e.action] ?? e.action }}
            </td>
            <td class="px-3 py-2">
              {{ e.targetName ?? '—' }}
            </td>
            <td class="px-3 py-2 text-slate-500">
              {{ detailsText(e) }}
            </td>
          </tr>
          <tr v-if="!loading && !entries.length">
            <td
              colspan="5"
              class="px-3 py-4 text-center text-slate-500"
            >
              Nothing recorded yet.
            </td>
          </tr>
        </tbody>
      </table>
    </section>

    <button
      v-if="nextCursor"
      :disabled="loading"
      class="rounded bg-slate-100 px-3 py-1 text-sm hover:bg-slate-200 disabled:opacity-50"
      @click="load(nextCursor)"
    >
      {{ loading ? 'Loading…' : 'Load more' }}
    </button>
  </main>
</template>
