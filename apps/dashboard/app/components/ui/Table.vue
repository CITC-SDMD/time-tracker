<template>
  <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:shadow-none dark:ring-white/10">
    <UiSpinner v-if="loading" />
    <div
      v-else-if="error"
      class="p-4"
    >
      <UiAlert variant="danger">
        {{ error }}
      </UiAlert>
    </div>
    <UiEmptyState
      v-else-if="rows.length === 0"
      :title="emptyTitle"
      :description="emptyDescription"
    >
      <slot name="empty" />
    </UiEmptyState>
    <div
      v-else
      class="overflow-x-auto"
    >
      <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
        <thead class="bg-gray-50 dark:bg-white/5">
          <tr>
            <th
              v-for="col in columns"
              :key="col.key"
              scope="col"
              class="whitespace-nowrap px-4 py-3 text-xs/5 font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"
              :class="col.align === 'right' ? 'text-right' : 'text-left'"
            >
              {{ col.label }}
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
          <tr
            v-for="(row, index) in rows"
            :key="rowKey(row, index)"
            class="hover:bg-gray-50 dark:hover:bg-white/5"
            :class="rowLink?.(row) ? 'cursor-pointer' : ''"
            @click="openRow($event, row)"
          >
            <td
              v-for="col in columns"
              :key="col.key"
              class="px-4 py-3 text-sm/6 text-gray-700 dark:text-gray-300"
              :class="[col.align === 'right' ? 'text-right tabular-nums' : 'text-left', col.nowrap === false ? '' : 'whitespace-nowrap']"
            >
              <slot
                :name="`cell-${col.key}`"
                :row="row"
                :index="index"
              >
                {{ row[col.key] ?? '—' }}
              </slot>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts" generic="T extends Record<string, any>">
// Every table in the dashboard. Describe the columns, pass the rows, and override a cell with a
// `#cell-<key>="{ row }"` slot (for badges, links, buttons). Shows a spinner while `loading`,
// the message when `error` is set, and the empty state when there are no rows. With `rowLink` the whole
// row opens the page it returns (or does nothing when it returns null); links and buttons inside the row
// keep working on their own.
export interface TableColumn {
  key: string
  label: string
  align?: 'left' | 'right'
  nowrap?: boolean
}

const props = withDefaults(defineProps<{
  columns: TableColumn[]
  rows: T[]
  idKey?: string
  loading?: boolean
  error?: string | null
  emptyTitle?: string
  emptyDescription?: string
  rowLink?: (row: T) => string | null
}>(), {
  idKey: 'id',
  emptyTitle: 'Nothing to show',
})

function openRow(event: MouseEvent, row: T) {
  const to = props.rowLink?.(row)
  if (!to)
    return
  // a click on a link or control inside the row is that control's own; selecting text is not a click
  if ((event.target as HTMLElement).closest('a, button, input, select, textarea'))
    return
  if (window.getSelection()?.toString())
    return
  navigateTo(to)
}

function rowKey(row: T, index: number): string | number {
  return row[props.idKey] ?? index
}
</script>
