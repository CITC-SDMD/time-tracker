<script setup lang="ts">
import type { SegmentDto } from '~/composables/useTracking'

const props = defineProps<{ segments: SegmentDto[] }>()

const PALETTE = ['#3b82f6', '#10b981', '#8b5cf6', '#f59e0b', '#ec4899', '#06b6d4', '#84cc16', '#ef4444']
const IDLE_COLOUR = '#cbd5e1'

// The same app always gets the same colour.
function colourFor(segment: SegmentDto) {
  if (segment.kind === 'IDLE')
    return IDLE_COLOUR
  let hash = 0
  for (const ch of segment.label)
    hash = (hash * 31 + ch.charCodeAt(0)) >>> 0
  return PALETTE[hash % PALETTE.length]
}

const span = computed(() => {
  const first = props.segments[0]
  const last = props.segments[props.segments.length - 1]
  return first && last ? Math.max(1, last.endedAt - first.startedAt) : 1
})

const bars = computed(() =>
  props.segments.map(s => ({
    ...s,
    colour: colourFor(s),
    // A sliver of a block still gets a visible sliver of the bar.
    width: Math.max(0.4, ((s.endedAt - s.startedAt) / span.value) * 100),
  })),
)

const time = (ms: number) => new Date(ms).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
</script>

<template>
  <div>
    <p
      v-if="!segments.length"
      class="text-sm text-slate-500"
    >
      Nothing recorded yet today.
    </p>
    <template v-else>
      <div
        class="flex h-4 w-full overflow-hidden rounded bg-slate-100"
        role="img"
        aria-label="Today's timeline"
      >
        <div
          v-for="(bar, i) in bars"
          :key="i"
          :style="{ width: `${bar.width}%`, backgroundColor: bar.colour }"
          :title="`${bar.label}, ${time(bar.startedAt)} to ${time(bar.endedAt)}`"
        />
      </div>
      <div class="mt-1 flex justify-between text-xs text-slate-500">
        <span>{{ time(segments[0]!.startedAt) }}</span>
        <span>{{ time(segments[segments.length - 1]!.endedAt) }}</span>
      </div>
      <ul class="mt-2 max-h-40 space-y-1 overflow-y-auto text-xs">
        <li
          v-for="(bar, i) in [...bars].reverse()"
          :key="i"
          class="flex items-center gap-2"
        >
          <span
            class="inline-block h-2.5 w-2.5 shrink-0 rounded-sm"
            :style="{ backgroundColor: bar.colour }"
          />
          <span class="truncate">{{ bar.label }}</span>
          <span class="ml-auto shrink-0 tabular-nums text-slate-500">{{ time(bar.startedAt) }} to {{ time(bar.endedAt) }}</span>
        </li>
      </ul>
    </template>
  </div>
</template>
