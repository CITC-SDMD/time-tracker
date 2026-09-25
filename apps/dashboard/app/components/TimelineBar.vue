<template>
  <div>
    <div
      class="relative h-10 overflow-hidden rounded bg-gray-100 dark:bg-white/10"
      role="img"
      aria-label="Timeline of the day"
    >
      <div
        v-for="b in blocks"
        :key="b.key"
        class="absolute inset-y-0"
        :style="{ left: `${b.left}%`, width: `${b.width}%`, backgroundColor: b.colour }"
        :title="b.title"
      />
    </div>
    <div class="mt-1 flex justify-between text-xs text-gray-500 dark:text-gray-400">
      <span>{{ formatTime(firstAt) }}</span>
      <span>{{ formatTime(lastAt) }}</span>
    </div>
    <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-gray-300">
      <li
        v-for="[name, colour] in legend"
        :key="name"
        class="flex items-center gap-1.5"
      >
        <span
          class="inline-block h-2.5 w-2.5 rounded-sm"
          :style="{ backgroundColor: colour }"
        />
        {{ name }}
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import type { TimelineSegment } from 'shared'

// One horizontal bar for a day: a coloured block per merged segment, placed by its real time
// between the first and last activity, so gaps stay empty. Plain divs with percentage widths
// (docs/DEVELOPMENT_PLAN.md §12 Phase 6) — no chart library.
const props = defineProps<{
  segments: TimelineSegment[]
  firstAt: string | null
  lastAt: string | null
}>()

const { formatTime, formatDuration } = useFormat()

const PALETTE = ['#2563eb', '#16a34a', '#9333ea', '#ea580c', '#0891b2', '#db2777', '#65a30d', '#4f46e5', '#ca8a04', '#0d9488']
const IDLE_COLOUR = '#cbd5e1'

// The same app always gets the same colour, on every day and for every person.
function colourOf(segment: TimelineSegment): string {
  if (segment.kind === 'idle')
    return IDLE_COLOUR
  let hash = 0
  for (const ch of segment.label)
    hash = (hash * 31 + ch.charCodeAt(0)) >>> 0
  return PALETTE[hash % PALETTE.length]!
}

const blocks = computed(() => {
  if (!props.firstAt || !props.lastAt)
    return []
  const from = new Date(props.firstAt).getTime()
  const span = Math.max(1, new Date(props.lastAt).getTime() - from)
  return props.segments.map((s) => {
    const start = new Date(s.startedAt).getTime()
    const end = new Date(s.endedAt).getTime()
    return {
      key: s.startedAt + s.label,
      left: ((start - from) / span) * 100,
      // Never thinner than a sliver, or a 20-second block would vanish.
      width: Math.max(0.25, ((end - start) / span) * 100),
      colour: colourOf(s),
      title: `${s.label}${s.title ? ` — ${s.title}` : ''}\n${formatTime(s.startedAt)}–${formatTime(s.endedAt)} · ${formatDuration(s.durationSeconds)}`,
    }
  })
})

const legend = computed(() => {
  const seen = new Map<string, string>()
  for (const s of props.segments) {
    const name = s.kind === 'idle' ? 'Idle' : s.label
    if (!seen.has(name))
      seen.set(name, colourOf(s))
  }
  return [...seen.entries()]
})
</script>
