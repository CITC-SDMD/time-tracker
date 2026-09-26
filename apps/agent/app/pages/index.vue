<template>
  <main class="flex h-dvh flex-col gap-2.5 overflow-hidden p-3">
    <header class="flex items-center justify-between gap-2">
      <div class="flex min-w-0 items-center gap-2.5">
        <UiLogoMark small />
        <div class="min-w-0">
          <h1 class="text-base font-semibold leading-tight">
            Time Tracker
          </h1>
          <p class="truncate text-xs text-gray-500 dark:text-gray-400">
            {{ me?.name }}
          </p>
        </div>
      </div>
      <nav class="flex shrink-0 gap-1.5">
        <UiButton
          v-if="shots?.enabled || shots?.lastTakenAt"
          to="/screenshots"
          class="px-3! py-1.5!"
        >
          Screenshots
        </UiButton>
        <UiButton
          to="/settings"
          class="px-3! py-1.5!"
        >
          Settings
        </UiButton>
      </nav>
    </header>

    <p
      v-if="notice"
      class="flex items-start justify-between gap-2 rounded-2xl bg-primary-100 p-2.5 text-xs text-primary-900 dark:bg-primary-500/15 dark:text-primary-200"
    >
      <span>{{ notice }}</span>
      <button
        class="shrink-0 underline"
        @click="dismissNotice"
      >
        Dismiss
      </button>
    </p>
    <p
      v-if="resumedNotice"
      class="rounded-2xl bg-primary-100 p-2.5 text-xs text-primary-900 dark:bg-primary-500/15 dark:text-primary-200"
    >
      Tracking resumed after the app restarted.
    </p>
    <p
      v-if="error"
      class="rounded-2xl bg-red-100 p-2.5 text-xs text-red-700 dark:bg-red-500/15 dark:text-red-300"
    >
      {{ error }}
    </p>

    <UiCard class="flex items-center gap-4">
      <UiTooltip :text="ringHelp">
        <UiTimerRing :percent="activeShare ?? 0">
          <span
            class="font-mono text-lg font-semibold tabular-nums"
            data-testid="clock"
          >{{ workClock ?? clock?.tracked ?? '--:--:--' }}</span>
          <span class="text-[11px] leading-tight text-gray-500 dark:text-gray-400">{{ workClock ? 'this period' : 'tracked today' }}</span>
          <span
            v-if="activeShare !== null"
            class="text-xs font-medium text-primary-600 dark:text-primary-400"
          >{{ activeShare }}% active</span>
        </UiTimerRing>
      </UiTooltip>

      <div class="min-w-0 flex-1 space-y-2">
        <p class="flex items-center gap-2">
          <span
            class="inline-block h-2.5 w-2.5 shrink-0 rounded-full"
            :class="STATUS_STYLE[status]"
          />
          <span
            class="text-lg font-semibold leading-tight"
            data-testid="status"
          >{{ STATUS_LABEL[status] }}</span>
        </p>
        <p
          v-if="summary?.currentApp && status === 'active'"
          class="truncate text-xs"
          :title="summary.currentTitle ?? summary.currentApp"
        >
          <span class="font-medium">{{ summary.currentApp }}</span>
          <span
            v-if="summary.currentTitle"
            class="text-gray-500 dark:text-gray-400"
          > · {{ summary.currentTitle }}</span>
        </p>
        <p
          v-else-if="status === 'idle'"
          class="text-xs text-gray-500 dark:text-gray-400"
        >
          No keyboard or mouse use.
        </p>

        <div class="flex gap-2">
          <UiButton
            v-if="state?.state === 'not_tracking'"
            variant="primary"
            block
            @click="start"
          >
            Start
          </UiButton>
          <UiButton
            v-if="state?.state === 'tracking'"
            class="flex-1"
            @click="pause"
          >
            Pause
          </UiButton>
          <UiButton
            v-if="state?.state === 'paused'"
            variant="primary"
            class="flex-1"
            @click="resume"
          >
            Resume
          </UiButton>
          <UiButton
            v-if="state?.state === 'tracking' || state?.state === 'paused'"
            variant="danger"
            class="flex-1"
            @click="stop"
          >
            Stop
          </UiButton>
        </div>
      </div>
    </UiCard>

    <dl class="grid grid-cols-3 gap-2">
      <UiStatTile
        label="Tracked"
        :value="clock?.tracked ?? '--:--:--'"
      />
      <UiStatTile
        label="Active"
        :value="clock?.active ?? '--:--:--'"
      />
      <UiStatTile
        label="Idle"
        :value="clock?.idle ?? '--:--:--'"
      />
    </dl>

    <UiCard title="Apps today">
      <template
        v-if="moreApps > 0"
        #action
      >
        <UiButton
          variant="link"
          class="text-xs"
          @click="showApps = true"
        >
          +{{ moreApps }} more
        </UiButton>
      </template>
      <p
        v-if="!summary?.apps.length"
        class="text-sm text-gray-500 dark:text-gray-400"
      >
        No apps recorded yet.
      </p>
      <ul
        v-else
        class="space-y-1.5 text-sm"
      >
        <li
          v-for="app in topApps"
          :key="app.name"
        >
          <div class="flex justify-between gap-2">
            <span class="truncate">{{ app.name }}</span>
            <span class="shrink-0 tabular-nums text-gray-500 dark:text-gray-400">{{ formatDuration(app.seconds) }}</span>
          </div>
          <div class="mt-0.5 h-1 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
            <div
              class="h-full rounded-full bg-primary-500"
              :style="{ width: `${appShare(app.seconds)}%` }"
            />
          </div>
        </li>
      </ul>
    </UiCard>

    <UiCard title="Timeline">
      <TimelineBar :segments="timeline" />
    </UiCard>

    <footer class="mt-auto">
      <p
        v-if="footerLabel"
        class="text-center text-xs text-gray-500 dark:text-gray-400"
      >
        {{ footerLabel }}
      </p>
      <div
        v-if="progress && sync?.online"
        class="mt-1"
      >
        <div
          class="h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10"
          role="progressbar"
          :aria-valuenow="progress.percent"
          aria-valuemin="0"
          aria-valuemax="100"
        >
          <div
            class="h-full rounded-full bg-primary-500 transition-all duration-500"
            :style="{ width: `${progress.percent}%` }"
          />
        </div>
        <p class="mt-1 text-center text-xs text-gray-500 dark:text-gray-400">
          Sending {{ progress.sent.toLocaleString() }} of {{ progress.total.toLocaleString() }} ({{ progress.percent }}%)
        </p>
      </div>
      <p
        v-if="isDev && state?.state === 'not_tracking'"
        class="text-center"
      >
        <UiButton
          variant="link"
          class="text-xs"
          title="Sets these counters back to 00:00:00. Nothing is deleted."
          @click="resetCounters"
        >
          Reset counters
        </UiButton>
      </p>
    </footer>

    <AppsModal
      :open="showApps"
      :apps="summary?.apps ?? []"
      @close="showApps = false"
    />
  </main>
</template>

<script setup lang="ts">
const { state, summary, timeline, status, clock, activeShare, workClock, resumedNotice, error, start, pause, resume, stop, resetCounters } = useTracking()
const { me, refresh } = useAuth()
const { status: sync, notice, progress, dismissNotice } = useSync()
const { status: shots } = useScreenshots()

// The counter Reset is a testing aid: only dev builds show it.
const isDev = import.meta.dev

const STATUS_STYLE: Record<StatusKey, string> = {
  active: 'bg-green-500',
  idle: 'bg-primary-500',
  paused: 'bg-blue-500',
  away: 'bg-gray-400',
  not_tracking: 'bg-gray-400',
}

// The Today screen never scrolls: only the three busiest apps are listed, the rest open in a popup.
const TOP_APPS = 3
const showApps = ref(false)
const topApps = computed(() => (summary.value?.apps ?? []).slice(0, TOP_APPS))
const moreApps = computed(() => Math.max(0, (summary.value?.apps.length ?? 0) - TOP_APPS))
// each bar is the app's time against the busiest app's
const appShare = (seconds: number) => Math.round((seconds / Math.max(1, topApps.value[0]?.seconds ?? 1)) * 100)

// What the ring means, shown when it is hovered or focused.
const ringHelp = computed(() => activeShare.value === null
  ? 'The ring fills with the share of your tracked time that was active use. Nothing is tracked yet today.'
  : `${activeShare.value}% active: of the ${clock.value?.tracked} you have tracked today, ${clock.value?.active} was active use and ${clock.value?.idle} was idle (no keyboard or mouse). The clock in the middle is the time since you pressed Start. The ring is not progress toward a full working day.`)

const syncLabel = computed(() => {
  const s = sync.value
  if (!s)
    return null
  if (s.needsLogin)
    return 'Please log in again'
  if (s.upgradeRequired)
    return 'Please update the app'
  if (!s.online)
    return s.pendingCount > 0 ? `Offline · ${s.pendingCount.toLocaleString()} waiting to send` : 'Offline'
  return s.pendingCount > 0 ? `${s.pendingCount.toLocaleString()} ${s.pendingCount === 1 ? 'session' : 'sessions'} waiting to send` : 'All data sent'
})

// "Last screenshot 14:05" while screenshots are on (or have been taken)
const shotLabel = computed(() => {
  const s = shots.value
  if (!s || (!s.enabled && !s.lastTakenAt))
    return null
  const tz = me.value?.settings.timezone ?? 'UTC'
  const last = s.lastTakenAt ? `Last screenshot ${clockTime(s.lastTakenAt, tz)}` : 'No screenshot yet today'
  return s.waiting > 0 ? `${last} · ${s.waiting} waiting to send` : last
})

// One line at the bottom: the sync state and the last screenshot.
const footerLabel = computed(() => [syncLabel.value, shotLabel.value].filter(Boolean).join(' · ') || null)

// Best effort: picks up a raised consent version. The route guard then sends the person
// to the consent screen if needed.
onMounted(async () => {
  await refresh().catch(() => {})
  if (me.value?.consentRequired)
    await navigateTo('/consent')
})
</script>
